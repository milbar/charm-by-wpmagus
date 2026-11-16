<?php

namespace WPMagus\Charm\Environment;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * A notice on every environment that is not the live site: an amber bar at the top of every page, a notice in the admin
 * and a message on the login screen say it is a test system and the data on it is not real.
 *
 * The notice is shown unless the environment is `production`. The environment is `WP_ENV` (Bedrock), else
 * `WP_ENVIRONMENT_TYPE` (constant or environment variable); when neither is set it is not known to be production, so
 * the notice shows. The `charm_test_notice` filter can force it on or off, `charm_test_notice_css` changes the bar's
 * look (return '' to style `.charm-test-notice` in the theme).
 */
class TestNotice
{
    public static function init(): void
    {
        // On init, not while the mu-plugin loads: by then the theme's `charm_test_notice` filter is registered.
        add_action('init', [self::class, 'register']);
    }

    public static function register(): void
    {
        if (! self::active()) {
            return;
        }

        add_action('wp_head', [self::class, 'css']);
        add_action('wp_body_open', [self::class, 'bar']);
        add_action('admin_notices', [self::class, 'adminNotice']);
        add_action('login_message', [self::class, 'loginMessage']);
    }

    /** The environment name: WP_ENV, else WP_ENVIRONMENT_TYPE, else an empty string (not set). */
    public static function environment(): string
    {
        if (defined('WP_ENV') && WP_ENV !== '') {
            return (string) WP_ENV;
        }

        if (defined('WP_ENVIRONMENT_TYPE') && WP_ENVIRONMENT_TYPE !== '') {
            return (string) WP_ENVIRONMENT_TYPE;
        }

        $env = getenv('WP_ENVIRONMENT_TYPE');

        return is_string($env) ? $env : '';
    }

    public static function active(): bool
    {
        return (bool) apply_filters('charm_test_notice', self::environment() !== 'production');
    }

    public static function text(): string
    {
        return __('This is a test system, not the live website. The content and data on it are not real.', 'charm-by-wpmagus');
    }

    public static function css(): void
    {
        $css = '.charm-test-notice{padding:.5rem 1rem;background:#fcd34d;color:#232323;font-size:.875rem;line-height:1.4;text-align:center}';
        $css = (string) apply_filters('charm_test_notice_css', $css);

        if ($css !== '') {
            echo '<style id="charm-test-notice-css">'.$css.'</style>';
        }
    }

    public static function bar(): void
    {
        echo '<div class="charm-test-notice" role="status"><strong>'.esc_html__('Test system', 'charm-by-wpmagus').'</strong> '.esc_html(self::text()).'</div>';
    }

    public static function adminNotice(): void
    {
        echo '<div class="notice notice-warning"><p><strong>'.esc_html__('Test system', 'charm-by-wpmagus').'</strong> '.esc_html(self::text()).'</p></div>';
    }

    public static function loginMessage(string $message): string
    {
        return $message.'<p class="message"><strong>'.esc_html__('Test system', 'charm-by-wpmagus').'</strong> '.esc_html(self::text()).'</p>';
    }
}
