<?php

namespace WPMagus\Charm\Security;

if (! defined('ABSPATH')) {
    exit;
}

class Security
{
    public static function init(): void
    {
        // XML-RPC off
        add_filter('xmlrpc_enabled', '__return_false');
        add_filter('pre_update_option_enable_xmlrpc', '__return_false');
        add_filter('pre_option_enable_xmlrpc', '__return_zero');

        // Pings off
        add_filter('pings_open', '__return_false', 9999);

        // Nothing in <head> that names the WordPress version or the legacy editors
        add_filter('the_generator', '__return_empty_string');
        remove_action('wp_head', 'wp_generator');
        remove_action('wp_head', 'wlwmanifest_link');
        remove_action('wp_head', 'rsd_link');

        add_filter('wp_headers', [self::class, 'removePingbackHeaders']);
        add_action('init', [self::class, 'interceptXmlrpcHeader']);
    }

    public static function removePingbackHeaders(array $headers): array
    {
        unset($headers['X-Pingback'], $headers['x-pingback']);

        return $headers;
    }

    /** Direct requests to xmlrpc.php end here. */
    public static function interceptXmlrpcHeader(): void
    {
        if (! isset($_SERVER['SCRIPT_FILENAME']) || basename($_SERVER['SCRIPT_FILENAME']) !== 'xmlrpc.php') {
            return;
        }

        header('HTTP/1.1 403 Forbidden');
        echo 'HTTP/1.1 403 Forbidden';
        exit;
    }
}
