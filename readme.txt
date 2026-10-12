=== Purge Sync for Bunny and LiteSpeed ===
Contributors: paramedicspecialist
Tags: bunny, litespeed, cache, purge
Requires at least: 6.8
Tested up to: 7.1.3
Requires PHP: 7.4
Stable tag: 1.1.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Clears Bunny when LiteSpeed Cache clears a page or the whole site.

== Description ==

Bunny sits in front of the site address, the same way a reverse proxy does. LiteSpeed Cache clears its own copy with a header the web server consumes. Bunny never sees that header, so visitors can still receive the previous page.

Purge Sync for Bunny and LiteSpeed listens for those clears and asks Bunny to drop the same address.

* A normal edit purges the LiteSpeed tags for that page, including the homepage and archives that include it.
* Purge This Page, and a URL typed into LiteSpeed, also purge that exact public address.
* Elementor CSS files, replaced media, and LiteSpeed's optimized CSS and JavaScript are purged by URL.
* A published Elementor header, footer, kit, or other sitewide template purges the whole pull zone. LiteSpeed is cleared first, so Bunny does not fetch a stale copy.
* The Customizer and a finished plugin or theme update can do the same. Background update checks do not.
* The API key is encrypted in the database. Bunny needs the original key on every purge, so a one-way hash cannot be stored instead.

The plugin does not rewrite URLs and can sit beside the official bunny.net plugin. It does not change pull-zone settings.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate Purge Sync for Bunny and LiteSpeed.
3. Open Settings → Purge Sync.
4. Enter the Pull Zone ID and API Key, then save.

Deleting the plugin removes its settings, encrypted API key, purge log, and temporary records. Deactivating it leaves those in place.

If the official bunny.net plugin already stored a key, use Import Key from bunny.net. That copies the key into an encrypted option and leaves the original in place.

Optional `wp-config.php` constants:

* `BUNNY_LITESPEED_API_KEY` supplies the key and disables the field.
* `BUNNY_LITESPEED_SECRET` is the encryption secret. When it is absent, the plugin uses the WordPress auth salt.

== Bunny Dashboard ==

Tag purges do not apply while Perma-Cache is on.

Bypass the cache for `/wp-admin`, `/wp-login.php`, cart, checkout, and account pages, and when a `wordpress_logged_in_*`, `wp-postpass_*`, or `comment_author_*` cookie is present. Do not vary the cache on those cookies.

A long browser cache time can keep a page after both caches have been cleared. This plugin cannot remove a copy the browser already stored.

== Frequently Asked Questions ==

= Why is the key encrypted instead of hashed? =

A hash cannot be turned back into the key. Every purge sends the original key to Bunny.

= Does a trailing slash clear more than that page? =

No. A page purge sends exactPath, so an address that ends with a slash clears that address. Bunny documents that flag for pull zones that keep query strings in the cache key. A directory purge still uses * and counts as a prefix purge.

= How many purges can run at once? =

Bunny's default burst is 120 exact purges and 20 prefix purges. An exact purge is one tag or one address. A prefix purge is a wildcard or the whole pull zone. A larger batch becomes one full pull zone purge when a prefix call is still available.

= Does a post edit clear the whole site? =

No. The whole pull zone is cleared when LiteSpeed itself sends a purge-all tag, for sitewide Elementor templates, for the Customizer, for a real plugin or theme update, and when one edit would otherwise send more than 40 tag purges. LiteSpeed's "All pages" auto-purge rule is a purge-all tag.

= What is stored in the log? =

The newest 50 rows: time, kind, trigger, count, HTTP status, and a short reason or path. The API key and the Bunny response body are not stored.

== Upgrade Notice ==

= 1.1.1 =
Page purges now clear that exact address, including addresses that end with a slash.

= 1.1.0 =
Renamed from Bunny Sync for LiteSpeed. Activating this plugin retires the old one and keeps its settings.

= 1.0.1 =
Page tags now reach Bunny. After updating, run LiteSpeed Cache → Purge All once so cached pages are rebuilt with tags.

== Changelog ==

= 1.1.1 =
* A page address that ends with a slash is purged as that exact page. Bunny no longer treats it as every address under that path, which on the homepage meant the whole site.
* The site port is kept when WordPress is not served on port 80 or 443.
* A failed connection to Bunny is reported as a failed purge.
* Purge limits follow Bunny's separate exact and prefix limits.
* A full pull zone purge sends an empty JSON body, which is a purge without a cache tag.
* Licence is now GPLv3 or later.

= 1.1.0 =
* Renamed to Purge Sync for Bunny and LiteSpeed. The folder, text domain, and settings page are now purge-sync-for-bunny-litespeed.
* Activating this plugin deactivates Bunny Sync for LiteSpeed 1.0.x and removes its folder. Settings, the encrypted API key, and the purge log are kept, including when the old plugin is deleted from the Plugins screen.

= 1.0.1 =
* Fixed: the CDN-Tag header was never sent because LiteSpeed adds its cache headers after WordPress's send_headers hook. Tag purges matched nothing at Bunny.
* Fixed: a new media upload no longer purges Bunny for files it has never cached.
* Fixed: many versioned file URLs in one folder now collapse into one folder purge.
* Text domain and settings page slug now match the plugin folder, bunny-sync-for-litespeed.

= 1.0.0 =
* First release.
