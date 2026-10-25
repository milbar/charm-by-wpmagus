<?php

namespace WPMagus\Charm\Credit;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The "website by WPMágus" credit: the WPMágus logo as a link to wpmagus.hu, for a footer or any other place.
 *
 * Use it from a template with `echo charm_credit();` or in content with `[charm_credit]`. Both take the same
 * options (see {@see self::defaults()}). The look is meant to be changed:
 *
 *  - options: `surface` (dark|light background), `mark` (only the badge), `tone` (grey|color), `height`, `class`, `style`
 *  - CSS variables on the link or any parent: `--charm-credit-height`, `--charm-credit-filter`, `--charm-credit-opacity`,
 *    `--charm-credit-hover-filter`, `--charm-credit-hover-opacity`
 *  - filters: `charm_credit_args`, `charm_credit_html`, `charm_credit_css` (return '' to style it entirely in the theme)
 */
class Credit
{
    public const URL = 'https://wpmagus.hu';

    /** width / height of the wordmark (viewBox of the logo files) */
    private const WORDMARK_RATIO = 7.9748;

    private static bool $cssPrinted = false;

    public static function init(): void
    {
        add_shortcode('charm_credit', [self::class, 'shortcode']);
    }

    /** @return array{surface: string, mark: bool, tone: string, height: int, class: string, style: string, url: string, label: string} */
    public static function defaults(): array
    {
        return [
            // The background the logo sits on: 'dark' (white "WP", for footers) or 'light' (dark "WP").
            'surface' => 'dark',
            // Only the badge with the wizard hat, no wordmark.
            'mark' => false,
            // 'grey': desaturated until hover, like footer links. 'color': always in its own colours.
            'tone' => 'grey',
            // Height of the logo in pixels.
            'height' => 16,
            // Extra classes on the link.
            'class' => '',
            // Extra inline style on the link (CSS variables included), filtered through safecss_filter_attr().
            'style' => '',
            'url' => self::URL,
            // Accessible name; empty = the default, translated text.
            'label' => '',
        ];
    }

    /** The credit link as HTML. */
    public static function render(array $args = []): string
    {
        /** @var array<string, mixed> $args */
        $args = apply_filters('charm_credit_args', array_merge(self::defaults(), $args));

        $mark = filter_var($args['mark'], FILTER_VALIDATE_BOOLEAN);
        $tone = $args['tone'] === 'color' ? 'color' : 'grey';
        $height = max(8, (int) $args['height']);
        $file = $mark ? 'logo-mark.svg' : ($args['surface'] === 'light' ? 'wpmagus-logo.svg' : 'wpmagus-logo-dark.svg');
        $width = $mark ? $height : (int) round($height * self::WORDMARK_RATIO);

        $label = $args['label'] !== '' ? (string) $args['label'] : __('Website by WPMágus (opens in a new tab)', 'charm-by-wpmagus');
        $style = trim('--charm-credit-height:'.$height.'px;'.safecss_filter_attr((string) $args['style']));

        $classes = array_filter(['charm-credit', 'charm-credit--'.$tone, $mark ? 'charm-credit--mark' : '', (string) $args['class']]);

        $html = sprintf(
            '<a class="%1$s" href="%2$s" target="_blank" rel="noopener" aria-label="%3$s" style="%4$s"><img src="%5$s" alt="WPMágus" width="%6$d" height="%7$d" loading="lazy" decoding="async"></a>',
            esc_attr(implode(' ', array_map('sanitize_html_class', explode(' ', implode(' ', $classes))))),
            esc_url((string) $args['url']),
            esc_attr($label),
            esc_attr($style),
            esc_url(CHARM_BY_WPMAGUS_URL.'assets/img/'.$file),
            $width,
            $height,
        );

        return (string) apply_filters('charm_credit_html', self::css().$html, $args);
    }

    /** [charm_credit surface="light" height="20" tone="color" class="my-class" mark="1"] */
    public static function shortcode($atts): string
    {
        return self::render(shortcode_atts(self::defaults(), (array) $atts, 'charm_credit'));
    }

    /** The default look, printed once before the first credit; the CSS variables are the way to adjust it. */
    private static function css(): string
    {
        if (self::$cssPrinted) {
            return '';
        }
        self::$cssPrinted = true;

        $css = '.charm-credit{display:inline-flex;align-items:center;line-height:0;color:inherit;text-decoration:none}'
            .'.charm-credit img{display:block;width:auto;height:var(--charm-credit-height,16px);filter:var(--charm-credit-filter,grayscale(1) brightness(1.15));opacity:var(--charm-credit-opacity,.6);transition:filter .2s ease,opacity .2s ease}'
            .'.charm-credit:hover img,.charm-credit:focus-visible img{filter:var(--charm-credit-hover-filter,none);opacity:var(--charm-credit-hover-opacity,1)}'
            .'.charm-credit--color img{filter:none;opacity:1}';

        $css = (string) apply_filters('charm_credit_css', $css);

        return $css !== '' ? '<style id="charm-credit-css">'.$css.'</style>' : '';
    }
}
