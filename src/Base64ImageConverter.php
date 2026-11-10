<?php

namespace Hamaka\Base64Images;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Image;
use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Versioned\Versioned;
use Throwable;

/**
 * Converts base64 images in HTML (<img src="data:image/png;base64,...">) into an Image in the asset admin and a
 * regular [image] shortcode.
 *
 * TinyMCE 6 (Silverstripe 5 and up) has paste_data_images enabled by default: an image that is pasted or dropped
 * into the editor ends up as a base64 data URI in the HTML field. Every save/publish then copies the full image into
 * the *_Versions table again, and the image is not managed in Files (no reuse, resizing or caching).
 *
 * - Deduplicated on FileHash (sha1): pasting the same image again, or converting old versions, reuses one file.
 * - On any error (invalid or truncated data, PCRE error, write failure) the original tag is left untouched, so no
 *   content is lost. The error is logged and (optionally) mailed.
 *
 * Usage:
 *   $converter = Base64ImageConverter::create();
 *   $html = $converter->convert($html);
 */
class Base64ImageConverter
{
    use Injectable;
    use Configurable;

    /** Folder in Files that pasted images are saved to */
    private static string $folder = 'Uploads/pasted-images';

    /** Filename prefix, followed by the first 12 characters of the sha1 */
    private static string $filename_prefix = 'pasted-';

    /** Title of a newly created Image */
    private static string $image_title = 'Pasted image';

    /** Used when the <img> has no class (the same classes the CMS editor uses when inserting an image) */
    private static string $default_class = 'leftAlone ss-htmleditorfield-file image';

    /**
     * Who to mail when an image could not be converted.
     * null: Email.admin_email. false or '': no mail (errors are still logged).
     */
    private static string|bool|null $notify_email = null;

    /** Send the same notification at most once per this many minutes */
    private static int $notify_throttle_minutes = 60;

    /** Supported types: IMAGETYPE_* => extension */
    private const ALLOWED_TYPES = [
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_GIF  => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];

    /** Only these data URIs are converted, others (e.g. svg+xml) are left alone */
    private const DATA_URI_PATTERN = '~^\s*data:image/(?:png|jpe?g|pjpeg|gif|webp)\s*;\s*base64\s*,(.*)$~is';

    protected bool $dryRun = false;

    protected bool $notify = true;

    protected array $stats = [
        'converted' => 0, // <img> tags converted (dry run: convertible)
        'created'   => 0, // new Image files
        'reused'    => 0, // existing file reused (same FileHash)
        'failed'    => 0, // tags left untouched because of an error
        'bytes'     => 0, // bytes of data URIs removed from the HTML
    ];

    protected array $errors = [];

    /** sha1 => Image, saves queries when the same image appears in many versions */
    protected array $imageCache = [];

    /**
     * Validate and count only, don't write anything
     */
    public function setDryRun(bool $dryRun): static
    {
        $this->dryRun = $dryRun;

        return $this;
    }

    /**
     * false: don't mail per error (e.g. a task that sends one summary at the end). Errors are always logged.
     */
    public function setNotify(bool $notify): static
    {
        $this->notify = $notify;

        return $this;
    }

    public function getStats(): array
    {
        return $this->stats;
    }

    /**
     * @return array[] each with context, message and snippet
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Cheap check before running the regex
     */
    public static function containsDataImage(?string $html): bool
    {
        return $html !== null && $html !== '' && stripos($html, 'data:image/') !== false;
    }

    /**
     * Replaces every <img src="data:image/...;base64,..."> with an [image] shortcode.
     * On an error the original HTML (or tag) is returned unchanged.
     *
     * @param string $context Where the HTML comes from, only used in error messages (e.g. "SiteTree #12 Content")
     */
    public function convert(?string $html, string $context = ''): ?string
    {
        if (!static::containsDataImage($html)) {
            return $html;
        }

        // Possessive quantifier: no backtracking, even with base64 strings of several MB.
        // Base64 contains no '>', so [^>]*+ always runs to the end of the tag.
        $result = preg_replace_callback(
            '~<img\b[^>]*+>~i',
            fn(array $match) => $this->convertTag($match[0], $context),
            $html
        );

        if ($result === null) {
            $this->addError($context, 'PCRE error: ' . preg_last_error_msg(), $html);

            return $html;
        }

        return $result;
    }

    protected function convertTag(string $tag, string $context): string
    {
        if (stripos($tag, 'data:image/') === false) {
            return $tag;
        }

        $attributes = $this->parseAttributes($tag);
        if ($attributes === null) {
            $this->addError($context, 'PCRE error while parsing attributes: ' . preg_last_error_msg(), $tag);

            return $tag;
        }

        $src = $attributes['src'] ?? '';
        if (!preg_match(self::DATA_URI_PATTERN, $src, $srcMatch)) {
            // No (supported) base64 image in src, e.g. svg, or "data:image/" somewhere in the alt text
            return $tag;
        }

        try {
            $image = $this->decodeAndValidate($srcMatch[1]);
        } catch (InvalidArgumentException $e) {
            $this->addError($context, $e->getMessage(), $tag);

            return $tag;
        }

        if ($this->dryRun) {
            $this->stats['converted']++;
            $this->stats['bytes'] += strlen($src);

            return $tag;
        }

        try {
            $file = $this->findOrCreateImage($image);
        } catch (Throwable $e) {
            $this->addError($context, 'Saving as Image failed: ' . $e->getMessage(), $tag);

            return $tag;
        }

        $this->stats['converted']++;
        $this->stats['bytes'] += strlen($src);

        return $this->buildShortcode($file, $attributes, $image['width'], $image['height']);
    }

