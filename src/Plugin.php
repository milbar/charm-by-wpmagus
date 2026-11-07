<?php

namespace WPMagus\Charm;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Boots every component. Security, Admin, Content and Development carry over the features of the former
 * milbar-muplugin, Branding those of milbar-branding; Settings (logo and social profiles), Credit (the WPMágus credit logo) and Transfer (content export / import between environments) are new.
 */
class Plugin
{
    public static function init(): void
    {
        add_action('init', [self::class, 'loadTextDomain'], 0);

        Security\Security::init();
        Admin\Admin::init();
        Content\Content::init();
        Development\Development::init();
        Branding\Branding::init();
        Settings\Settings::init();
        Credit\Credit::init();
        Transfer\Transfer::init();
    }

    public static function loadTextDomain(): void
    {
        load_muplugin_textdomain('charm-by-wpmagus', basename(CHARM_BY_WPMAGUS_DIR).'/languages');
    }
}
