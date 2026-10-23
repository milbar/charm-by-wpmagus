<?php

namespace WPMagus\Charm\Admin;

if (! defined('ABSPATH')) {
    exit;
}

class Admin
{
    public static function init(): void
    {
        add_filter('login_display_language_dropdown', '__return_false');
        add_action('wp_head', [self::class, 'addAdminBarStyles']);
        add_filter('site_status_tests', [self::class, 'removeUnnecessarySiteHealthChecks']);
        add_filter('update_footer', [self::class, 'customizeUpdateFooter'], 11);
    }

    /** Sticky/fixed headers sit below the admin bar for logged-in users. */
    public static function addAdminBarStyles(): void
    {
        if (! is_user_logged_in()) {
            return;
        }

        ?>
        <style>
            @media screen and (min-width: 783px) {
                .admin-bar .fixed-top,
                .admin-bar .sticky-top { top: 32px; }
            }

            @media screen and (max-width: 782px) {
                .admin-bar .fixed-top,
                .admin-bar .sticky-top { top: 46px; }
            }

            @media screen and (max-width: 600px) {
                #wpadminbar { position: fixed; }
            }
        </style>
        <?php
    }

    public static function removeUnnecessarySiteHealthChecks(array $tests): array
    {
        if (defined('WP_ENV') && WP_ENV !== 'production') {
            unset($tests['direct']['debug_enabled']);
        }

        unset(
            $tests['direct']['theme_version'],
            $tests['direct']['plugin_theme_auto_updates'],
            $tests['direct']['persistent_object_cache'],
            $tests['async']['dotorg_communication'],
            $tests['async']['background_updates'],
            $tests['async']['page_cache'],
        );

        return $tests;
    }

    /** Admin footer (right side): WP version, WP_ENV and, with a theme revision.php, build date, commit and release. */
    public static function customizeUpdateFooter($footer): string
    {
        $revision = self::revision();

        if (! $revision) {
            return (string) $footer;
        }

        $parts = [sprintf('<b>%s: </b> %s', __('WP Version', 'charm-by-wpmagus'), get_bloginfo('version', 'display'))];

        if (defined('WP_ENV')) {
            $parts[] = sprintf('<b>%s: </b> %s', __('WP_ENV', 'charm-by-wpmagus'), WP_ENV);
        }

        if (! empty($revision['date'])) {
            $parts[] = sprintf('<b>%s: </b> %s', __('Build Date', 'charm-by-wpmagus'), wp_date('Y.m.d. H:i:s', strtotime($revision['date'])));
        }

        if (! empty($revision['repo_url'])) {
            $repo = $revision['repo_url'];

            if (! empty($revision['commit_id'])) {
                $parts[] = sprintf('<b>%s: </b><a href="%s" target="_blank" rel="noopener">%s</a>', __('Commit', 'charm-by-wpmagus'), esc_url("$repo/commit/{$revision['commit_id']}"), esc_html($revision['commit_id']));
            }

            if (! empty($revision['release'])) {
                $parts[] = sprintf('<b>%s: </b><a href="%s" target="_blank" rel="noopener">%s</a>', __('Release', 'charm-by-wpmagus'), esc_url("$repo/releases/tag/{$revision['release']}"), esc_html($revision['release']));
            }
        }

        return implode(' | ', $parts);
    }

    /** @return array<string, string>|null contents of the active theme's revision.php */
    private static function revision(): ?array
    {
        $file = get_stylesheet_directory().'/revision.php';

        return file_exists($file) ? (include $file) : null;
    }
}