    /**
     * Attributes of a tag as [name => value]. Values are kept as they are in the HTML (entities not decoded), so they
     * can be copied into the shortcode unchanged.
     *
     * @return array|null null on a PCRE error
     */
    protected function parseAttributes(string $tag): ?array
    {
        $result = preg_match_all(
            '~([a-zA-Z_:][-a-zA-Z0-9_:.]*+)\s*+=\s*+(?:"([^"]*+)"|\'([^\']*+)\'|([^\s"\'>]++))~',
            $tag,
            $matches,
            PREG_SET_ORDER
        );

        if ($result === false) {
            return null;
        }

        $attributes = [];
        foreach ($matches as $match) {
            $name = strtolower($match[1]);
            if (isset($attributes[$name])) {
                continue; // first one wins, like in the browser
            }
            $attributes[$name] = ($match[2] ?? '') . ($match[3] ?? '') . ($match[4] ?? '');
        }

        return $attributes;
    }

    /**
     * @return array data, hash, type, ext, width, height
     * @throws InvalidArgumentException on invalid or truncated data
     */
    protected function decodeAndValidate(string $base64): array
    {
        // Strict: characters outside the base64 alphabet (whitespace excepted) make it fail
        $data = base64_decode($base64, true);
        if ($data === false || $data === '') {
            throw new InvalidArgumentException('Invalid base64 data');
        }

        $size = @getimagesizefromstring($data);
        if (!$size || empty($size[0]) || empty($size[1])) {
            throw new InvalidArgumentException('Data is not a valid image');
        }

        $type = $size[2];
        if (!isset(self::ALLOWED_TYPES[$type])) {
            throw new InvalidArgumentException('Image type not allowed: ' . ($size['mime'] ?? $type));
        }

        // getimagesizefromstring only reads the header, a truncated image passes it
        if (!$this->isComplete($data, $type)) {
            throw new InvalidArgumentException('Image is incomplete (truncated data)');
        }

        return [
            'data'   => $data,
            'hash'   => sha1($data),
            'type'   => $type,
            'ext'    => self::ALLOWED_TYPES[$type],
            'width'  => (int) $size[0],
            'height' => (int) $size[1],
        ];
    }

    /**
     * Checks that the file has a valid end
     */
    protected function isComplete(string $data, int $type): bool
    {
        $length = strlen($data);

        switch ($type) {
            case IMAGETYPE_PNG:
                // The last chunk is always IEND with a fixed CRC
                return str_contains(substr($data, -64), "IEND\xAE\x42\x60\x82");

            case IMAGETYPE_JPEG:
                // EOI marker; some programs add a few bytes after it
                $pos = strrpos($data, "\xFF\xD9");

                return $pos !== false && $pos >= $length - 2048;

            case IMAGETYPE_GIF:
                // Trailer byte
                $trimmed = rtrim($data, "\0");

                return $trimmed !== '' && substr($trimmed, -1) === "\x3B";

            case IMAGETYPE_WEBP:
                // The RIFF header holds the file size - 8
                if ($length < 12 || !str_starts_with($data, 'RIFF')) {
                    return false;
                }
                $riff = unpack('V', substr($data, 4, 4));

                return isset($riff[1]) && $riff[1] + 8 <= $length;
        }

        return false;
    }

    protected function findOrCreateImage(array $image): Image
    {
        $hash = $image['hash'];
        if (isset($this->imageCache[$hash])) {
            $this->stats['reused']++;

            return $this->imageCache[$hash];
        }

        $file = Versioned::withVersionedMode(function () use ($image, $hash) {
            // Always look up and write in draft, also when called from the frontend (live)
            Versioned::set_stage(Versioned::DRAFT);

            /** @var Image|null $existing */
            $existing = Image::get()->filter('FileHash', $hash)->sort('ID', 'ASC')->first();
            if ($existing && $existing->exists()) {
                if (!$existing->isPublished()) {
                    $existing->publishSingle();
                }
                $this->stats['reused']++;

                return $existing;
            }

            $folder = Folder::find_or_make(static::config()->get('folder'));

            $file = Image::create();
            $file->setFromString(
                $image['data'],
                $folder->getFilename() . static::config()->get('filename_prefix') . substr($hash, 0, 12) . '.' . $image['ext']
            );
            $file->ParentID = $folder->ID;
            $file->Title = static::config()->get('image_title');

            // jonom/focuspoint reads the dimensions on write by loading the whole image into memory if they're unset
            if ($file->hasField('FocusPoint')) {
                $file->FocusPoint->Width = $image['width'];
                $file->FocusPoint->Height = $image['height'];
            }

            $file->write();
            $file->publishSingle();
            $this->stats['created']++;

            return $file;
        });

        $this->imageCache[$hash] = $file;

        return $file;
    }

