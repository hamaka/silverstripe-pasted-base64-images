<?php

namespace Hamaka\Base64Images\Extension;

use Hamaka\Base64Images\Base64ImageConverter;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extension;
use SilverStripe\ORM\DataObject;

/**
 * Converts base64 images in every HTML field (HTMLText/HTMLVarchar/HTMLFragment) into an Image in Files and an
 * [image] shortcode on write. See Base64ImageConverter.
 *
 * Applied to DataObject, so it covers pages, elemental blocks and any other object with an HTML field.
 *
 * Disable it per project with:
 *   SilverStripe\ORM\DataObject:
 *     extensions:
 *       base64Images: null
 *
 * @extends Extension<DataObject>
 */
class Base64ImageExtension extends Extension
{
    use Configurable;

    /** Per class, onBeforeWrite runs often */
    protected static array $htmlFieldCache = [];

    protected function onBeforeWrite(): void
    {
        $owner = $this->getOwner();
        $converter = null;

        foreach ($this->getHtmlFieldNames() as $field) {
            $value = $owner->getField($field);
            if (!is_string($value) || !Base64ImageConverter::containsDataImage($value)) {
                continue;
            }

            $converter ??= Base64ImageConverter::create();
            $newValue = $converter->convert($value, sprintf('%s #%d %s', get_class($owner), $owner->ID, $field));

            if ($newValue !== $value) {
                $owner->setField($field, $newValue);
            }
        }
    }

    /**
     * All HTML fields of the owner's class, including those of its parent classes
     */
    protected function getHtmlFieldNames(): array
    {
        $class = get_class($this->getOwner());

        if (!isset(static::$htmlFieldCache[$class])) {
            static::$htmlFieldCache[$class] = static::filterHtmlFields(DataObject::getSchema()->databaseFields($class));
        }

        return static::$htmlFieldCache[$class];
    }

    /**
     * @param array $fieldSpecs [field => spec], as returned by DataObjectSchema::databaseFields()
     * @return string[] names of the HTML fields
     */
    public static function filterHtmlFields(array $fieldSpecs): array
    {
        $fields = [];
        foreach ($fieldSpecs as $field => $spec) {
            // HTMLText, HTMLVarchar(255), HTMLFragment, DBHTMLText or the fully qualified class name
            if (preg_match('/^(?:[\w\\\\]*\\\\)?(?:DB)?HTML(?:Text|Varchar|Fragment)\b/', (string) $spec)) {
                $fields[] = $field;
            }
        }

        return $fields;
    }
}
