=== CartConsent — Abandoned Cart Recovery for WooCommerce ===
Contributors: cartconsent
Tags: woocommerce, abandoned cart, cart recovery, gdpr, retargeting
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free abandoned-cart recovery, cookie consent, and auto-updating legal pages for WooCommerce — capture shoppers on a lawful basis and win them back by email. Pay only for optional visitor resolution.

== Description ==

Every other abandoned-cart plugin sniffs the email field as your shopper types — grabbing their address before any consent exists, then emailing them anyway. When the shopper rejects your cookie banner, you're holding data you have no lawful basis to use, and your recovery emails land in spam.

**CartConsent does it the right way.** We capture only on a deliberate action — a submitted checkout or a completed email field — never by watching keystrokes. Every captured shopper is stamped with their lawful basis (explicit opt-in vs. soft opt-in) and region. Shoppers with no lawful basis to email are never stored, and non-consented captures are auto-purged. That discipline is exactly why our recovery emails reach the inbox and our audiences don't get rejected by Meta and Google.

= What it does =

* **Verified-shopper capture** on both the classic checkout and the new Cart/Checkout Blocks — we capture from a submitted checkout (an order the shopper actually placed), never by sniffing keystrokes and never an email typed into a field on someone else's behalf.
* **A lawful basis stamped on every cart** (opt-in / soft opt-in), region-aware, with Global Privacy Control honored.
* **Shopper emails encrypted at rest** (libsodium) — the ciphertext in the carts table is useless on its own; for defense against a full database dump, set an encryption secret in wp-config (`CARTCONSENT_CRYPTO_SECRET`) so the key never lives in the database.
* **A ready-to-send 3-step recovery sequence** over your own mail (any SMTP), with merge tags, a one-click cart-restore link, the store's postal address, and a working unsubscribe.
* **Single-use recovery coupons** locked to the shopper.
* **Revenue-recovered dashboard** — carts captured, recovery rate, revenue won back, open-cart value.
* **Consent-filtered retargeting** — Google Consent Mode v2 defaults, a Meta Pixel / GA4 that load only after marketing consent, and a retargeting audience of abandoners built from explicit opt-ins only (unsubscribes and erasures removed automatically).
* **Retention auto-purge** and full WordPress data-export / erase support.

= A complete privacy + recovery suite =

This is an all-in-one: it includes a full **cookie consent banner** (Google Consent Mode v2, script blocking, GPC/DNT), **tamper-evident Consent Records**, and a **Privacy Requests** (DSAR) intake + queue — everything a store needs to be compliant *and* recover carts, in one plugin. A Setup Wizard gets you live in three steps. If you already run the standalone Consent Resolve CMP, this plugin detects it and its own banner steps aside automatically — no double banners.

Connect your CartConsent account (optional) to push consent-clean audiences to Meta and Google through the edge and propagate opt-outs.

= Honest disclaimer =

This plugin gives you strong tools and safe defaults, but how you configure and use them — and whether your sends are lawful in your market — is between you and your counsel. Selling into the EU/UK? Set the consent basis to opt-in only.

== Installation ==

1. Install and activate. WooCommerce is required.
2. Open **Cart Recovery → Settings**, set your From name/address, and review the recovery sequence.
3. Make sure your **WooCommerce store address** is set (Settings → General) — it's used for the required email footer.
4. That's it. Carts are captured on checkout, and the recovery queue runs every five minutes.

== Frequently Asked Questions ==

= Does it work with the new WooCommerce checkout blocks? =
Yes. Capture works on both the classic shortcode checkout and the Cart/Checkout Blocks, and orders are read HPOS-safely.

= Will it email people who didn't consent? =
No. That's the whole point. A shopper with no lawful basis to email is never stored. In opt-out regions (US) a soft opt-in with a one-click unsubscribe is used; in opt-in regions an explicit checkbox is required. Global Privacy Control suppresses soft opt-ins and all audience syncing.

= Do the emails send through my own SMTP? =
Yes — they go through wp_mail, so whatever mail/SMTP setup your store uses is used, with the deliverability essentials (honest From, physical address, one-click List-Unsubscribe) built in.

== Changelog ==

= 1.3.0 =
* New: estimated lost revenue. The plugin counts anonymous guest carts on your own store (locally, no personal data read or stored) and shows a clearly-labeled estimate of what visitor resolution could have won back — anonymous cart value × an adjustable assumed resolution rate (default 20%) × your store's measured recovery rate. Shown on the Dashboard and, with its full math, on Recovery Analytics. Estimates are never blended into measured revenue.

= 1.2.0 =
* The built-in cookie banner is back — free forever, serving by default with no account required (banner + preference center, script blocking, Consent Mode v2, GPC, consent records). Cart recovery is likewise fully functional on the free plan.
* Connecting a Consent Resolve API key is now an optional upgrade: it swaps the free banner for the hosted Consent Resolve javascript + banner (which then serves exclusively) and unlocks visitor resolution credits.
* Banner & Settings screen restored for styling the free banner. Consent Records and Privacy Requests remain in the hosted Consent Resolve dashboard.

= 1.1.0 =
* CartConsent now uses the Consent Resolve javascript and cookie banner exclusively, activated by your API key (Connection screen). Without a connection the banner is not served and capture, sends, popup, and push pause. Consent Records and Privacy Requests moved to your Consent Resolve dashboard.
* Redesigned Dashboard: stats first, a status strip (banner, connection, credits, operational checks) below them, and an icon navigation grid with next-step hints.
* Rebuilt Recovery Analytics: 30-day KPIs, a daily recovered-revenue chart, and a drop-off funnel.
* Cart Recovery Settings reorganized into tabs with a sticky save bar (same settings, easier to find).
* Connection screen simplified (local pixel/GA4 keys live only in Cart Recovery Settings).

= 1.0.0 =
* First release under the CartConsent brand. The complete plugin, free: abandoned-cart recovery (multi-step sequences, segmentation, A/B subject testing, single-use coupons, exit-intent popup with double opt-in, revenue dashboard), a cookie-consent banner with real script blocking, Google Consent Mode v2, GPC honoring, and tamper-evident consent records, and auto-updating Privacy / Cookie / Terms pages (or your Termageddon embed).
* Optional, paid: visitor resolution — identify anonymous abandoning shoppers on a consented basis.
* Consent-first by design: capture only on a submitted checkout (never keystroke-sniffing), a lawful basis stamped on every cart, emails encrypted at rest, and auto-purge of non-consented captures. HPOS-compatible, Cart/Checkout Blocks support, works with your caching and SMTP plugins.
