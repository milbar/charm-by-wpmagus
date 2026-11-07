<?php

namespace WPMagus\Charm\Transfer;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Reads a content bundle (see Exporter). A dry run (the default) only reports; with $apply it writes.
 *
 * - Items are matched by post type + slug; product variations by their attribute values.
 * - Images are matched by the SHA-1 of the file, so each is uploaded once; every id in content, meta and options is
 *   remapped (FieldMap tokens: attachments, other posts and terms by slug).
 * - A live item whose content or meta changed after the last import (fingerprint kept in `_charm_transfer_fp`) is a
 *   conflict and left alone unless $force. The fingerprint covers title, excerpt, content, status, menu order and the
 *   meta the bundle carries; terms are not part of it.
 */
class Importer
{
    private const STATUSES = ['publish', 'future', 'draft', 'pending', 'private'];

    /** @var string[] */
    private array $report = [];

    /** @var array<string, int> media key => attachment id (0 for a file a dry run would upload) */
    private array $media = [];

    /** @var array<string, int> "type/slug" => post id (0: created by this import, not yet there) */
    private array $posts = [];

    /** @var array<string, true> "type/slug" of every item of the bundle */
    private array $bundle = [];

    /** @var string[] */
    private array $missing = [];

    /** @return string[] report lines */
    public function import(string $zipPath, bool $apply, bool $force): array
    {
        $this->report = [];
        $this->media = [];
        $this->posts = [];
        $this->bundle = [];

        $zip = new \ZipArchive;
        if ($zip->open($zipPath) !== true) {
            return ['Cannot open the zip.'];
        }
        $raw = $zip->getFromName('content.json');
        $data = $raw ? json_decode(str_replace('{{SITE}}', untrailingslashit(home_url()), $raw), true) : null;
        if (! is_array($data) || ! in_array($data['format'] ?? 0, [1, 2], true)) {
            return ['content.json is missing or has an unknown format.'];
        }

        foreach ($data['media'] ?? [] as $key => $m) {
            $existing = get_posts(['post_type' => 'attachment', 'post_status' => 'any', 'meta_key' => '_charm_transfer_key', 'meta_value' => $key, 'numberposts' => 1, 'fields' => 'ids']);
            $existing = $existing[0] ?? $this->adoptByFile($key, $m['file'], $apply);
            if ($existing) {
                $this->media[$key] = (int) $existing;

                continue;
            }
            $this->report[] = ($apply ? 'UPLOAD ' : 'would upload ').$m['file'];
            $this->media[$key] = $apply ? $this->sideload($zip, $key, $m) : 0;
        }

        $items = $data['items'] ?? [];
        $live = [];
        foreach ($items as $item) {
            $label = $item['type'].'/'.$item['slug'];
            $this->bundle[$label] = true;
            $live[$label] = $this->find($item['type'], $item['slug']);
            $this->posts[$label] = $live[$label]?->ID ?? 0;
        }

        // decide what to do with each item
        $todo = [];
        foreach ($items as $item) {
            $label = $item['type'].'/'.$item['slug'];
            $post = $live[$label];
            $this->missing = [];
            $content = $this->decodeContent($item['content']);
            $meta = $this->decodeMeta($item['meta']);
            $variations = $this->decodeVariations($item['variations'] ?? []);
            $core = ['title' => $item['title'], 'excerpt' => $item['excerpt'], 'content' => $content, 'status' => $item['status'], 'menu_order' => (int) $item['menu_order']];
            $meta['_thumbnail_id'] = $item['thumbnail'] ? (string) ($this->media[$item['thumbnail']] ?? 0) : '0';
            $shape = $this->shapeOf($meta, $variations);

            foreach (array_unique($this->missing) as $ref) {
                $this->report[] = 'warning    '.$label.' refers to '.$ref.', which is neither in the bundle nor on this site';
            }

            if ($post) {
                $incoming = FieldMap::normalize($this->snapshot($core, $meta, $variations));
                if ($incoming === $this->liveSnapshot($post, $shape)) {
                    $this->report[] = 'unchanged  '.$label;

                    continue;
                }
                if (! $force && $this->isConflict($post)) {
                    $this->report[] = 'CONFLICT   '.$label.' (edited on this site after the last import; --force / checkbox to overwrite)';

                    continue;
                }
            }
            $this->report[] = ($post ? ($apply ? 'UPDATE     ' : 'would update ') : ($apply ? 'CREATE     ' : 'would create ')).$label;
            $todo[$label] = ['item' => $item, 'core' => $core, 'post' => $post];
        }

        if ($apply) {
            $author = $this->importer();
            // pass 1: the posts themselves, so every reference between them can be resolved in pass 2
            foreach ($todo as $label => &$job) {
                $id = $this->writePost($job['item'], $job['core'], $job['post'], $author);
                if (is_wp_error($id)) {
                    $this->report[] = 'ERROR      '.$label.': '.$id->get_error_message();
                    $job['id'] = 0;

                    continue;
                }
                $job['id'] = (int) $id;
                $this->posts[$label] = (int) $id;
            }
            unset($job);

            // pass 2: terms, meta, thumbnail, variations
            foreach ($todo as $label => $job) {
                if ($job['id']) {
                    $this->writeDetails($job['id'], $job['item']);
                }
            }
        }

        $this->options($data['options'] ?? [], $apply);
        $zip->close();

        return $this->report;
    }

