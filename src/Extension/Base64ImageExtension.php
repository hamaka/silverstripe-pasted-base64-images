<?php

namespace Hamaka\Base64Images\Extension;

use Hamaka\Base64Images\Base64ImageConverter;
use SilverStripe\Control\Director;
use SilverStripe\Core\Extension;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;

/**
 * Converts base64 images in every HTML field that processes shortcodes (HTMLText) into an Image in Files and an
 * [image] shortcode on write. See Base64ImageConverter.
 *
 * Applied to DataObject, so it covers pages, elemental blocks and any other object with an HTML field.
 *
 * Only when the current user may: see Base64ImageConverter.write_permission. Leave out classes or fields with
 * Base64ImageConverter.exclude_classes and exclude_fields, or disable it for the whole project with:
 *   SilverStripe\ORM\DataObject:
 *     extensions:
 *       base64Images: null
 *
 * @extends Extension<DataObject>
 */
class Base64ImageExtension extends Extension
{
    protected function onBeforeWrite(): void
    {
        $owner = $this->getOwner();
        $converter = null;
        $changed = false;

        foreach (Base64ImageConverter::convertibleFields(get_class($owner)) as $field) {
            $value = $owner->getField($field);
            if (!is_string($value) || !Base64ImageConverter::containsDataImage($value)) {
                continue;
            }

            if ($converter === null) {
                // Only checked once there is something to convert
                if (!$this->canConvertOnWrite()) {
                    return;
                }
                // One mail for the whole record, below
                $converter = Base64ImageConverter::create()->setNotify(false);
            }

            $newValue = $converter->convert($value, sprintf('%s #%d %s', get_class($owner), $owner->ID, $field));

            if ($newValue !== $value) {
                $owner->setField($field, $newValue);
                $changed = true;
            }
        }

        if ($converter === null) {
            return;
        }

        Base64ImageConverter::notify($converter->getErrors());

        // FileLinkTracking (the "Used on" tab in Files) also runs on write. When it ran before this extension it
        // has not seen the new shortcodes, so run it again.
        if ($changed && $owner->hasMethod('syncLinkTracking')) {
            $owner->syncLinkTracking();
        }
    }

    /**
     * The conversion creates and publishes files, so by default only CMS users trigger it. Writes from the command
     * line (imports, tasks) are always converted.
     */
    protected function canConvertOnWrite(): bool
    {
        $permission = Base64ImageConverter::config()->get('write_permission');

        if (!$permission || Director::is_cli()) {
            return true;
        }

        return (bool) Permission::check($permission);
    }
}
