<?php

namespace WPMagus\Charm\Transfer;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Writes the content bundle: a zip with content.json (posts, terms, meta, product variations, ACF options) and the
 * original files of every image / file the content uses. Environment-specific ids are written as tokens (see FieldMap).
 */
class Exporter
{
    /** @var array<string, array> sha1 => attachment descriptor (with the local path) */
    private array $media = [];

    /** @var string[] */
    private array $warnings = [];

    /**
     * @param  int[]  $ids
     * @return array{items: int, media: int, options: int, warnings: string[]}
     */
    public function export(array $ids, string $zipPath, bool $withOptions = false): array
    {
        $this->media = [];
        $this->warnings = [];

        $items = [];
        foreach ($ids as $id) {
            $items[] = $this->item(get_post((int) $id));
        }
        $options = $withOptions ? $this->options() : [];

        $json = wp_json_encode([
            'format' => Transfer::FORMAT,
            'site' => home_url(),
            'items' => $items,
            'options' => $options,
            'media' => array_map(fn (array $m) => array_diff_key($m, ['path' => 1]), $this->media),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $json = str_replace(untrailingslashit(home_url()), '{{SITE}}', (string) $json);

        $zip = new \ZipArchive;
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Cannot write '.$zipPath);
        }
        $zip->addFromString('content.json', $json);
        foreach ($this->media as $key => $m) {
            $zip->addFile($m['path'], 'files/'.$key.'-'.$m['file']);
        }
        $zip->close();

        return ['items' => count($items), 'media' => count($this->media), 'options' => count($options), 'warnings' => $this->warnings];
    }

    private function item(\WP_Post $post): array
    {
        $id = $post->ID;
        $item = [
            'type' => $post->post_type,
            'slug' => $post->post_name,
            'status' => $post->post_status,
            'title' => $post->post_title,
            'excerpt' => $post->post_excerpt,
            'content' => $this->encodeContent($post->post_content),
            'date' => $post->post_date,
            'date_gmt' => $post->post_date_gmt,
            'menu_order' => (int) $post->menu_order,
            'terms' => $this->terms($id, $post->post_type),
            'meta' => $this->meta($id),
            'thumbnail' => ($thumb = (int) get_post_thumbnail_id($id)) ? $this->addMedia($thumb) : null,
        ];

        if ($post->post_type === 'product') {
            $item['variations'] = $this->variations($id);
        }
        if (str_contains($post->post_content, '<!-- wp:acf/')) {
            $this->warnings[] = sprintf('%s/%s has ACF blocks: ids of other posts stored in them are not remapped.', $post->post_type, $post->post_name);
        }

        return $item;
    }

    /** Terms with their ancestors, root first, so the import can rebuild the hierarchy by slug. */
    private function terms(int $id, string $postType): array
    {
        $out = [];
        foreach (get_object_taxonomies($postType) as $tax) {
            foreach ((array) wp_get_object_terms($id, $tax) as $term) {
                $path = [[$term->slug, $term->name]];
                foreach (get_ancestors($term->term_id, $tax, 'taxonomy') as $parentId) {
                    if (($parent = get_term($parentId, $tax)) instanceof \WP_Term) {
                        array_unshift($path, [$parent->slug, $parent->name]);
                    }
                }
                $out[$tax][] = ['path' => $path];
            }
        }

        return $out;
    }

    /** All meta of a post with the environment-specific ids written as tokens. */
    private function meta(int $id): array
    {
        $all = get_post_meta($id);
        $meta = [];
        foreach ($all as $key => $values) {
            if (in_array($key, FieldMap::SKIP_META, true)) {
                continue;
            }
            $companion = isset($all['_'.$key][0]) ? (string) $all['_'.$key][0] : null;
            $meta[$key] = $this->encode(maybe_unserialize($values[0]), FieldMap::kind($key, $companion));
        }

        return $meta;
    }

    /** Variations of a variable product, matched on import by their attribute values. */
    private function variations(int $parentId): array
    {
        $out = [];
        $children = get_posts(['post_type' => 'product_variation', 'post_parent' => $parentId, 'post_status' => ['publish', 'private'], 'numberposts' => -1, 'orderby' => 'menu_order ID', 'order' => 'ASC']);
        foreach ($children as $child) {
            $out[] = [
                'status' => $child->post_status,
                'title' => $child->post_title,
                'menu_order' => (int) $child->menu_order,
                'meta' => $this->meta($child->ID),
                'thumbnail' => ($thumb = (int) get_post_thumbnail_id($child->ID)) ? $this->addMedia($thumb) : null,
            ];
        }

        return $out;
    }

    /** The ACF options pages' values (`options_<field>` rows that have an ACF field key next to them). */
    private function options(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'options\_%' OR option_name LIKE '\_options\_%'", ARRAY_A);
        $names = array_column($rows, 'option_value', 'option_name');

        $out = [];
        foreach ($names as $name => $value) {
            if (! str_starts_with($name, 'options_')) {
                continue;
            }
            $fieldKey = $names['_'.$name] ?? null;
            if (! is_string($fieldKey) || ! str_starts_with($fieldKey, 'field_')) {
                continue;
            }
            $out[$name] = ['key' => $fieldKey, 'value' => $this->encode(maybe_unserialize($value), FieldMap::kind(substr($name, 8), $fieldKey))];
        }
        ksort($out);

        return $out;
    }

    /** Replaces the ids in a value (or in every element of an array) by tokens. */
    private function encode(mixed $value, ?array $kind): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($v) => $this->encode($v, $kind), $value);
        }
        if ($kind === null || $value === '' || $value === null || $value === false || (string) $value === '0') {
            return $value; // an empty field ("0" is how ACF stores "no image / no post")
        }

        [$type, $tax] = $kind;

        return match ($type) {
            FieldMap::MEDIA => ($key = $this->addMedia((int) $value)) ? '{{MEDIA-ID:'.$key.'}}' : '',
            FieldMap::MEDIA_CSV => implode(',', array_filter(array_map(fn ($v) => ($key = $this->addMedia((int) $v)) ? '{{MEDIA-ID:'.$key.'}}' : '', explode(',', (string) $value)))),
            FieldMap::POST => $this->postToken((int) $value),
            FieldMap::TERM => $this->termToken((int) $value, (string) $tax),
            default => $value,
        };
    }

    private function postToken(int $id): string
    {
        $post = $id ? get_post($id) : null;
        if (! $post) {
            return '';
        }

        return '{{POST:'.$post->post_type.'/'.$post->post_name.'}}';
    }

    private function termToken(int $id, string $taxonomy): string
    {
        $term = $id && $taxonomy ? get_term($id, $taxonomy) : null;

        return $term instanceof \WP_Term ? '{{TERM:'.$taxonomy.'/'.$term->slug.'}}' : '';
    }

    /** Image ids and URLs inside the post content. */
    private function encodeContent(string $content): string
    {
        $content = preg_replace_callback('/<img\b[^>]*>/i', function (array $m) {
            if (! preg_match('/wp-image-(\d+)/', $m[0], $idm)) {
                return $m[0];
            }
            $key = $this->addMedia((int) $idm[1]);

            return $key ? preg_replace('/\ssrc="[^"]*"/', ' src="{{MEDIA:'.$key.'}}"', $m[0]) : $m[0];
        }, $content);

        return preg_replace_callback('/(<!-- wp:image \{[^}]*?"id":|wp-image-)(\d+)/', function (array $m) {
            $key = $this->addMedia((int) $m[2]);

            return $m[1].($key ? '{{MEDIA-ID:'.$key.'}}' : $m[2]);
        }, $content);
    }

    /** Registers an attachment in the bundle, returns its key (sha1 of the file). */
    private function addMedia(int $id): ?string
    {
        if (! $id) {
            return null;
        }
        $path = get_attached_file($id);
        if (! $path || ! is_file($path)) {
            $this->warnings[] = 'Attachment '.$id.' has no file, skipped.';

            return null;
        }
        $key = sha1_file($path);
        $this->media[$key] ??= [
            'file' => basename($path),
            'path' => $path,
            'title' => get_the_title($id),
            'alt' => (string) get_post_meta($id, '_wp_attachment_image_alt', true),
            'caption' => wp_get_attachment_caption($id) ?: '',
            'description' => (string) get_post_field('post_content', $id),
            'old_id' => $id,
            'mime' => get_post_mime_type($id),
        ];

        return $key;
    }
}
