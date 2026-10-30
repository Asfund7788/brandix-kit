=== Brandix PowerKit - Enterprise Agency Engine ===
Contributors: brandixsoft
Tags: sticky buttons, redirects, cookie consent, smtp, image optimization
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 11.31
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Agency toolkit by Brandix Soft: sticky contact buttons, mobile bottom menu, redirects, custom 404 page, cookie consent, email delivery and more.

== Description ==

Brandix PowerKit bundles the tools Brandix Soft uses on client websites into one plugin. Every module can be switched on or off from Brandix Kit → Modules.

* Sticky contact buttons with a launcher, shapes and animations
* Mobile bottom navigation menu
* Redirect manager and 404 log, with optional 404 → home redirect
* Custom 404 page
* Cookie consent banner with consent log
* Outgoing email via SMTP, Google, Outlook or SMTP2GO, with email logs
* Image optimisation on upload (resize, compress, WebP)
* Text and link replacement, phone number validation for Elementor forms
* Login and activity logs

Updates are delivered from the Brandix Soft GitHub repository.

== Third-party libraries ==

* intl-tel-input 17.0.8 (MIT licence) — https://github.com/jackocnr/intl-tel-input — bundled in assets/intl-tel-input for phone number validation.

== Changelog ==

= 11.31 =
* Security and code-quality fixes from a WordPress Plugin Check audit (output escaping, prepared SQL, WordPress file functions).
* Phone validation library is now bundled with the plugin instead of loading from a CDN.
* Maintenance page no longer loads Google Fonts.
* Requires WordPress 6.2 or newer.
