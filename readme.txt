=== Saayeh Demo Importer ===
Contributors: sahandfarshad
Tags: demo import, one click demo, woocommerce, rtl, persian
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

One-click, resumable demo content importer for Saayeh themes (Persian/RTL UI): WooCommerce variable products, pages, posts, menus, coupons, shipping and theme settings — with full removal.

== Description ==

درون‌ریز دمو سایه محتوای نمایشی پوسته‌های «سایه» را با یک کلیک درون‌ریزی می‌کند و هر زمان خواستید همه‌ی آن را کامل حذف می‌کند.

Saayeh Demo Importer imports the demo content shipped inside a theme (declared with the `Saayeh Demo Package` theme header) step by step over AJAX, with a progress bar, live log, retry and resume.

* Detects every installed theme with a `Saayeh Demo Package: demo/demo.json` header, plus known themes that are not installed yet.
* Imports images, global product attributes (with color swatch hex), product categories (with thumbnails), simple and variable products with variations, sale prices, out-of-stock rules, featured flag and badges, pages (front/blog/WooCommerce pages), posts, menus assigned to theme locations, coupons, a shipping zone with methods, theme mods and options.
* Batched requests to avoid timeouts on shared hosting; state is stored so an interrupted import can be resumed.
* Idempotent: running it again never duplicates content.
* Backs up the front page, WooCommerce page and menu location settings (and every option/theme mod the demo touches) and restores them when you remove the demo.
* "Remove demo content" deletes only what the importer created: posts, products and variations, attachments (files included), terms, menus, coupons, the shipping zone and created attributes.
* Extensible with the `sdi_import_steps`, `sdi_before_step` and `sdi_after_step` hooks and more.
* Security: `manage_options` capability and nonces on every request, files read only from inside the theme directory, protected core options can never be changed by a demo package.
* No external requests, no tracking.

The admin interface is in Persian (fa_IR) and right-to-left.

== Installation ==

1. Upload the plugin zip via Plugins → Add New → Upload Plugin, then activate it.
2. Install and activate WooCommerce (required by demo packages that list it in `requires`).
3. Activate a theme that ships a demo package, e.g. Saayeh.
4. Go to Appearance → درون‌ریزی دمو and press «درون‌ریزی دمو».

== Frequently Asked Questions ==

= How does a theme declare a demo package? =

Add `Saayeh Demo Package: demo/demo.json` to the theme's style.css header and ship the demo.json (schema version 1) and its images inside the theme. See README.md for the full schema reference.

= Will removing the demo delete my own content? =

No. Only objects flagged by the importer (meta `_sdi_demo`) are deleted. Existing terms or attributes that the demo reused are left untouched.

= Does it work with child themes? =

Yes. When a child theme without its own package is active, the parent theme's package is used.

= What happens on uninstall? =

The plugin's own options are removed. Imported demo content is kept; remove it from the importer screen first if you do not want it.

== Screenshots ==

1. Theme cards with status, demo summary and import options.
2. Import progress with live log.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
