# Charm by WPMágus

Must-use plugin of the Charm sites. It replaces `milbar-muplugin` and `milbar-branding` and adds the basic site settings.

## Install

In a Bedrock project (the plugin is `type: wordpress-muplugin`, so Composer puts it into `web/app/mu-plugins/`):

```json
"repositories": [{ "type": "vcs", "url": "https://github.com/milbar/charm-by-wpmagus" }]
```

```bash
composer require milbar/charm-by-wpmagus
```

No Composer step at runtime: the plugin loads its own classes (`WPMagus\Charm\` => `src/`). Bedrock's autoloader picks it up from `mu-plugins/charm-by-wpmagus/`.

| Component | What it does |
|---|---|
| `Security` | XML-RPC off (filters + 403 on direct requests), pings off, no generator/RSD/WLW tags, no `X-Pingback` header |
| `Admin` | admin-bar offsets for fixed headers, fewer Site Health tests, admin footer with WP version, `WP_ENV` and (with a theme `revision.php`) build date, commit, release |
| `Content` | clean upload filenames, password-protected content open for editors |
| `Development` | no Yoast "search engines discouraged" notice outside production, WP Rocket does not write `WP_CACHE` |
| `Branding` | no WP logo in the admin bar, admin and login styles, the login screen shows the site logo and links home, "Made with ❤ by WPMágus" admin footer |
| `Credit` | the "website by WPMágus" logo link: `charm_credit()` and `[charm_credit]`, styled with CSS variables |
| `Transfer` | content export / import between environments (local to staging or production) without a database migration, see below |
| `Settings` | ACF options page **Settings > Charm**: logo and social profile URLs (Facebook, LinkedIn, Google Business Profile, Instagram, TikTok, Threads); feeds Yoast's organisation profiles |

## Template API

```php
charm_logo_id();         // attachment id: Settings > Charm, else the core Site Logo, else 0
charm_social_profiles(); // [['service' => 'facebook', 'label' => 'Facebook', 'url' => '...'], ...] filled-in ones only
```

```php
echo charm_credit();                                           // grey logo for a dark footer, 16 px
echo charm_credit(['surface' => 'light', 'height' => 20]);     // for a light background
echo charm_credit(['tone' => 'color', 'class' => 'my-credit']);
```

Guard calls in themes with `function_exists()`.

## The WPMágus credit

`charm_credit()` returns the "website by WPMágus" logo as a link to wpmagus.hu; `[charm_credit]` does the same in content. Both take the same options:

```
[charm_credit]
[charm_credit surface="light" height="20" tone="color"]
[charm_credit mark="1" height="28"]
```

| Option | Default | |
|---|---|---|
| `surface` | `dark` | background the logo sits on: `dark` (white "WP") or `light` (dark "WP") |
| `mark` | `false` | only the badge with the wizard hat |
| `tone` | `grey` | `grey`: desaturated, in colour on hover; `color`: always in colour |
| `height` | `16` | pixels |
| `class`, `style` | empty | extra classes and inline style on the link |
| `url`, `label` | wpmagus.hu, translated | link target and accessible name |

The look can be changed without touching the plugin: set CSS variables on the link or on any parent (`--charm-credit-height`, `--charm-credit-filter`, `--charm-credit-opacity`, `--charm-credit-hover-filter`, `--charm-credit-hover-opacity`), or use the filters `charm_credit_args`, `charm_credit_html` and `charm_credit_css` (return an empty string from the last one to drop the built-in CSS and style `.charm-credit` entirely in the theme).

```css
.footer .charm-credit { --charm-credit-opacity: .5; --charm-credit-hover-filter: none; }
```

## Content transfer

Moves finished content between environments: posts of any type (pages, posts, WooCommerce products with their variations, custom types), their terms, meta and featured image, and optionally the ACF options pages.

```bash
wp charm content export --type=product,faq,page --options --file=bundle.zip   # local: content.json + the original files
wp charm content import bundle.zip                                            # remote: dry run, lists what would change
wp charm content import bundle.zip --apply                                    # writes it (--force: also overwrite hand-edited items)
```

Also in the admin: Tools > Content transfer (upload the zip; leave "Apply" unchecked for a dry run). Upload the zip by SFTP and run the import over SSH for big bundles.

- Items are matched by post type and slug, product variations by their attribute values, so a re-run never duplicates.
- Images are matched by the SHA-1 of the file (a file already in the library with the same name and bytes is reused), so each is uploaded once. Ids of images, files, galleries, other posts and terms in content, in ACF fields (the field type is read from ACF itself, so any theme works) and in WooCommerce / Yoast meta are written as tokens and mapped back by hash and slug on the other side. Absolute site URLs are stored as `{{SITE}}`.
- An item that was edited on the target after the last import (its fingerprint covers title, excerpt, content, status and the meta of the bundle) is a conflict and is skipped unless `--force`. An item that no import has written yet counts as edited: the first import over existing content needs `--force`.
- Not carried: menus, plugin settings, WooCommerce attribute definitions (their terms are), users, comments, orders, ACF blocks' references to other posts (the export warns).
- Filters: `charm/transfer/default_types` (the types exported without `--type`, default `post, faq`), `charm/transfer/media_meta_keys`, `charm/transfer/post_meta_keys` (non-ACF meta that holds attachment / post ids).

The first version of this lived in the wpmagus theme (`Charm\Modules\ContentTransfer`). While a theme still ships that class the plugin stays out of the way; remove the theme module to switch over.

## Needs

ACF (Pro) for the settings page; without it the settings helpers return empty values and everything else keeps working.

## Translations

Text domain `charm-by-wpmagus`, catalogs in `languages/`. Generate with WP-CLI only (from the plugin folder; Hungarian files are named `charm-by-wpmagus-hu_HU.po/.mo`):

```bash
wp i18n make-pot . languages/charm-by-wpmagus.pot --slug=charm-by-wpmagus --domain=charm-by-wpmagus
wp i18n make-mo languages
```
