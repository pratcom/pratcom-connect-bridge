=== Pratcom Connect ===
Contributors: pratcommedia
Tags: consent, privacy, cookies, forms, chatbot
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 2.1.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free, 100% local cookie consent banner compliant with Quebec's Law 25. Optional paid modules: smart forms and AI chat.

== Description ==

**Pratcom Connect** adds a cookie consent banner to your WordPress site, designed for compliance with Quebec's Law 25 — free, with no account required and no calls to any external server.

The plugin interface and the consent banner are fully bilingual (French and English).

= Free (no account required) =

* **Law 25 consent banner**: cookie categories (necessary, analytics, marketing), editable FR/EN texts, design adaptable to your brand (primary color + rich palette, WCAG contrast computed automatically).
* **Presets for popular plugins**: check the tools you use (Google Analytics 4, Meta Pixel, Hotjar, and more) — the cookie declaration and blocking rules configure themselves. Automatic suggestions based on the plugins active on your site (local detection, no data transmitted).
* **Google Consent Mode v2 (optional)**: when enabled, the banner emits Google's consent signals (analytics_storage, ad_storage, ad_user_data, ad_personalization) so Google Analytics 4 and Google Ads respect the visitor's choice — denied by default until consent. Off by default; no effect unless you use Google tags.
* **Legal pages**: a privacy policy and a standalone cookie declaration, both bilingual and generated automatically. They are created on activation and kept up to date from your presets, your manual list and a local scan. Shortcodes: [pratcom_privacy_policy] and [pratcom_cookie_declaration].
* **Local consent registry**: proof of consent stored in your WordPress database, with CSV export.

= Paid modules (require a Pratcom Connect account) =

* **Privacy Connect**: automatic scan of your site's cookies and trackers.
* **Connect Forms**: smart forms with lead scoring and routing.
* **Connect Chat**: multilingual AI chatbot, available 24/7, trained on your content.

Paid modules are provided by the Pratcom Connect service and are enabled by connecting your account (API key). Without an account, the plugin remains fully functional for Law 25 consent.

= External service (disclosure) =

In the free version, **no data is sent to any external server**: the banner, presets, legal pages and registry run entirely on your site.

If you connect a Pratcom Connect account (a voluntary action), the plugin communicates with the **api.connect.pratcom.net** service (operated by Pratcom Media, Canada):

