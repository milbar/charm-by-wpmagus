<?php

namespace WPMagus\Charm\Content;

if (! defined('ABSPATH')) {
    exit;
}

class Content
{
    public static function init(): void
    {
        add_filter('sanitize_file_name', [self::class, 'sanitizeFileName']);
        add_filter('post_password_required', [self::class, 'bypassPasswordForEditors'], 10, 2);
    }

    /** Clean upload filenames: ASCII, lowercase, single dashes, one dot. */
    public static function sanitizeFileName(string $filename): string
    {
        $filename = remove_accents($filename);
        $filename = str_replace([' ', '%20', '_'], '-', $filename);
        $filename = preg_replace('/[^A-Za-z0-9-\. ]/', '', $filename); // only letters, digits, dash, dot
        $filename = preg_replace('/\.(?=.*\.)/', '', $filename);      // all dots but the last
        $filename = preg_replace('/-+/', '-', $filename);
        $filename = str_replace('-.', '.', $filename);

        return strtolower($filename);
    }

    /** Password-protected content is open for logged-in users who can edit posts. */
    public static function bypassPasswordForEditors(bool $required, $post): bool
    {
        if ($required && is_user_logged_in() && current_user_can('edit_posts')) {
            return false;
        }

        return $required;
    }
}
