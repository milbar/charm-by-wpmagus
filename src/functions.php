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
