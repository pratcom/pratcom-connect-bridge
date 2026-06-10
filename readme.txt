=== Pratcom Connect ===
Contributors: pratcommedia
Tags: consent, privacy, cookies, forms, chatbot
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 2.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free, 100% local cookie consent banner compliant with Quebec's Law 25. Optional paid modules: smart forms and AI chat.

== Description ==

**Pratcom Connect** adds a cookie consent banner to your WordPress site, designed for compliance with Quebec's Law 25 — free, with no account required and no calls to any external server.

The plugin interface and the consent banner are fully bilingual (French and English).

= Free (no account required) =

* **Law 25 consent banner**: cookie categories (necessary, analytics, marketing), editable FR/EN texts, design adaptable to your brand (primary color + rich palette, WCAG contrast computed automatically).
* **Presets for popular plugins**: check the tools you use (Google Analytics 4, Meta Pixel, Hotjar, and more) — the cookie declaration and blocking rules configure themselves. Automatic suggestions based on the plugins active on your site (local detection, no data transmitted).
* **Local consent registry**: proof of consent stored in your WordPress database, with CSV export.

= Paid modules (require a Pratcom Connect account) =

* **Privacy Connect**: automatic scan of your site's cookies and trackers.
* **Connect Forms**: smart forms with lead scoring and routing.
* **Connect Chat**: multilingual AI chatbot, available 24/7, trained on your content.

Paid modules are provided by the Pratcom Connect service and are enabled by connecting your account (API key). Without an account, the plugin remains fully functional for Law 25 consent.

= External service (disclosure) =

In the free version, **no data is sent to any external server**: the banner, presets and registry run entirely on your site.

If you connect a Pratcom Connect account (a voluntary action), the plugin communicates with the **api.connect.pratcom.net** service (operated by Pratcom Media, Canada):

* **When**: when connecting your account (handshake), during scheduled status checks, and when saving your brand colors.
* **What**: your API key, the site domain, module status and brand palette. Active modules (forms, chat) transmit data submitted by your visitors to the service for processing.
* **Terms**: [Privacy Policy](https://connect.pratcom.net/confidentialite) - [Terms of Use](https://connect.pratcom.net/conditions)

== Frequently Asked Questions ==

= Is the plugin really free? =

Yes. The Law 25 consent banner, presets and local registry are free, with no account and no time limit. Paid modules are optional.

= Does my data leave my site in the free version? =

No. No external call is made as long as you do not connect a Pratcom Connect account.

= Is the banner compliant with Quebec's Law 25? =

The plugin provides the required mechanisms (prior consent per category, easy withdrawal, proof registry). Overall compliance also depends on your own practices (privacy policy, designated privacy officer).

= Is the plugin bilingual? =

Yes, French and English (banner texts are editable in both languages).

= How do I enable the Forms and Chat modules? =

Create an account at connect.pratcom.net, choose your modules, then paste your API key in the plugin's Account tab.

== Screenshots ==

1. Dashboard — connection overview: workspace, active modules and last handshake.
2. Privacy tab — free Law 25 consent banner with popular plugin presets (GA4, Meta Pixel, WooCommerce and more), local consent registry and CSV export.
3. Forms tab — your Connect Forms with copyable shortcodes.
4. Appearance — brand palette with automatic WCAG-compliant contrast, shared by all modules.
5. Modules — Connect Chat, Forms and Privacy at a glance (activate them with your Pratcom Connect account).
6. Front-end — the consent banner live on a website, in the visitor's language.

== Changelog ==

= 2.0.1 =
* Plugin Check compliance pass: escaped front-end script output, WP script tag helpers, input sanitization hardening, translators comments, prepared SQL identifier in CSV export.
* readme.txt rewritten in English (WordPress.org guidelines).

= 2.0.0 =
* First public release.
* Free, 100% local Law 25 consent banner.
* Popular plugin presets + local registry + CSV export.
* Optional connection to the Pratcom Connect service (paid modules).

== Upgrade Notice ==

= 2.0.1 =
Plugin Check compliance fixes and English readme. No functional changes.
