<?php

namespace Hamaka\Base64Images\Tests;

use Hamaka\Base64Images\Base64ImageConverter;
use Hamaka\Base64Images\Tests\Base64ImageConverterTest\TestObject;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\Image;
use SilverStripe\Dev\SapphireTest;

class Base64ImageConverterTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        TestObject::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        TestAssetStore::activate('Base64ImageConverterTest');
        Base64ImageConverter::config()->set('notify_email', false);
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    protected function pngBase64(int $width = 40, int $height = 20, array $rgb = [200, 50, 50]): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, ...$rgb));
        ob_start();
        imagepng($image);

        return base64_encode(ob_get_clean());
    }

    protected function imgTag(string $base64, string $attributes = ''): string
    {
        return '<img ' . $attributes . ' src="data:image/png;base64,' . $base64 . '">';
    }

    public function testConvertsToShortcodeAndPublishedFile(): void
    {
        $base64 = $this->pngBase64();
        $converter = Base64ImageConverter::create();

        $html = $converter->convert('<p>Text ' . $this->imgTag($base64, 'alt="Red &amp; flat" class="left"') . '</p>');

        $this->assertStringNotContainsString('data:image/', $html);
        $this->assertMatchesRegularExpression('~^<p>Text \[image src="[^"]+" id="\d+" width="40" height="20" class="left" alt="Red &amp; flat"\]</p>$~', $html);

        $image = Image::get()->filter('FileHash', sha1(base64_decode($base64)))->first();
        $this->assertNotNull($image);
        $this->assertTrue($image->isPublished());
        $this->assertStringStartsWith('Uploads/pasted-images/pasted-', $image->getFilename());
        $this->assertStringContainsString('id="' . $image->ID . '"', $html);
        $this->assertSame(1, $converter->getStats()['created']);
    }

    public function testSameImageIsReused(): void
    {
        $base64 = $this->pngBase64();

        Base64ImageConverter::create()->convert($this->imgTag($base64));
        // A new instance, so the reuse comes from the FileHash lookup and not the in-memory cache
        $converter = Base64ImageConverter::create();
        $converter->convert($this->imgTag($base64) . $this->imgTag($base64));

        $this->assertSame(1, Image::get()->filter('FileHash', sha1(base64_decode($base64)))->count());
        $this->assertSame(0, $converter->getStats()['created']);
        $this->assertSame(2, $converter->getStats()['reused']);
    }

    public function testMissingDimensionIsProportional(): void
    {
        $html = Base64ImageConverter::create()->convert($this->imgTag($this->pngBase64(40, 20), 'width="100"'));

        $this->assertStringContainsString('width="100" height="50"', $html);
        $this->assertStringContainsString('class="leftAlone ss-htmleditorfield-file image"', $html);
    }

    public function testSingleQuotedAttributeWithDoubleQuote(): void
    {
        $html = Base64ImageConverter::create()->convert($this->imgTag($this->pngBase64(), "alt='Say \"hi\"'"));

        $this->assertStringContainsString('alt="Say &quot;hi&quot;"', $html);
    }

    public static function provideInvalidData(): array
    {
        $image = imagecreatetruecolor(40, 20);
        ob_start();
        imagepng($image);
        $base64 = base64_encode(ob_get_clean());

        return [
            'invalid base64'   => ['@@@not-base64!!!', 'Invalid base64 data'],
            'not an image'     => [base64_encode('hello world'), 'Data is not a valid image'],
            // Cut on a 4 character boundary, so base64_decode() still succeeds
            'truncated'        => [substr($base64, 0, intdiv((int) (strlen($base64) * 0.6), 4) * 4), 'Image is incomplete (truncated data)'],
        ];
    }

    /**
     * @dataProvider provideInvalidData
     */
    public function testInvalidDataIsLeftUntouched(string $base64, string $expectedError): void
    {
        $input = '<p>' . $this->imgTag($base64) . '</p>';
        $converter = Base64ImageConverter::create();

        $this->assertSame($input, $converter->convert($input));
        $this->assertSame(1, $converter->getStats()['failed']);
        $this->assertSame($expectedError, $converter->getErrors()[0]['message']);
        $this->assertSame(0, Image::get()->count());
    }

    public function testUnsupportedDataUriIsSkipped(): void
    {
        $input = '<img src="data:image/svg+xml;base64,' . base64_encode('<svg/>') . '"><img src="/a.png" alt="data:image/png">'
            . '<div style="background:url(data:image/png;base64,' . $this->pngBase64() . ')"></div>';
        $converter = Base64ImageConverter::create();

        $this->assertSame($input, $converter->convert($input));
        $this->assertSame(0, $converter->getStats()['failed']);
        $this->assertSame(3, $converter->getStats()['skipped']);
        $this->assertSame('Unsupported data URI (data:image/svg+xml;base64)', $converter->getSkipped()[0]['reason']);
        $this->assertSame(0, Image::get()->count());
    }

    public function testDataUriWithParameterIsConverted(): void
    {
        $html = Base64ImageConverter::create()->convert('<img src="data:image/png;charset=utf-8;base64,' . $this->pngBase64() . '">');

        $this->assertStringStartsWith('[image ', $html);
    }

    public function testAngleBracketInAttributeDoesNotBecomeMarkup(): void
    {
        $converter = Base64ImageConverter::create();
        $html = $converter->convert('<p>' . $this->imgTag($this->pngBase64(), 'alt="><img src=x onerror=alert(1)>"') . '</p>');

        $this->assertSame(1, $converter->getStats()['converted']);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('alt="&gt;&lt;img src=x onerror=alert(1)&gt;"', $html);
        $this->assertStringEndsWith(']</p>', $html);
    }

    public function testLimits(): void
    {
        $tag = $this->imgTag($this->pngBase64());

        Base64ImageConverter::config()->set('max_pixels', 100);
        $converter = Base64ImageConverter::create();
        $this->assertSame($tag, $converter->convert($tag));
        $this->assertStringContainsString('max_pixels', $converter->getErrors()[0]['message']);

        Base64ImageConverter::config()->set('max_pixels', 0)->set('max_bytes', 50);
        $converter = Base64ImageConverter::create();
        $this->assertSame($tag, $converter->convert($tag));
        $this->assertStringContainsString('max_bytes', $converter->getErrors()[0]['message']);

        Base64ImageConverter::config()->set('max_bytes', 0)->set('max_images', 1);
        $converter = Base64ImageConverter::create();
        $html = $converter->convert($tag . $tag . $tag);
        $this->assertStringStartsWith('[image ', $html);
        $this->assertStringEndsWith($tag . $tag, $html);
        $this->assertSame(1, $converter->getStats()['converted']);
        $this->assertSame(1, $converter->getStats()['failed']);
        $this->assertSame(1, $converter->getStats()['skipped']);
    }

    public function testSameImageElsewhereInFilesIsNotReused(): void
    {
        $base64 = $this->pngBase64();

        // An editor's own upload of the same image, deliberately left in draft
        $other = Image::create();
        $other->setFromString(base64_decode($base64), 'Uploads/editors-own.png');
        $other->write();

        $converter = Base64ImageConverter::create();
        $converter->convert($this->imgTag($base64));

        $this->assertSame(1, $converter->getStats()['created']);
        $this->assertSame(2, Image::get()->filter('FileHash', sha1(base64_decode($base64)))->count());
        $this->assertFalse(Image::get()->byID($other->ID)->isPublished());
    }

    public function testDryRunWritesNothing(): void
    {
        $input = $this->imgTag($this->pngBase64());
        $converter = Base64ImageConverter::create()->setDryRun(true);

        $this->assertSame($input, $converter->convert($input));
        $this->assertSame(1, $converter->getStats()['converted']);
        $this->assertSame(0, Image::get()->count());
    }

    public function testExtensionConvertsOnWrite(): void
    {
        $object = TestObject::create(['Content' => '<p>' . $this->imgTag($this->pngBase64()) . '</p>', 'Title' => 'x']);
        $object->write();

        $this->assertStringStartsWith('<p>[image ', $object->Content);
        $this->assertSame(1, Image::get()->count());
    }

    public function testOnlyFieldsThatProcessShortcodesAreConverted(): void
    {
        $this->assertSame(['Content'], Base64ImageConverter::convertibleFields(TestObject::class));

        $tag = $this->imgTag($this->pngBase64());
        $object = TestObject::create(['Fragment' => $tag, 'Short' => '<img src="data:image/png;base64,AAAA">']);
        $object->write();

        // Not compared as a whole: core's link tracking normalises the HTML on write
        $this->assertStringContainsString('src="data:image/png;base64,', $object->Fragment);
        $this->assertStringNotContainsString('[image', $object->Fragment);
        $this->assertSame(0, Image::get()->count());
    }

    public function testExcludedClassesAndFields(): void
    {
        Base64ImageConverter::config()->set('exclude_fields', [TestObject::class . '.Content']);
        $this->assertSame([], Base64ImageConverter::convertibleFields(TestObject::class));

        Base64ImageConverter::config()->set('exclude_fields', [])->set('exclude_classes', [TestObject::class]);
        $this->assertSame([], Base64ImageConverter::convertibleFields(TestObject::class));

        $tag = $this->imgTag($this->pngBase64());
        $object = TestObject::create(['Content' => $tag]);
        $object->write();

        $this->assertStringContainsString('src="data:image/png;base64,', $object->Content);
        $this->assertStringNotContainsString('[image', $object->Content);
        $this->assertSame(0, Image::get()->count());
    }
}
