<?php

namespace WPMagus\Charm\Transfer;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Knows which meta values hold ids that differ between environments (attachments, other posts, terms) and how to
 * write them as environment-neutral tokens:
 *
 *   {{MEDIA-ID:<sha1 of the file>}}   an attachment
 *   {{POST:<post type>/<slug>}}       another post
 *   {{TERM:<taxonomy>/<slug>}}        a term
 *
 * The type of a value is read from ACF itself: ACF stores the key of every field next to its value (`_<name>` =
 * `field_...`), so image / file / gallery / post object / relationship / page link / taxonomy fields of any theme are
 * recognised without the plugin knowing the theme. A few well-known non-ACF keys (Yoast, WooCommerce) are listed too.
 */
final class FieldMap
{
    public const MEDIA = 'media';
    public const MEDIA_CSV = 'media_csv';
    public const POST = 'post';
    public const TERM = 'term';

    public const TOKEN_MEDIA = '/\{\{MEDIA-ID:([0-9a-f]{40})\}\}/';
    public const TOKEN_POST = '/\{\{POST:([a-z0-9_\-]+)\/([^}]+)\}\}/';
    public const TOKEN_TERM = '/\{\{TERM:([a-z0-9_\-]+)\/([^}]+)\}\}/';

    /** Meta that is environment-specific, a counter, or rebuilt on import. */
    public const SKIP_META = [
        '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_thumbnail_id', '_wp_trash_meta_status', '_wp_trash_meta_time',
        '_charm_transfer_fp', '_charm_transfer_shape', '_yoast_wpseo_opengraph-image', '_yoast_wpseo_twitter-image',
        'total_sales', '_wc_average_rating', '_wc_review_count', '_wc_rating_count', '_product_version',
    ];

    /** @var array<string, array|null> */
    private static array $fields = [];

    /**
     * What kind of ids the meta value holds.
     *
     * @param  string|null  $fieldKey  the ACF field key stored next to the value (`field_...`), if any
     * @return array{0: string, 1: string|null}|null  [kind, taxonomy for TERM]
     */
    public static function kind(string $key, ?string $fieldKey): ?array
    {
        if ($fieldKey !== null && str_starts_with($fieldKey, 'field_') && ($field = self::field($fieldKey))) {
            return match ($field['type'] ?? '') {
                'image', 'file', 'gallery' => [self::MEDIA, null],
                'post_object', 'relationship', 'page_link' => [self::POST, null],
                'taxonomy' => [self::TERM, (string) ($field['taxonomy'] ?? '')],
                default => null,
            };
        }

        $media = (array) apply_filters('charm/transfer/media_meta_keys', ['_yoast_wpseo_opengraph-image-id', '_yoast_wpseo_twitter-image-id', '_wpm_images']);
        $posts = (array) apply_filters('charm/transfer/post_meta_keys', ['_upsell_ids', '_crosssell_ids', '_children']);

        if (in_array($key, $media, true)) {
            return [self::MEDIA, null];
        }
        if ($key === '_product_image_gallery') {
            return [self::MEDIA_CSV, null];
        }
        if (in_array($key, $posts, true)) {
            return [self::POST, null];
        }
        if (preg_match('/^_yoast_wpseo_primary_(.+)$/', $key, $m)) {
            return [self::TERM, $m[1]];
        }

        return null;
    }

    /** Whether the ACF field is a repeater / flexible content (its rows are stored as `<name>_<n>_<sub>`). */
    public static function isRepeater(?string $fieldKey): bool
    {
        if ($fieldKey === null || ! str_starts_with($fieldKey, 'field_') || ! ($field = self::field($fieldKey))) {
            return false;
        }

        return in_array($field['type'] ?? '', ['repeater', 'flexible_content'], true);
    }

    private static function field(string $fieldKey): ?array
    {
        if (! function_exists('acf_get_field')) {
            return null;
        }

        return self::$fields[$fieldKey] ??= (acf_get_field($fieldKey) ?: null);
    }

    /** Canonical form for fingerprints: every scalar a string, keys sorted, values unserialized. */
    public static function normalize(mixed $value): mixed
    {
        // never instantiate objects: the values come from a bundle that may not be trusted
        if (is_string($value) && is_serialized($value)) {
            $value = @unserialize(trim($value), ['allowed_classes' => false]);
        }
        if (is_array($value)) {
            $value = array_map([self::class, 'normalize'], $value);
            ksort($value);

            return $value;
        }

        return is_scalar($value) || $value === null ? (string) $value : $value;
    }
}
