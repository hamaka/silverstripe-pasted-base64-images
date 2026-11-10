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
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\ORM\FieldType\DBHTMLVarchar;
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
 * - Deduplicated on FileHash (sha1) within the configured folder: pasting the same image again, or converting old
 *   versions, reuses one file.
 * - On any error (invalid or truncated data, an image over a limit, PCRE error, write failure) the original tag is
 *   left untouched, so no content is lost. The error is logged and (optionally) mailed.
 * - Data URIs this class does not convert (svg, a CSS background, ...) are left untouched and counted as skipped.
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
     * Permission code(s) of which the current user needs at least one for the conversion on write. 'CMS_ACCESS'
     * matches access to any CMS section. false: no check, so HTML written by anonymous visitors (a frontend form)
     * is converted too. Command line writes and the task are never checked.
     */
    private static string|array|bool $write_permission = 'CMS_ACCESS';

    /** Largest image that is converted, in bytes of the decoded file. 0: no limit */
    private static int $max_bytes = 20971520;

    /** Largest image that is converted, in pixels (width x height). 0: no limit */
    private static int $max_pixels = 50000000;

    /** At most this many images are converted per HTML value (one field of one record). 0: no limit */
    private static int $max_images = 100;

    /**
     * Classes that are never converted, on write or by the task. Includes their subclasses.
     *
     * @var string[]
     */
    private static array $exclude_classes = [];

    /**
     * Fields that are never converted, on write or by the task, as 'Class.Field'. Includes subclasses of the class.
     *
     * @var string[]
     */
    private static array $exclude_fields = [];

    /**
     * Who to mail when an image could not be converted.
     * null: Email.admin_email (empty by default, so no mail). false or '': no mail. Errors are always logged.
     */
    private static string|bool|null $notify_email = null;

    /** Send at most one notification per this many minutes; errors in between are only logged. 0: no throttling */
    private static int $notify_throttle_minutes = 60;

    /** Supported types: IMAGETYPE_* => extension */
    private const ALLOWED_TYPES = [
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_GIF  => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];

    /**
     * A whole <img> tag. Quoted attribute values are matched as a unit, so a '>' inside a value does not end the
     * tag. Possessive quantifiers: no backtracking, even with base64 strings of several MB. Anchored (A): it is
     * tried at the position of each "<img", see convert().
     */
    private const TAG_PATTERN = '~<img\b(?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>~Ai';

    /** After this many "<img" tags without an end, the rest of the HTML value is left as it is */
    private const MAX_UNCLOSED_TAGS = 10;

    /**
     * Only these data URIs are converted, others (e.g. svg+xml) are skipped. Parameters such as ;charset=utf-8 are
     * allowed before ;base64. The declared type is not trusted: the real type is read from the decoded bytes.
     */
    private const DATA_URI_PATTERN = '~^\s*data:image/(?:png|x-png|jpe?g|pjpeg|gif|webp)(?:\s*;\s*[\w.-]++\s*=[^;,]*+)*+\s*;\s*base64\s*,(.*)$~is';

    /** [class|own => names of the HTML fields that process shortcodes] */
    protected static array $shortcodeFieldCache = [];

    protected bool $dryRun = false;

    protected bool $notify = true;

    protected array $stats = [
        'converted' => 0, // <img> tags converted (dry run: convertible)
        'created'   => 0, // new Image files
        'reused'    => 0, // existing file reused (same FileHash)
        'failed'    => 0, // tags left untouched because of an error
        'skipped'   => 0, // data URIs left untouched because this class does not convert them
        'bytes'     => 0, // bytes of data URIs removed from the HTML
    ];

    protected array $errors = [];

    protected array $skipped = [];

    /** sha1 => Image, saves queries when the same image appears in many versions */
    protected array $imageCache = [];

    /** Images accepted in the HTML value that is being converted, for max_images */
    protected int $imagesInValue = 0;

    /**
     * Validate and count only, don't write anything
     */
    public function setDryRun(bool $dryRun): static
    {
        $this->dryRun = $dryRun;

        return $this;
    }

    /**
     * false: convert() doesn't mail its errors (e.g. a task that sends one summary at the end). Errors are always
     * logged.
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
     * Data URIs that were left alone because they are not something this class converts.
     *
     * @return array[] each with context, reason and count
     */
    public function getSkipped(): array
    {
        return $this->skipped;
    }

    /**
     * Cheap check before running the regex
     */
    public static function containsDataImage(?string $html): bool
    {
        return $html !== null && $html !== '' && stripos($html, 'data:image/') !== false;
    }

    /**
     * The fields of a class that are converted: HTML fields that process shortcodes, minus the excluded ones.
     *
     * An [image] shortcode is only rendered by a field that processes shortcodes. That is HTMLText by default;
     * HTMLFragment, HTMLVarchar and HTMLText(['shortcodes' => false]) would show the shortcode as literal text.
     *
     * @param bool $ownTableOnly true: only the fields in the class's own table, not those of its parent classes
     * @return string[]
     */
    public static function convertibleFields(string $class, bool $ownTableOnly = false): array
    {
        $key = $class . ($ownTableOnly ? '|own' : '');

        if (!isset(static::$shortcodeFieldCache[$key])) {
            $fields = [];
            foreach (DataObject::getSchema()->databaseFields($class, !$ownTableOnly) as $field => $spec) {
                if (static::processesShortcodes((string) $spec, (string) $field)) {
                    $fields[] = $field;
                }
            }
            static::$shortcodeFieldCache[$key] = $fields;
        }

        // Not cached: config can differ per request (and per test)
        return array_values(array_filter(
            static::$shortcodeFieldCache[$key],
            fn(string $field) => !static::isExcluded($class, $field)
        ));
    }

    /**
     * Whether a field of a class is excluded through exclude_classes or exclude_fields
     */
    public static function isExcluded(string $class, string $field): bool
    {
        foreach ((array) static::config()->get('exclude_classes') as $excluded) {
            if (is_a($class, (string) $excluded, true)) {
                return true;
            }
        }

        foreach ((array) static::config()->get('exclude_fields') as $excluded) {
            $parts = explode('.', (string) $excluded, 2);
            if (count($parts) === 2 && $parts[1] === $field && is_a($class, $parts[0], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $spec Field spec as in $db, e.g. "HTMLText" or "HTMLVarchar(255)"
     */
    protected static function processesShortcodes(string $spec, string $field): bool
    {
        try {
            // The same way DataObject::dbObject() builds the field, so aliases, arguments and subclasses all work
            $object = Injector::inst()->create($spec, $field);
        } catch (Throwable $e) {
            return false;
        }

        return ($object instanceof DBHTMLText || $object instanceof DBHTMLVarchar) && $object->getProcessShortcodes();
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

        $errorsBefore = count($this->errors);
        $this->imagesInValue = 0;

        $inTags = 0;   // data URIs inside the tags that were looked at
        $unclosed = 0; // "<img" without an end
        $offset = 0;
        $copied = 0;
        $parts = [];
        $pcreError = false;

        // Tag by tag from each "<img", not one pattern over the whole value: a tag that never closes makes the
        // pattern scan to the end of the value, and with thousands of those that adds up to minutes. So stop after
        // a few; whatever comes after them is left as it is.
        while ($unclosed < self::MAX_UNCLOSED_TAGS && ($pos = stripos($html, '<img', $offset)) !== false) {
            $matched = preg_match(self::TAG_PATTERN, $html, $match, 0, $pos);

            if ($matched === false) {
                $pcreError = true;
                break;
            }

            if (!$matched) {
                // Either another element ("<imgx"), which fails at once, or a tag without an end
                $next = $html[$pos + 4] ?? '';
                if (!ctype_alnum($next) && $next !== '_') {
                    $unclosed++;
                }
                $offset = $pos + 4;

                continue;
            }

            $tag = $match[0];
            $offset = $pos + strlen($tag);

            if (stripos($tag, 'data:image/') === false) {
                continue;
            }

            $inTags += $this->countDataImages($tag);
            $newTag = $this->convertTag($tag, $context);

            if ($newTag !== $tag) {
                $parts[] = substr($html, $copied, $pos - $copied);
                $parts[] = $newTag;
                $copied = $offset;
            }
        }

        if ($pcreError) {
            $this->addError($context, 'PCRE error: ' . preg_last_error_msg(), $html);
            $result = $html;
        } else {
            $result = $html;
            if ($parts) {
                $parts[] = substr($html, $copied);
                $result = implode('', $parts);
            }

            // What was never looked at: a CSS background, srcset, <source>, or a tag with unbalanced quotes
            $outside = $this->countDataImages($html) - $inTags;
            if ($outside > 0) {
                $this->addSkipped($context, 'Data URI outside the src of a well-formed <img> tag', $outside);
            }
        }

        // One mail per HTML value, not one per image
        if ($this->notify && !$this->dryRun) {
            static::notify(array_slice($this->errors, $errorsBefore));
        }

        return $result;
    }

    protected function countDataImages(string $html): int
    {
        return (int) preg_match_all('~data:image/~i', $html);
    }

    protected function convertTag(string $tag, string $context): string
    {
        $attributes = $this->parseAttributes($tag);
        if ($attributes === null) {
            $this->addError($context, 'PCRE error while parsing attributes: ' . preg_last_error_msg(), $tag);

            return $tag;
        }

        $src = $attributes['src'] ?? '';
        if (!preg_match('~^\s*data:image/~i', $src)) {
            // "data:image/" somewhere else in the tag, e.g. in the alt text
            $this->addSkipped($context, 'data:image/ appears in an <img> tag, but not as its src');

            return $tag;
        }

        if (!preg_match(self::DATA_URI_PATTERN, $src, $srcMatch)) {
            // e.g. svg, avif, bmp, or not base64 encoded
            $declared = substr($src, 0, min(60, strcspn($src, ',')));
            $this->addSkipped($context, 'Unsupported data URI (' . trim($declared) . ')');

            return $tag;
        }

        $maxImages = (int) $this->setting('max_images');
        if ($maxImages > 0 && $this->imagesInValue >= $maxImages) {
            if ($this->imagesInValue === $maxImages) {
                // Reported once per HTML value; the images after it are counted as skipped
                $this->imagesInValue++;
                $this->addError($context, sprintf('More than %d images in one HTML value (max_images), the rest is left as it is', $maxImages), $tag);
            } else {
                $this->addSkipped($context, sprintf('Over the limit of %d images in one HTML value (max_images)', $maxImages));
            }

            return $tag;
        }

        try {
            $image = $this->decodeAndValidate($srcMatch[1]);
        } catch (InvalidArgumentException $e) {
            $this->addError($context, $e->getMessage(), $tag);

            return $tag;
        }

        $this->imagesInValue++;

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
     * @throws InvalidArgumentException on invalid or truncated data, or an image over a limit
     */
    protected function decodeAndValidate(string $base64): array
    {
        $maxBytes = (int) $this->setting('max_bytes');

        // Rough check before decoding, so an absurdly large payload is not decoded at all (base64 is 4/3 of the
        // file, whitespace can add a little)
        if ($maxBytes > 0 && strlen($base64) > $maxBytes * 2) {
            throw new InvalidArgumentException(sprintf('Image is larger than max_bytes (%s)', $this->formatBytes($maxBytes)));
        }

        // Strict: characters outside the base64 alphabet (whitespace excepted) make it fail
        $data = base64_decode($base64, true);
        if ($data === false || $data === '') {
            throw new InvalidArgumentException('Invalid base64 data');
        }

        if ($maxBytes > 0 && strlen($data) > $maxBytes) {
            throw new InvalidArgumentException(sprintf(
                'Image is larger than max_bytes (%s, the limit is %s)',
                $this->formatBytes(strlen($data)),
                $this->formatBytes($maxBytes)
            ));
        }

        $size = @getimagesizefromstring($data);
        if (!$size || empty($size[0]) || empty($size[1])) {
            throw new InvalidArgumentException('Data is not a valid image');
        }

        $type = $size[2];
        if (!isset(self::ALLOWED_TYPES[$type])) {
            throw new InvalidArgumentException('Image type not allowed: ' . ($size['mime'] ?? $type));
        }

        // The header is all that was read so far: a small file can declare huge dimensions, which would exhaust
        // memory when the image is resized later
        $maxPixels = (int) $this->setting('max_pixels');
        if ($maxPixels > 0 && $size[0] * $size[1] > $maxPixels) {
            throw new InvalidArgumentException(sprintf('Image has more pixels than max_pixels (%d x %d)', $size[0], $size[1]));
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
                return $this->isCompletePng($data);

            case IMAGETYPE_JPEG:
                return $this->isCompleteJpeg($data);

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

    /**
     * Walks the chunks up to IEND. Data after IEND is allowed, browsers ignore it.
     */
    protected function isCompletePng(string $data): bool
    {
        $length = strlen($data);
        $pos = 8; // signature

        // Each chunk: length (4), type (4), data, CRC (4)
        while ($pos + 12 <= $length) {
            $chunkLength = unpack('N', substr($data, $pos, 4))[1];
            $end = $pos + 12 + $chunkLength;
            if ($end > $length) {
                return false;
            }
            if (substr($data, $pos + 4, 4) === 'IEND') {
                return true;
            }
            $pos = $end;
        }

        return false;
    }

    /**
     * Walks the segments up to the EOI marker of the main image. Data after it is allowed: phones append a second
     * image or a video there (MPF, Motion Photo), which can be megabytes.
     *
     * An EOI somewhere in the file is not enough, an embedded EXIF thumbnail has one of its own.
     */
    protected function isCompleteJpeg(string $data): bool
    {
        $length = strlen($data);
        $pos = 2; // SOI

        while ($pos + 1 < $length) {
            if ($data[$pos] !== "\xFF") {
                // Not a structure this walk understands: fall back to "an EOI close to the end"
                $eoi = strrpos($data, "\xFF\xD9");

                return $eoi !== false && $eoi >= $length - 2048;
            }

            $marker = ord($data[$pos + 1]);

            if ($marker === 0xFF) {
                $pos++; // fill byte

                continue;
            }
            if ($marker === 0xD9) {
                return true; // EOI
            }
            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD8)) {
                $pos += 2; // markers without a length

                continue;
            }

            if ($pos + 4 > $length) {
                return false;
            }
            $pos += 2 + unpack('n', substr($data, $pos + 2, 2))[1];
            if ($pos > $length) {
                return false;
            }

            if ($marker !== 0xDA) {
                continue;
            }

            // SOS: compressed data follows, up to the next marker that is not a stuffed FF00 or a restart marker
            while (true) {
                $pos = strpos($data, "\xFF", $pos);
                if ($pos === false || $pos + 1 >= $length) {
                    return false;
                }
                $next = ord($data[$pos + 1]);
                if ($next === 0x00 || ($next >= 0xD0 && $next <= 0xD7)) {
                    $pos += 2;
                } elseif ($next === 0xFF) {
                    $pos++;
                } else {
                    break;
                }
            }
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

            $folder = Folder::find_or_make($this->setting('folder'));

            // Only within this module's own folder: the same image elsewhere in Files belongs to an editor, who may
            // have left it unpublished or restricted on purpose
            /** @var Image|null $existing */
            $existing = Image::get()
                ->filter(['FileHash' => $hash, 'ParentID' => $folder->ID])
                ->sort('ID', 'ASC')
                ->first();
            if ($existing && $existing->exists()) {
                if (!$existing->isPublished()) {
                    $existing->publishSingle();
                }
                $this->stats['reused']++;

                return $existing;
            }

            $file = Image::create();
            $file->setFromString(
                $image['data'],
                $folder->getFilename() . $this->setting('filename_prefix') . substr($hash, 0, 12) . '.' . $image['ext']
            );
            $file->ParentID = $folder->ID;
            $file->Title = $this->setting('image_title');

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
            'class'  => $class !== '' ? $class : $this->setting('default_class'),
            'alt'    => $attributes['alt'] ?? '',
        ];
        if (isset($attributes['title'])) {
            $shortcodeAttributes['title'] = $attributes['title'];
        }

        $parts = [];
        foreach ($shortcodeAttributes as $name => $value) {
            // Values are already entity encoded in the HTML, except for what is allowed bare in an attribute value:
            // a " (in a single quoted attribute) and < and >. Outside a tag those would become markup.
            $parts[] = $name . '="' . str_replace(['"', '<', '>'], ['&quot;', '&lt;', '&gt;'], (string) $value) . '"';
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

    protected function formatBytes(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : round($bytes / 1024) . ' KB';
    }

    /**
     * A config value. In one place, so a subclass can supply its own.
     */
    protected function setting(string $name): mixed
    {
        return static::config()->get($name);
    }

    protected function addSkipped(string $context, string $reason, int $count = 1): void
    {
        $this->stats['skipped'] += $count;
        $this->skipped[] = [
            'context' => $context,
            'reason'  => $reason,
            'count'   => $count,
        ];
    }

    protected function addError(string $context, string $message, string $html): void
    {
        $this->stats['failed']++;

        // No megabytes of base64 in the error
        $snippet = strlen($html) > 300 ? substr($html, 0, 300) . '... (' . strlen($html) . ' characters)' : $html;

        $this->errors[] = [
            'context' => $context,
            'message' => $message,
            'snippet' => $snippet,
        ];

        if (!$this->dryRun) {
            static::logger()->warning(sprintf('Pasted image not converted (%s): %s', $context ?: 'unknown', $message));
        }
    }

    /**
     * Mails a summary of conversion errors to notify_email (or Email.admin_email).
     * Never throws: a notification must not break a save.
     *
     * @param bool $throttle true: at most one mail per notify_throttle_minutes, whatever the errors are. The key
     *                       does not depend on the errors, so varying the content does not get around it.
     */
    public static function notify(array $errors, string $intro = '', bool $throttle = true): void
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

            $minutes = $throttle ? (int) static::config()->get('notify_throttle_minutes') : 0;
            $cache = $minutes > 0 ? static::cache() : null;
            if ($cache && $cache->has('notify')) {
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

            if ($minutes > 0) {
                $body .= "\nThis notification is sent at most once per $minutes minutes. "
                    . "Errors in between are only written to the log.\n";
            }

            Email::create()
                ->setTo($to)
                ->setSubject('Pasted image not converted on ' . static::siteLabel())
                // text(), not setBody(): sendPlain() strips tags from an HTML body, and the snippets are HTML
                ->text($body)
                ->sendPlain();

            if ($cache) {
                $cache->set('notify', true, $minutes * 60);
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
