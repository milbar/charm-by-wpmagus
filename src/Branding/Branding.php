<?php

namespace WPMagus\Charm\Branding;

use WPMagus\Charm\Settings\Settings;

if (! defined('ABSPATH')) {
    exit;
}

/** Admin and login screen look. The login logo is the site's own (Settings > Charm), the bundled emblem as fallback. */
class Branding
{
    public static function init(): void
    {
        add_action('admin_bar_menu', [self::class, 'removeWpLogoNode'], 999);
        add_action('admin_enqueue_scripts', [self::class, 'adminStyle']);
        add_action('login_enqueue_scripts', [self::class, 'loginStyle']);
        add_filter('login_headerurl', static fn () => home_url('/'));
        add_filter('login_headertext', static fn () => get_bloginfo('name'));
        add_filter('admin_footer_text', [self::class, 'adminFooter'], 999);
    }

    public static function removeWpLogoNode(\WP_Admin_Bar $adminBar): void
    {
        $adminBar->remove_node('wp-logo');
    }

    public static function adminStyle(): void
    {
        wp_enqueue_style('charm-by-wpmagus/admin', CHARM_BY_WPMAGUS_URL.'assets/css/admin.css', [], CHARM_BY_WPMAGUS_VERSION);
    }

    public static function loginStyle(): void
    {
        wp_enqueue_style('charm-by-wpmagus/login', CHARM_BY_WPMAGUS_URL.'assets/css/login.css', [], CHARM_BY_WPMAGUS_VERSION);

        $logo = Settings::logoId() ? wp_get_attachment_image_url(Settings::logoId(), 'medium') : '';
        $logo = $logo ?: CHARM_BY_WPMAGUS_URL.'assets/img/logo-mark.svg';

        wp_add_inline_style('charm-by-wpmagus/login', 'body.login h1 a{background-image:url("'.esc_url_raw($logo).'")}');
    }

    public static function adminFooter(): string
    {
        return 'Made with &#10084; by <a href="https://wpmagus.hu" target="_blank" rel="noopener">WPMágus</a>';
    }
}
