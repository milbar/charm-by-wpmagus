<?php

/**
 * Template-facing API of Charm by WPMágus. Themes call these behind a function_exists() check.
 */

use WPMagus\Charm\Settings\Settings;

if (! function_exists('charm_logo_id')) {
    /** Attachment id of the site logo (Settings > Charm, else core Site Logo), 0 when none. */
    function charm_logo_id(): int
    {
        return Settings::logoId();
    }
}

if (! function_exists('charm_social_profiles')) {
    /** @return array<int, array{service:string, label:string, url:string}> */
    function charm_social_profiles(): array
    {
        return Settings::socialProfiles();
    }
}

if (! function_exists('charm_credit')) {
    /**
     * The "website by WPMágus" logo link, as HTML (echo it). Options: surface (dark|light), mark (bool), tone (grey|color),
     * height (px), class, style, url, label. Style it with the --charm-credit-* CSS variables or the charm_credit_* filters.
     * The shortcode [charm_credit] takes the same options.
     */
    function charm_credit(array $args = []): string
    {
        return WPMagus\Charm\Credit\Credit::render($args);
    }
}
