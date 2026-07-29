=== Consent Resolve for WooCommerce — Abandoned Cart Recovery ===
Contributors: consentresolve
Tags: woocommerce, abandoned cart, cart recovery, gdpr, retargeting
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 0.4.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Recover abandoned WooCommerce carts the right way — capture shoppers on a lawful basis, win them back by email, and build consent-clean retargeting audiences.

== Description ==

Every other abandoned-cart plugin sniffs the email field as your shopper types — grabbing their address before any consent exists, then emailing them anyway. When the shopper rejects your cookie banner, you're holding data you have no lawful basis to use, and your recovery emails land in spam.

**Consent Resolve for WooCommerce does it the right way.** We capture only on a deliberate action — a submitted checkout or a completed email field — never by watching keystrokes. Every captured shopper is stamped with their lawful basis (explicit opt-in vs. soft opt-in) and region. Shoppers with no lawful basis to email are never stored, and non-consented captures are auto-purged. That discipline is exactly why our recovery emails reach the inbox and our audiences don't get rejected by Meta and Google.

= What it does =

* **Verified-shopper capture** on both the classic checkout and the new Cart/Checkout Blocks — we capture from a submitted checkout (an order the shopper actually placed), never by sniffing keystrokes and never an email typed into a field on someone else's behalf.
* **A lawful basis stamped on every cart** (opt-in / soft opt-in), region-aware, with Global Privacy Control honored.
* **Shopper emails encrypted at rest** (libsodium) — the ciphertext in the carts table is useless on its own; for defense against a full database dump, set an encryption secret in wp-config (`CONSENT_RESOLVE_WOO_CRYPTO_SECRET`) so the key never lives in the database.
* **A ready-to-send 3-step recovery sequence** over your own mail (any SMTP), with merge tags, a one-click cart-restore link, the store's postal address, and a working unsubscribe.
* **Single-use recovery coupons** locked to the shopper.
* **Revenue-recovered dashboard** — carts captured, recovery rate, revenue won back, open-cart value.
* **Consent-filtered retargeting** — Google Consent Mode v2 defaults, a Meta Pixel / GA4 that load only after marketing consent, and a retargeting audience of abandoners built from explicit opt-ins only (unsubscribes and erasures removed automatically).
* **Retention auto-purge** and full WordPress data-export / erase support.

= A complete privacy + recovery suite =

This is an all-in-one: it includes a full **cookie consent banner** (Google Consent Mode v2, script blocking, GPC/DNT), **tamper-evident Consent Records**, and a **Privacy Requests** (DSAR) intake + queue — everything a store needs to be compliant *and* recover carts, in one plugin. A Setup Wizard gets you live in three steps. If you already run the standalone Consent Resolve CMP, this plugin detects it and its own banner steps aside automatically — no double banners.

Connect your Consent Resolve account (optional) to push consent-clean audiences to Meta and Google through the edge and propagate opt-outs.

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

= 0.4.2 =
* Second full audit pass (4 reviewers). Retargeting fix: the Meta Pixel / GA4 / Purchase event now recognize the plugin's own bundled consent banner as the consent authority — previously, on a store with no separate CMP, they never loaded even after a shopper accepted marketing.
* Compliance: the preference center now defaults categories to your region's policy instead of pre-ticking everything — in opt-in regions (EU/UK/CA) marketing and statistics start unchecked, so clicking Save never grants them without an affirmative tick.
* Correctness: revenue is attributed to a single cart per recovered order (no multi-counting across a repeat abandoner's finished sequences); a returning shopper's restarted sequence no longer inherits a stale coupon or retry count; a paid order always clears its recovery messages even when capture is turned off; an empty sequence can't dead-end a cart; rate-limit windows now recover for busy shared IPs; consent records are timestamped in GMT like every other table; duplicate Consent Mode default output removed.
* Encryption-at-rest docs corrected; optional wp-config secret (`CONSENT_RESOLVE_WOO_CRYPTO_SECRET`) added for full-database-dump protection.

= 0.4.1 =
* Security + QA hardening pass (full-plugin audit). Data-subject deletion/opt-out now applies only after an admin verifies the request (not on public intake); web-push subscriptions link only to the logged-in shopper's own address; push endpoints are allowlisted to the real browser push services (SSRF guard); per-address throttles on confirmation and consent-log writes; CSV export is formula-injection safe.
* Correctness fixes: a popup opt-in and a checkout capture for the same shopper reconcile to one recovery sequence (no duplicate emails); recoveries that land after the final email are still attributed; the popup no longer interrupts or expires an already-active recovery; the send queue claims each cart atomically so overlapping cron runs can't double-send.
* Fixed the WordPress 6.7 "_load_textdomain_just_in_time was called incorrectly" notice at the root — option-dependent wiring is deferred to init/woocommerce_init so no translation runs before init.

= 0.4.0 =
* Cart-saver popup: an exit-intent (or timed) popup that saves a shopper's cart by email. Spam-safe by design — an explicit consent tick plus a double-opt-in confirmation email, so a submitted address that is never confirmed is never sent a recovery sequence.
* Recovery Analytics screen: channel totals (emails, web pushes, popup captures, recovery clicks), per-sequence performance (carts, recovered, rate, revenue), and A/B subject-test send splits.

= 0.3.0 =
* Multi-sequence recovery workflows with segmentation — run several targeted flows (by cart value, products, categories, country); each cart enters the first matching sequence.
* A/B subject testing — put multiple subject lines on any step and they are split-tested automatically.
* Web push recovery channel (self-contained, no third-party account): shoppers who opt in at checkout get a browser notification alongside each recovery email. Pure-PHP Web Push encryption (RFC 8291) + VAPID.

= 0.2.0 =
* Now a standalone all-in-one — no separate CMP needed. Added a built-in cookie consent banner + preference center (Google Consent Mode v2, Microsoft UET, curated script blocking, GPC/DNT honoring, region auto-detect), tamper-evident hash-chained Consent Records with CSV export + chain verification, and a Privacy Requests (DSAR) intake form + admin queue with deadline tracking. New unified "Consent Resolve" admin menu, a Setup Wizard, and a Connection page for your own Consent Resolve account credentials. Shortcodes: [crw_manage_consent] (reopen preferences) and [crw_dsar_form] (privacy request form). The built-in banner stands down automatically if the standalone Consent Resolve plugin is active.

= 0.1.0 =
* Initial release: consent-first abandoned-cart capture (from submitted checkouts, classic + Blocks), a 3-step recovery sequence with cart-restore + single-use coupons, a revenue dashboard, consent-gated pixel/GA4 tracking, consent-filtered retargeting audiences (explicit opt-in + no GPC), at-rest email encryption keyed to a dedicated plugin secret, one-click RFC-8058 unsubscribe (no email in the URL), retention auto-purge, and full WordPress privacy-tools support. Went through a security + QA audit before release (GPC enforced on audiences, claim-before-send queue with bounded retries, HPOS-safe).