* **When**: when connecting your account (handshake), during scheduled status checks, and when saving your brand colors.
* **What**: your API key, the site domain, module status and brand palette. Active modules (forms, chat) transmit data submitted by your visitors to the service for processing.
* **Terms**: [Privacy Policy](https://connect.pratcom.net/confidentialite) - [Terms of Use](https://connect.pratcom.net/conditions)

== Frequently Asked Questions ==

= Is the plugin really free? =

Yes. The Law 25 consent banner, presets, legal pages and local registry are free, with no account and no time limit. Paid modules are optional.

= Does my data leave my site in the free version? =

No. No external call is made as long as you do not connect a Pratcom Connect account.

= Is the banner compliant with Quebec's Law 25? =

The plugin provides the required mechanisms (prior consent per category, easy withdrawal, proof registry). Overall compliance also depends on your own practices (privacy policy, designated privacy officer).

= Is the plugin bilingual? =

Yes, French and English (banner texts are editable in both languages).

= How do I remove the "Powered by Pratcom Connect" attribution? =

The consent banner shows a small "Powered by Pratcom Connect" link by default (an attribution notice). You can remove it at any time: uncheck **"Show the Powered by Pratcom Connect badge"** in the Privacy tab. Developers can also remove it programmatically with the `pratcom_connect_branding` filter:

`add_filter( 'pratcom_connect_branding', '__return_false' );`

= How do I enable the Forms and Chat modules? =

Create an account at connect.pratcom.net, choose your modules, then paste your API key in the plugin's Account tab.

== Screenshots ==

1. Dashboard — connection overview: workspace, active modules and last handshake.
2. Privacy tab — free Law 25 consent banner with popular plugin presets (GA4, Meta Pixel, WooCommerce and more), local consent registry and CSV export.
3. Forms tab — your Connect Forms with copyable shortcodes.
4. Appearance — brand palette with automatic WCAG-compliant contrast, shared by all modules.
5. Modules — Connect Chat, Forms and Privacy at a glance (activate them with your Pratcom Connect account).
6. Front-end — the consent banner live on a website, in the visitor's language.
7. Chat tab — train your Connect Chat assistant directly from wp-admin (mirror of the Connect training dashboard).

== Changelog ==

= 2.1.4 =
* Google Consent Mode v2 (optional, off by default): the consent banner can now emit Google's consent signals so GA4 and Google Ads respect the visitor's choice (denied by default until consent, Law 25 opt-in). The default signal is printed inline before Google Tag Manager; privacy.js sends the update on each choice. Enable with the `pratcom_connect_consent_mode` filter/option.

= 2.1.2 =
* Admin interface fully translatable; bundled FR/EN translations.

= 2.1.1 =
* Boutons « Découvrir » des modules pointant vers les pages dédiées de chaque module (Chat, Forms, Privacy).

= 2.1.0 =
* Bannière Privacy Free 100 % autonome : le script de la bannière est désormais embarqué dans le plugin (aucun appel externe).
* Pages légales (politique de confidentialité et déclaration de témoins) créées automatiquement dans les deux langues sur les sites WPML ou Polylang.
* Interface d'administration revue : orthographe, nouveaux liens (Découvrir un module, Obtenir votre clé, Support, Documentation), et vitrines animées qui présentent chaque module verrouillé.

= 2.0.11 =
* Cookie scan ignores WordPress admin/auth cookies; public cookie declaration only lists classified cookies.
* The consent cookie (pratcom_consent) is now declared as a necessary cookie instead of unclassified; the "Powered by Pratcom Connect" badge links to pratcom.net/connect.

= 2.0.10 =
* Local legal pages: standalone cookie declaration (Cookiebot-style, grouped by category) plus dynamic cookie table (presets / local scan / manual entries) in the privacy policy, with both pages auto-created on activation. New shortcode [pratcom_cookie_declaration]. New company-info and manual-cookie editors in the Privacy tab. 100% local, no server calls in the free version.

= 2.0.9 =
* Renamed two localized JS globals to the pratcom prefix (WP.org review).

= 2.0.7 =
* WordPress.org build: the Chat, Forms and Privacy admin tabs no longer embed the Connect dashboard in an iframe. They now show your connection status and a direct link to manage each module in your dashboard (WordPress.org review feedback).
* Build: the .wordpress-org assets directory is no longer bundled in the distributed plugin zip.

= 2.0.6 =
* Documented phpcs annotation on the core 'the_content' filter call in the site-scan REST controller (Plugin Check false positive — no functional change).

= 2.0.5 =
* All admin JavaScript now loads through wp_enqueue_script / wp_localize_script — no more inline script tags or inline event attributes (WordPress.org review feedback).

= 2.0.4 =
* "Powered by Pratcom Connect" badge in the free consent banner — enabled by default, can be turned off with a checkbox in the Privacy tab or the documented `pratcom_connect_branding` filter (see FAQ).
* New "Attribution Notice" FAQ entry documenting badge removal options.

= 2.0.3 =
* Privacy: 14 new documented presets (Wordfence, Polylang, WordPress comments, Smash Balloon, wpDiscuz, TranslatePress, Stripe, PayPal, LiveChat, Tawk.to, Crisp, OptinMonster, Spotify, Pinterest pixel) — 34 total.
* Privacy: presets now grouped by origin — "Popular WordPress plugins" vs "External services and scripts".
* Modules tab: "Open" button on every active module.
* New read-only REST route exposing published pages to the Connect Chat site-scan training feature (pck_ key required).

= 2.0.2 =
* New Chat tab: train your assistant directly from wp-admin (mirror of the Connect CRM training dashboard).
* Forms tab: edit your forms in the Connect builder without leaving WordPress.
* Privacy tab: connected Privacy Connect section for sites with the paid module.
* Loading states on all action buttons (anti double-click).
* Full brand palette pushed to the Connect service on handshake.
* Fix: mirror iframe URLs now always use the public service domain.

= 2.0.1 =
* Plugin Check compliance pass: escaped front-end script output, WP script tag helpers, input sanitization hardening, translators comments, prepared SQL identifier in CSV export.
* readme.txt rewritten in English (WordPress.org guidelines).

= 2.0.0 =
* First public release.
* Free, 100% local Law 25 consent banner.
* Popular plugin presets + local registry + CSV export.
* Optional connection to the Pratcom Connect service (paid modules).

== Upgrade Notice ==

= 2.1.4 =
Optional Google Consent Mode v2 for GA4 and Google Ads (off by default, Law 25 opt-in). No change unless you enable it.

= 2.1.2 =
Admin interface fully translatable; bundled FR/EN translations.

= 2.1.1 =
Boutons « Découvrir » des modules pointant désormais vers la page dédiée de chaque module.

= 2.1.0 =
Bannière Privacy Free entièrement autonome (script embarqué, aucun appel externe), pages légales bilingues créées automatiquement et interface d'administration revue.

= 2.0.11 =
The local cookie scan now ignores WordPress admin and authentication cookies, and the public cookie declaration lists only classified cookies. Unclassified cookies stay visible in the admin for you to classify. No change to paid modules.

= 2.0.10 =
New local legal pages: a standalone cookie declaration and a dynamic cookie table fed by your presets, a local scan and manual entries. Both pages are created automatically on activation. No change to paid modules.

= 2.0.9 =
Internal rename of two admin JavaScript globals to satisfy WordPress.org naming guidelines. No functional change.

= 2.0.7 =
WordPress.org build: admin module tabs link out to your dashboard instead of embedding it. No change to the consent banner.

= 2.0.4 =
Optional "Powered by" badge in the free banner (on by default, removable via checkbox or filter).

= 2.0.3 =
More privacy presets with origin grouping, module quick-open buttons, site-scan support for Chat training.

= 2.0.2 =
Mirror dashboards in wp-admin (Chat training, Forms builder, Privacy Connect), loading states, full brand palette sync.

= 2.0.1 =
Plugin Check compliance fixes and English readme. No functional changes.