    /**
     * Same format as the CMS editor: [image src="..." id="..." width="..." height="..." class="..." alt="..."]
     */
    protected function buildShortcode(Image $file, array $attributes, int $naturalWidth, int $naturalHeight): string
    {
        $width = $this->toPixels($attributes['width'] ?? null);
        $height = $this->toPixels($attributes['height'] ?? null);

        // Fill in a missing dimension proportionally, or use the natural size
        if ($width && !$height) {
            $height = (int) round($width * $naturalHeight / $naturalWidth);
        } elseif ($height && !$width) {
            $width = (int) round($height * $naturalWidth / $naturalHeight);
        } elseif (!$width && !$height) {
            $width = $naturalWidth;
            $height = $naturalHeight;
        }

        $class = trim($attributes['class'] ?? '');

        $shortcodeAttributes = [
            'src'    => $file->getURL(),
            'id'     => $file->ID,
            'width'  => $width,
            'height' => $height,
            'class'  => $class !== '' ? $class : static::config()->get('default_class'),
            'alt'    => $attributes['alt'] ?? '',
        ];
        if (isset($attributes['title'])) {
            $shortcodeAttributes['title'] = $attributes['title'];
        }

        $parts = [];
        foreach ($shortcodeAttributes as $name => $value) {
            // Values are already entity encoded in the HTML; only a bare " (from a single quoted attribute) is left
            $parts[] = $name . '="' . str_replace('"', '&quot;', (string) $value) . '"';
        }

        return '[image ' . implode(' ', $parts) . ']';
    }

    protected function toPixels(?string $value): ?int
    {
        if ($value === null || !preg_match('/^\s*(\d+)\s*(px)?\s*$/i', $value, $match)) {
            return null; // empty, or e.g. a percentage
        }

        return (int) $match[1] ?: null;
    }

    protected function addError(string $context, string $message, string $html): void
    {
        $this->stats['failed']++;

        // No megabytes of base64 in the error
        $snippet = strlen($html) > 300 ? substr($html, 0, 300) . '... (' . strlen($html) . ' characters)' : $html;

        $error = [
            'context' => $context,
            'message' => $message,
            'snippet' => $snippet,
        ];
        $this->errors[] = $error;

        if ($this->dryRun) {
            return;
        }

        static::logger()->warning(sprintf('Pasted image not converted (%s): %s', $context ?: 'unknown', $message));

        if ($this->notify) {
            static::notify([$error]);
        }
    }

    /**
     * Mails a summary of conversion errors to notify_email (or Email.admin_email), throttled per unique set of errors.
     * Never throws: a notification must not break a save.
     */
    public static function notify(array $errors, string $intro = ''): void
    {
        if (!$errors) {
            return;
        }

        try {
            $to = static::config()->get('notify_email');
            if ($to === null) {
                $to = Email::config()->get('admin_email');
            }
            if (!$to) {
                return;
            }

            // On the error and the data itself, not the record: a new record has no ID yet on its first write
            $throttleKey = 'notify_' . md5(serialize(array_map(fn($error) => [$error['message'], $error['snippet']], $errors)));
            $cache = static::cache();
            if ($cache && $cache->has($throttleKey)) {
                return;
            }

            $body = $intro !== '' ? $intro . "\n\n" : '';
            $body .= 'One or more base64 images in HTML could not be converted into a file in Files. '
                . 'The original <img> tag has been left in place, no content was lost.' . "\n\n";

            foreach (array_slice($errors, 0, 50) as $error) {
                $body .= '- ' . ($error['context'] ?: 'unknown') . ': ' . $error['message'] . "\n"
                    . '  ' . $error['snippet'] . "\n\n";
            }
            if (count($errors) > 50) {
                $body .= '... and ' . (count($errors) - 50) . " more errors\n";
            }

            $throttle = (int) static::config()->get('notify_throttle_minutes');
            if ($throttle > 0) {
                $body .= "\nThis notification is sent at most once per $throttle minutes.\n";
            }

            Email::create()
                ->setTo($to)
                ->setSubject('Pasted image not converted on ' . static::siteLabel())
                ->setBody($body)
                ->sendPlain();

            if ($cache && $throttle > 0) {
                $cache->set($throttleKey, true, $throttle * 60);
            }
        } catch (Throwable $e) {
            static::logger()->error('Could not send pasted image notification: ' . $e->getMessage());
        }
    }

    protected static function siteLabel(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? null;
        if (!$host && class_exists(\SilverStripe\Control\Director::class)) {
            $host = parse_url(\SilverStripe\Control\Director::absoluteBaseURL(), PHP_URL_HOST);
        }

        return $host ?: 'unknown site';
    }

    protected static function logger(): LoggerInterface
    {
        return Injector::inst()->get(LoggerInterface::class);
    }

    protected static function cache(): ?CacheInterface
    {
        try {
            return Injector::inst()->get(CacheInterface::class . '.Base64Images');
        } catch (Throwable $e) {
            return null;
        }
    }
}
