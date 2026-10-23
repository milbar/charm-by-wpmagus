<?php
/*
Plugin Name: Charm by WPMágus
Plugin URI: https://wpmagus.hu
Description: Must-use plugin of the Charm sites: security and admin hardening, WPMágus branding, and the basic site settings (logo, social profiles).
Version: 1.0.2
Author: WPMágus
Author URI: https://wpmagus.hu
License: MIT
Text Domain: charm-by-wpmagus
*/

if (! defined('ABSPATH')) {
    exit;
}

define('CHARM_BY_WPMAGUS_DIR', plugin_dir_path(__FILE__));
define('CHARM_BY_WPMAGUS_URL', plugin_dir_url(__FILE__));
define('CHARM_BY_WPMAGUS_VERSION', '1.0.2');

// No Composer step: one tiny PSR-4 loader for WPMagus\Charm\ => src/
spl_autoload_register(static function (string $class): void {
    $prefix = 'WPMagus\\Charm\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $file = CHARM_BY_WPMAGUS_DIR.'src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

    if (is_readable($file)) {
        require_once $file;
    }
});

require_once CHARM_BY_WPMAGUS_DIR.'src/functions.php';

WPMagus\Charm\Plugin::init();