    // ------------------------------------------------------------------ writing

    private function writePost(array $item, array $core, ?\WP_Post $post, int $author): int|\WP_Error
    {
        $postarr = [
            'post_type' => $item['type'],
            'post_name' => $item['slug'],
            'post_status' => $core['status'],
            'post_title' => $core['title'],
            'post_excerpt' => $core['excerpt'],
            'post_content' => $core['content'],
            'post_date' => $item['date'],
            'post_date_gmt' => $item['date_gmt'],
            'menu_order' => $core['menu_order'],
        ];
        if ($post) {
            $postarr['ID'] = $post->ID;
        } else {
            $postarr['post_author'] = $author;
        }

        return wp_insert_post(wp_slash($postarr), true);
    }

    private function writeDetails(int $id, array $item): void
    {
        $this->missing = [];
        $label = $item['type'].'/'.$item['slug'];

        $this->writeTerms($id, $item['terms'] ?? []);
        $meta = $this->decodeMeta($item['meta']);
        $this->writeMeta($id, $meta);

        if ($item['thumbnail'] && ! empty($this->media[$item['thumbnail']])) {
            set_post_thumbnail($id, $this->media[$item['thumbnail']]);
        } else {
            delete_post_thumbnail($id);
        }
        foreach (['opengraph', 'twitter'] as $net) {
            if ($ogId = (int) get_post_meta($id, '_yoast_wpseo_'.$net.'-image-id', true)) {
                update_post_meta($id, '_yoast_wpseo_'.$net.'-image', wp_get_attachment_url($ogId));
            }
        }

        $variations = $this->decodeVariations($item['variations'] ?? []);
        if ($variations) {
            $this->writeVariations($id, $variations, $item['variations']);
        }
        if ($item['type'] === 'product') {
            $this->refreshProduct($id);
        }

        foreach (array_unique($this->missing) as $ref) {
            $this->report[] = 'warning    '.$label.' refers to '.$ref.', which is neither in the bundle nor on this site';
        }

        // fingerprint after everything is written: what the post is now, over exactly the keys the bundle carries
        $post = get_post($id);
        $thumb = $item['thumbnail'] ? (string) ($this->media[$item['thumbnail']] ?? 0) : '0';
        $meta['_thumbnail_id'] = $thumb;
        $shape = $this->shapeOf($meta, $variations);
        update_post_meta($id, '_charm_transfer_shape', wp_slash((string) wp_json_encode($shape)));
        update_post_meta($id, '_charm_transfer_fp', md5((string) wp_json_encode($this->liveSnapshot($post, $shape))));
    }

    private function writeTerms(int $id, array $terms): void
    {
        foreach ($terms as $tax => $list) {
            if (! taxonomy_exists($tax)) {
                $this->report[] = 'warning    taxonomy '.$tax.' does not exist here';

                continue;
            }
            $ids = [];
            foreach ($list as $t) {
                $path = $t['path'] ?? [[$t['slug'], $t['name']]]; // format 1: a single term
                $parent = 0;
                $termId = 0;
                foreach ($path as [$slug, $name]) {
                    $termId = $this->term($tax, $slug, $name, $parent);
                    $parent = $termId;
                }
                $ids[] = $termId;
            }
            wp_set_object_terms($id, array_filter($ids), $tax);
        }
    }

    private function term(string $tax, string $slug, string $name, int $parent): int
    {
        $term = get_term_by('slug', $slug, $tax);
        if ($term instanceof \WP_Term) {
            return $term->term_id;
        }
        $args = ['slug' => $slug];
        if ($parent && is_taxonomy_hierarchical($tax)) {
            $args['parent'] = $parent;
        }
        $new = wp_insert_term($name, $tax, $args);

        return is_wp_error($new) ? 0 : (int) $new['term_id'];
    }

