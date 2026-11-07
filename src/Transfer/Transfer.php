<?php

namespace WPMagus\Charm\Transfer;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Moves finished content from one environment to another without a database migration.
 *
 *   wp charm content export --type=product,faq,page --options   writes a zip (content.json + the original files)
 *   wp charm content import <zip>                                dry run: lists what would change
 *   wp charm content import <zip> --apply                        writes it
 *
 * Also Tools > Content transfer in the admin (upload a zip; "Apply" unchecked is a dry run).
 *
 * What it carries: posts of any type (posts, pages, WooCommerce products with their variations, custom types) with
 * terms, meta and the featured image; every image / file / gallery, other post and term that content or ACF fields
 * point to is remapped by file hash and slug; optionally the ACF options pages. What it does not: menus, plugin
 * settings, WooCommerce attributes themselves (their terms are carried), users, comments, orders.
 *
 * The first version lived in the wpmagus theme (Charm\Modules\ContentTransfer). While a theme still ships that class
 * this one stays out of the way, so the two never register the same command.
 */
class Transfer
{
    /** Bundle format. 1 = the theme module's (posts only), still readable. */
    public const FORMAT = 2;

    public static function init(): void
    {
        add_action('init', [new self, 'register'], 99);
    }

    public function register(): void
    {
        if (class_exists('Charm\\Modules\\ContentTransfer\\ContentTransfer', false)) {
            return;
        }

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('charm content export', [$this, 'cliExport']);
            \WP_CLI::add_command('charm content import', [$this, 'cliImport']);
        }
        add_action('admin_menu', [$this, 'adminMenu']);
        add_action('admin_post_charm_content_import', [$this, 'handleUpload']);
    }

    /**
     * Writes a content bundle.
     *
     * ## OPTIONS
     *
     * [--type=<types>]
     * : Comma-separated post types (default: post,faq; a theme can change it with the charm/transfer/default_types filter).
     *
     * [--slug=<slugs>]
     * : Comma-separated slugs.
     *
     * [--since=<date>]
     * : Only items modified on or after this date (YYYY-MM-DD).
     *
     * [--status=<statuses>]
     * : Comma-separated statuses (default: publish,future,draft,pending).
     *
     * [--options]
     * : Also the ACF options pages (logos, company data, the pages the theme links to ...).
     *
     * [--file=<path>]
     * : Target zip (default: content-<date>.zip in the current directory).
     */
    public function cliExport(array $args, array $assoc): void
    {
        $defaults = (array) apply_filters('charm/transfer/default_types', ['post', 'faq']);
        $query = [
            'post_type' => array_filter(array_map('trim', explode(',', $assoc['type'] ?? implode(',', $defaults)))),
            'post_status' => array_filter(array_map('trim', explode(',', $assoc['status'] ?? 'publish,future,draft,pending'))),
            'posts_per_page' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'fields' => 'ids',
        ];
        if (! empty($assoc['slug'])) {
            $query['post_name__in'] = array_filter(array_map('trim', explode(',', $assoc['slug'])));
        }
        if (! empty($assoc['since'])) {
            $query['date_query'] = [['column' => 'post_modified', 'after' => $assoc['since']]];
        }
        $ids = get_posts($query);
        if (! $ids && ! isset($assoc['options'])) {
            \WP_CLI::error('No items match.');
        }

        $file = $assoc['file'] ?? getcwd().'/content-'.wp_date('Y-m-d-His').'.zip';
        try {
            $result = (new Exporter)->export($ids, $file, isset($assoc['options']));
        } catch (\RuntimeException $e) {
            \WP_CLI::error($e->getMessage());
        }

        foreach ($result['warnings'] as $warning) {
            \WP_CLI::warning($warning);
        }
        \WP_CLI::success(sprintf('%d item(s), %d option(s), %d file(s) -> %s', $result['items'], $result['options'], $result['media'], $file));
    }

    /**
     * Imports a content bundle (dry run unless --apply).
     *
     * ## OPTIONS
     *
     * <file>
     * : Path of the zip.
     *
     * [--apply]
     * : Write the changes. Without it only the report is printed.
     *
     * [--force]
     * : Overwrite live items that were edited after the last import (and ones no import has written yet).
     */
    public function cliImport(array $args, array $assoc): void
    {
        foreach ((new Importer)->import($args[0], isset($assoc['apply']), isset($assoc['force'])) as $line) {
            \WP_CLI::log($line);
        }
        \WP_CLI::success(isset($assoc['apply']) ? 'Done.' : 'Dry run only, nothing written. Add --apply to import.');
    }

    public function adminMenu(): void
    {
        add_management_page(__('Content transfer', 'charm-by-wpmagus'), __('Content transfer', 'charm-by-wpmagus'), 'manage_options', 'charm-content-transfer', [$this, 'page']);
    }

    public function page(): void
    {
        $report = get_transient('charm_transfer_report_'.get_current_user_id());
        delete_transient('charm_transfer_report_'.get_current_user_id());

        echo '<div class="wrap"><h1>'.esc_html__('Content transfer', 'charm-by-wpmagus').'</h1>';
        if ($report) {
            echo '<h2>'.esc_html__('Result', 'charm-by-wpmagus').'</h2><pre style="background:#fff;padding:12px;border:1px solid #ccd0d4">'.esc_html(implode("\n", $report)).'</pre>';
        }
        echo '<form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="charm_content_import">';
        wp_nonce_field('charm_content_import');
        echo '<p><input type="file" name="bundle" accept=".zip" required></p>';
        echo '<p><label><input type="checkbox" name="apply" value="1"> '.esc_html__('Apply (unchecked it is a dry run that lists what would change)', 'charm-by-wpmagus').'</label></p>';
        echo '<p><label><input type="checkbox" name="force" value="1"> '.esc_html__('Also overwrite content that was edited here by hand', 'charm-by-wpmagus').'</label></p>';
        submit_button(__('Upload and run', 'charm-by-wpmagus'));
        echo '</form></div>';
    }

    public function handleUpload(): void
    {
        check_admin_referer('charm_content_import');
        if (! current_user_can('manage_options') || empty($_FILES['bundle']['tmp_name'])) {
            wp_die(esc_html__('No permission or no file.', 'charm-by-wpmagus'));
        }
        @set_time_limit(300);
        $report = (new Importer)->import($_FILES['bundle']['tmp_name'], ! empty($_POST['apply']), ! empty($_POST['force']));
        set_transient('charm_transfer_report_'.get_current_user_id(), $report, 300);
        wp_safe_redirect(admin_url('tools.php?page=charm-content-transfer'));
        exit;
    }
}
