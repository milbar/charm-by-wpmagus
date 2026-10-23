# Charm by WPMágus

Must-use plugin of the Charm sites. It replaces `milbar-muplugin` and `milbar-branding` and adds the basic site settings.

## Install

In a Bedrock project (the plugin is `type: wordpress-muplugin`, so Composer puts it into `web/app/mu-plugins/`):

```json
"repositories": [{ "type": "vcs", "url": "https://github.com/milbareu/charm-by-wpmagus" }]
```

```bash
composer require milbareu/charm-by-wpmagus
```

No Composer step at runtime: the plugin loads its own classes (`WPMagus\Charm\` => `src/`). Bedrock's autoloader picks it up from `mu-plugins/charm-by-wpmagus/`.

| Component | What it does |
|---|---|
| `Security` | XML-RPC off (filters + 403 on direct requests), pings off, no generator/RSD/WLW tags, no `X-Pingback` header |
| `Admin` | admin-bar offsets for fixed headers, fewer Site Health tests, admin footer with WP version, `WP_ENV` and (with a theme `revision.php`) build date, commit, release |
| `Content` | clean upload filenames, password-protected content open for editors |
| `Development` | no Yoast "search engines discouraged" notice outside production, WP Rocket does not write `WP_CACHE` |
| `Branding` | no WP logo in the admin bar, admin and login styles, the login screen shows the site logo and links home, "Made with ❤ by WPMágus" admin footer |
| `Settings` | ACF options page **Settings > Charm**: logo and social profile URLs (Facebook, LinkedIn, Google Business Profile, Instagram, TikTok, Threads); feeds Yoast's organisation profiles |

## Template API

```php
charm_logo_id();         // attachment id: Settings > Charm, else the core Site Logo, else 0
charm_social_profiles(); // [['service' => 'facebook', 'label' => 'Facebook', 'url' => '...'], ...] filled-in ones only
```

Guard calls in themes with `function_exists()`.

## Needs

ACF (Pro) for the settings page; without it the settings helpers return empty values and everything else keeps working.

## Translations

Text domain `charm-by-wpmagus`, catalogs in `languages/`. Generate with WP-CLI only (from the plugin folder; Hungarian files are named `charm-by-wpmagus-hu_HU.po/.mo`):

```bash
wp i18n make-pot . languages/charm-by-wpmagus.pot --slug=charm-by-wpmagus --domain=charm-by-wpmagus
wp i18n make-mo languages
```