    private function writeMeta(int $id, array $meta): void
    {
        global $wpdb;

        // a repeater that has fewer rows than before must not keep the old ones: clear its rows first
        foreach (array_keys($meta) as $key) {
            if (FieldMap::isRepeater(is_string($meta['_'.$key] ?? null) ? $meta['_'.$key] : null)) {
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key REGEXP %s", $id, '^_?'.preg_quote((string) $key, '/').'_[0-9]+_'));
            }
        }
        wp_cache_delete($id, 'post_meta');

        foreach ($meta as $key => $value) {
            if (! in_array($key, FieldMap::SKIP_META, true)) {
                update_post_meta($id, wp_slash($key), wp_slash($value));
            }
        }
    }

    /**
     * @param  array<int, array>  $variations  decoded
     * @param  array<int, array>  $raw  as in the bundle (thumbnails, titles)
     */
    private function writeVariations(int $parentId, array $variations, array $raw): void
    {
        $existing = [];
        foreach ($this->variationPosts($parentId) as $v) {
            $existing[$this->signature(get_post_meta($v->ID))] = $v->ID;
        }

        foreach ($variations as $i => $variation) {
            $sig = $this->signature($variation['meta']);
            $postarr = ['post_type' => 'product_variation', 'post_parent' => $parentId, 'post_status' => $variation['status'], 'post_title' => $variation['title'], 'menu_order' => $variation['menu_order']];
            if (isset($existing[$sig])) {
                $postarr['ID'] = $existing[$sig];
            }
            $vid = wp_insert_post(wp_slash($postarr), true);
            if (is_wp_error($vid)) {
                $this->report[] = 'ERROR      variation of '.$parentId.': '.$vid->get_error_message();

                continue;
            }
            $this->writeMeta($vid, $variation['meta']);
            $thumb = $raw[$i]['thumbnail'] ?? null;
            if ($thumb && ! empty($this->media[$thumb])) {
                set_post_thumbnail($vid, $this->media[$thumb]);
            }
        }
        // no WC_Product_Variable::sync(): the parent's own price and stock meta come with the bundle, and the sync would
        // recompute them from the visible variations (a quote catalog without prices would turn "in stock" into "out of stock")
        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients($parentId);
        }
    }

    /** Prices, stock and the lookup table behind the shop's sorting and filtering. */
    private function refreshProduct(int $id): void
    {
        if (! function_exists('wc_get_product')) {
            return;
        }
        wc_delete_product_transients($id);
        try {
            \WC_Data_Store::load('product')->update_lookup_table($id, 'wc_product_meta_lookup');
        } catch (\Throwable) {
            // the lookup table is rebuilt by WooCommerce's own tools if this is not available
        }
    }

    // ------------------------------------------------------------------ options

    /** @param  array<string, array{key: string, value: mixed}>  $options */
    private function options(array $options, bool $apply): void
    {
        if (! $options) {
            return;
        }
        $changed = 0;
        foreach ($options as $name => $option) {
            // only what the exporter writes: ACF options-page values (`options_<field>` with an ACF field key)
            if (! is_string($name) || ! str_starts_with($name, 'options_') || ! is_string($option['key'] ?? null) || ! str_starts_with($option['key'], 'field_')) {
                $this->report[] = 'warning    option '.(is_string($name) ? $name : '?').' skipped: not an ACF options value';

                continue;
            }
            $this->missing = [];
            $value = $this->decode($option['value']);
            if (FieldMap::normalize($value) === FieldMap::normalize(get_option($name, ''))) {
                continue;
            }
            $changed++;
            $this->report[] = ($apply ? 'OPTION     ' : 'would set  ').$name;
            if ($apply) {
                update_option($name, $value);
                update_option('_'.$name, $option['key']);
            }
        }
        $this->report[] = sprintf('options: %d changed, %d unchanged', $changed, count($options) - $changed);
        foreach (array_unique($this->missing) as $ref) {
            $this->report[] = 'warning    an option refers to '.$ref.', which is neither in the bundle nor on this site';
        }
    }

    // ------------------------------------------------------------------ decoding

    private function decodeContent(string $content): string
    {
        $content = preg_replace_callback('/\{\{MEDIA:([0-9a-f]{40})\}\}/', function (array $m) {
            $id = $this->media[$m[1]] ?? 0;

            return $id ? (string) wp_get_attachment_image_url($id, 'large') : '';
        }, $content);

        return preg_replace_callback(FieldMap::TOKEN_MEDIA, fn (array $m) => (string) ($this->media[$m[1]] ?? 0), $content);
    }

    /** @return array<string, mixed> */
    private function decodeMeta(array $meta): array
    {
        return array_map(fn ($v) => $this->decode($v), $meta);
    }

    /** @return array<int, array{status: string, title: string, menu_order: int, meta: array}> */
    private function decodeVariations(array $variations): array
    {
        return array_map(fn (array $v) => [
            'status' => $v['status'],
            'title' => $v['title'],
            'menu_order' => (int) $v['menu_order'],
            'meta' => $this->decodeMeta($v['meta']) + ['_thumbnail_id' => $v['thumbnail'] ? (string) ($this->media[$v['thumbnail']] ?? 0) : '0'],
        ], $variations);
    }

    /** Replaces the tokens in a value by the ids of this site; what cannot be resolved is dropped from lists. */
    private function decode(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $decoded = $this->decode($v);
                if ($decoded !== null) {
                    $out[$k] = $decoded;
                }
            }

            return $out;
        }
        if (! is_string($value)) {
            return $value;
        }
        // meta and options bypass the kses filters of wp_insert_post: a multisite admin without unfiltered_html
        // must not be able to store markup there through a bundle
        if (is_multisite() && get_current_user_id() && ! current_user_can('unfiltered_html')) {
            $value = wp_kses_post($value);
        }
        if (! str_contains($value, '{{')) {
            return $value;
        }
        if (preg_match('/^\{\{(MEDIA-ID|POST|TERM):[^}]+\}\}$/', $value)) {
            return $this->token($value);
        }

        // several tokens in one string (WooCommerce's comma-separated gallery); any other text stays as it is
        $one = '\{\{(?:MEDIA-ID|POST|TERM):[^}]+\}\}';
        if (! preg_match('/^'.$one.'(?:,'.$one.')*$/', $value)) {
            return $value;
        }
        $parts = array_filter(array_map(fn ($p) => $this->token(trim($p)), explode(',', $value)), fn ($p) => $p !== null && $p !== '');

        return implode(',', $parts);
    }

    private function token(string $token): ?string
    {
        if (preg_match(FieldMap::TOKEN_MEDIA, $token, $m)) {
            $id = $this->media[$m[1]] ?? 0;

            return $id ? (string) $id : (array_key_exists($m[1], $this->media) ? '0' : null);
        }
        if (preg_match(FieldMap::TOKEN_POST, $token, $m)) {
            $label = $m[1].'/'.$m[2];
            if (! isset($this->posts[$label]) && ! isset($this->bundle[$label])) {
                $post = $this->find($m[1], $m[2]);
                $this->posts[$label] = $post?->ID ?? -1;
            }
            $id = $this->posts[$label] ?? 0;
            if ($id === -1) {
                $this->missing[] = $label;

                return null;
            }

            return (string) $id;
        }
        if (preg_match(FieldMap::TOKEN_TERM, $token, $m)) {
            $term = taxonomy_exists($m[1]) ? get_term_by('slug', $m[2], $m[1]) : false;
            if (! $term instanceof \WP_Term) {
                // the term comes with the item's own terms (written before the meta), so an unknown one is not an error
                return null;
            }

            return (string) $term->term_id;
        }

        return $token;
    }

    // ------------------------------------------------------------------ fingerprints

    /** The keys (meta and per-variation meta) a snapshot covers. */
    private function shapeOf(array $meta, array $variations): array
    {
        $keys = array_values(array_diff(array_keys($meta), array_diff(FieldMap::SKIP_META, ['_thumbnail_id'])));
        sort($keys);
        $vars = [];
        foreach ($variations as $v) {
            $vkeys = array_values(array_diff(array_keys($v['meta']), array_diff(FieldMap::SKIP_META, ['_thumbnail_id'])));
            sort($vkeys);
            $vars[$this->signature($v['meta'])] = $vkeys;
        }
        ksort($vars);

        return ['meta' => $keys, 'variations' => $vars];
    }

    private function snapshot(array $core, array $meta, array $variations): array
    {
        $shape = $this->shapeOf($meta, $variations);
        $vars = [];
        foreach ($variations as $v) {
            $sig = $this->signature($v['meta']);
            $vars[$sig] = ['status' => $v['status'], 'menu_order' => $v['menu_order'], 'meta' => array_intersect_key($v['meta'], array_flip($shape['variations'][$sig]))];
        }

        return ['core' => $core, 'meta' => array_intersect_key($meta, array_flip($shape['meta'])), 'variations' => $vars];
    }

    /** What this site holds now for the given shape, in the same form as snapshot(). */
    private function liveSnapshot(\WP_Post $post, array $shape): array
    {
        $meta = [];
        foreach ($shape['meta'] as $key) {
            $meta[$key] = $key === '_thumbnail_id' ? (string) get_post_thumbnail_id($post->ID) : get_post_meta($post->ID, $key, true);
        }
        $vars = [];
        $byPost = [];
        foreach ($this->variationPosts($post->ID) as $v) {
            $byPost[$this->signature(get_post_meta($v->ID))] = $v;
        }
        foreach ($shape['variations'] as $sig => $keys) {
            $v = $byPost[$sig] ?? null;
            if (! $v) {
                $vars[$sig] = null;

                continue;
            }
            $vmeta = [];
            foreach ($keys as $key) {
                $vmeta[$key] = $key === '_thumbnail_id' ? (string) get_post_thumbnail_id($v->ID) : get_post_meta($v->ID, $key, true);
            }
            $vars[$sig] = ['status' => $v->post_status, 'menu_order' => (int) $v->menu_order, 'meta' => $vmeta];
        }

        return FieldMap::normalize([
            'core' => ['title' => $post->post_title, 'excerpt' => $post->post_excerpt, 'content' => $post->post_content, 'status' => $post->post_status, 'menu_order' => (int) $post->menu_order],
            'meta' => $meta,
            'variations' => $vars,
        ]);
    }

    /** Whether the live item changed after the last import (or was never imported by a bundle with fingerprints). */
    private function isConflict(\WP_Post $post): bool
    {
        $stored = (string) get_post_meta($post->ID, '_charm_transfer_fp', true);
        if ($stored === '') {
            return true; // there is something here that no import wrote: do not overwrite it unasked
        }
        $shape = json_decode((string) get_post_meta($post->ID, '_charm_transfer_shape', true), true);
        if (! is_array($shape)) {
            // fingerprint of the first version of this tool: title, content and excerpt only
            return $stored !== md5($post->post_title."\0".$post->post_content."\0".$post->post_excerpt);
        }

        return $stored !== md5((string) wp_json_encode($this->liveSnapshot($post, $shape)));
    }

    /** Identifies a variation by its attribute values (`attribute_*` meta), the same on every site. */
    private function signature(array $meta): string
    {
        $attrs = [];
        foreach ($meta as $key => $value) {
            if (str_starts_with((string) $key, 'attribute_')) {
                $attrs[$key] = (string) (is_array($value) ? ($value[0] ?? '') : $value);
            }
        }
        ksort($attrs);

        return http_build_query($attrs);
    }

    /** @return \WP_Post[] */
    private function variationPosts(int $parentId): array
    {
        return get_posts(['post_type' => 'product_variation', 'post_parent' => $parentId, 'post_status' => ['publish', 'private', 'draft'], 'numberposts' => -1, 'orderby' => 'menu_order ID', 'order' => 'ASC']);
    }

    // ------------------------------------------------------------------ helpers

    private function find(string $type, string $slug): ?\WP_Post
    {
        $found = get_posts(['name' => $slug, 'post_type' => $type, 'post_status' => self::STATUSES, 'numberposts' => 1]);

        return $found[0] ?? null;
    }

    private function importer(): int
    {
        $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID']);

        return get_current_user_id() ?: (int) ($admins[0] ?? 1);
    }

    /**
     * An image that is already in the library but was not put there by an import (a seeder, a manual upload): same
     * file name and the same bytes. It is tagged with the key, so later imports find it directly.
     */
    private function adoptByFile(string $key, string $file, bool $apply): int
    {
        global $wpdb;

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND (meta_value = %s OR meta_value LIKE %s)",
            $file,
            '%/'.$wpdb->esc_like($file),
        ));
        foreach ($ids as $id) {
            $path = get_attached_file((int) $id);
            if ($path && is_file($path) && sha1_file($path) === $key) {
                if ($apply) {
                    update_post_meta((int) $id, '_charm_transfer_key', $key);
                }

                return (int) $id;
            }
        }

        return 0;
    }

    private function sideload(\ZipArchive $zip, string $key, array $m): int
    {
        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/media.php';
        require_once ABSPATH.'wp-admin/includes/image.php';

        $bytes = $zip->getFromName('files/'.$key.'-'.$m['file']);
        if ($bytes === false) {
            return 0;
        }
        $tmp = wp_tempnam($m['file']);
        file_put_contents($tmp, $bytes);
        $id = media_handle_sideload(['name' => $m['file'], 'tmp_name' => $tmp], 0, $m['title']);
        if (is_wp_error($id)) {
            @unlink($tmp);

            return 0;
        }
        update_post_meta($id, '_charm_transfer_key', $key);
        update_post_meta($id, '_wp_attachment_image_alt', $m['alt']);
        wp_update_post(['ID' => $id, 'post_excerpt' => $m['caption'], 'post_content' => $m['description']]);

        return (int) $id;
    }
}
