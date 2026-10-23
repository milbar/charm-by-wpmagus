<?php

namespace WPMagus\Charm\Development;

if (! defined('ABSPATH')) {
    exit;
}

class Development
{
    public static function init(): void
    {
        add_action('admin_init', [self::class, 'preventSearchEnginesDiscouragedNotice']);

        // WP Rocket must not write WP_CACHE into wp-config.php (Bedrock keeps config in code)
        add_filter('rocket_set_wp_cache_constant', '__return_false');
    }

    /** Yoast's "search engines discouraged" notice is expected outside production. */
    public static function preventSearchEnginesDiscouragedNotice(): void
    {
        if (! class_exists('WPSEO_Options')) {
            return;
        }

        $production = ! defined('WP_ENV') || WP_ENV === 'production';

        \WPSEO_Options::set('ignore_search_engines_discouraged_notice', ! $production);
    }
}
