<?php

namespace WPMagus\Charm\Settings;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The basic site settings on one ACF options page (Settings > Charm): the logo and the social profile URLs.
 *
 * Read them through the helpers in functions.php (`charm_logo_id()`, `charm_social_profiles()`), which the theme
 * uses for the header/footer/login logo and the footer icons. Yoast's organisation profiles (`wpseo_social`) are
 * fed from the same fields, so there is one place to edit.
 *
 * Stored as ACF options, field names `charm_logo` and `social_<network>` (`social_*` keeps the names of the
 * values entered before this plugin existed).
 */
class Settings
{
    public const PAGE = 'charm-settings';

    /** field name => [core social-link service, label] */
    private const NETWORKS = [
        'facebook' => ['facebook', 'Facebook'],
        'linkedin' => ['linkedin', 'LinkedIn'],
        'google' => ['google', 'Google Business Profile'],
        'instagram' => ['instagram', 'Instagram'],
        'tiktok' => ['tiktok', 'TikTok'],
        'threads' => ['threads', 'Threads'],
    ];

    public static function init(): void
    {
        add_action('acf/init', [self::class, 'registerFields']);
        add_filter('option_wpseo_social', [self::class, 'feedYoast']);
    }

    /** ACF answers only after its init: options read while plugins load (Yoast does) would trigger notices. */
    private static function acfReady(): bool
    {
        return function_exists("get_field") && did_action("acf/init") > 0;
    }

    /** Attachment id of the logo: the one set here, else the core Site Logo, else 0 (themes then draw their own). */
    public static function logoId(): int
    {
        $id = self::acfReady() ? (int) get_field('charm_logo', 'option') : 0;

        return $id ?: (int) get_option('site_logo');
    }

    /** @return array<int, array{service:string, label:string, url:string}> filled-in profiles, in field order */
    public static function socialProfiles(): array
    {
        if (! self::acfReady()) {
            return [];
        }

        $profiles = [];

        foreach (self::NETWORKS as $name => [$service, $label]) {
            $url = trim((string) get_field('social_'.$name, 'option'));

            if ($url !== '') {
                $profiles[] = ['service' => $service, 'label' => $label, 'url' => $url];
            }
        }

        return $profiles;
    }

    /**
     * Yoast's organisation schema (`sameAs`) reads only `facebook_site`, `twitter_site` and `other_social_urls`, so
     * every network except Facebook goes to the other profiles (LinkedIn and Instagram also fill their own fields).
     */
    public static function feedYoast($value)
    {
        if (! is_array($value)) {
            return $value;
        }

        $byService = array_column(self::socialProfiles(), 'url', 'service');

        foreach (['facebook' => 'facebook_site', 'linkedin' => 'linkedin_url', 'instagram' => 'instagram_url'] as $service => $key) {
            if (isset($byService[$service])) {
                $value[$key] = $byService[$service];
            }
        }

        $other = array_values(array_filter(array_map(
            static fn (string $service) => $byService[$service] ?? '',
            ['linkedin', 'instagram', 'google', 'tiktok', 'threads']
        )));

        if ($other) {
            $value['other_social_urls'] = $other;
        }

        return $value;
    }

    public static function registerFields(): void
    {
        if (! function_exists('acf_add_options_page')) {
            return;
        }

        acf_add_options_page([
            'page_title' => __('Charm settings', 'charm-by-wpmagus'),
            'menu_title' => __('Charm', 'charm-by-wpmagus'),
            'menu_slug' => self::PAGE,
            'parent_slug' => 'options-general.php',
            'capability' => 'manage_options',
        ]);

        acf_add_local_field_group([
            'key' => 'group_charm_brand',
            'title' => __('Brand', 'charm-by-wpmagus'),
            'fields' => [[
                'key' => 'field_charm_logo',
                'label' => __('Logo', 'charm-by-wpmagus'),
                'name' => 'charm_logo',
                'type' => 'image',
                'instructions' => __('Shown in the site header and on the login screen. Empty: the logo set in the Site Editor, else the theme\'s own.', 'charm-by-wpmagus'),
                'return_format' => 'id',
                'preview_size' => 'medium',
                'library' => 'all',
                'mime_types' => 'svg,png,webp,jpg',
            ]],
            'location' => [[['param' => 'options_page', 'operator' => '==', 'value' => self::PAGE]]],
            'menu_order' => 0,
        ]);

        $fields = [];

        foreach (self::NETWORKS as $name => [, $label]) {
            $fields[] = [
                'key' => 'field_social_'.$name,
                'label' => $label,
                'name' => 'social_'.$name,
                'type' => 'url',
                'instructions' => $name === 'google' ? __('Link to the Google Business Profile (the share link is fine).', 'charm-by-wpmagus') : '',
                'wrapper' => ['width' => '50'],
            ];
        }

        acf_add_local_field_group([
            'key' => 'group_social_profiles',
            'title' => __('Social profiles', 'charm-by-wpmagus'),
            'fields' => $fields,
            'location' => [[['param' => 'options_page', 'operator' => '==', 'value' => self::PAGE]]],
            'menu_order' => 10,
        ]);
    }
}
