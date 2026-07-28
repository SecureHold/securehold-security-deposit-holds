=== SecureHold Security Deposit Holds with Stripe for WooCommerce ===
Contributors: secureholdwp
Tags: stripe, woocommerce, security deposit, pre-authorization, rental
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 3.4.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Place a Stripe security deposit hold on WooCommerce orders for rentals and bookings. Capture or release, no upfront charge.

== Description ==

Protect your rentals and bookings with a Stripe security deposit hold on WooCommerce. Reserve the card at checkout, then decide later whether to capture or release. No upfront charge required.

Perfect for:

* rental deposits (equipment, vehicles, accommodations)
* booking deposits for events and reservations
* damage deposits for services and short-term use

### A hold is not a charge

* SecureHold only reserves funds temporarily on the customer's card. The deposit stays fully refundable until you capture it.
* Nothing transfers to you unless you explicitly capture the hold.
* Authorization windows are time-limited and vary by card network and payment method. Capture or release as soon as your workflow allows.

### Not a partial payment plugin

SecureHold never charges anything at checkout. If you're looking for a plugin that collects a partial payment upfront, a deposit-on-order plugin, this isn't it. SecureHold only places holds.

### How it works

1. Customer places an order in WooCommerce
2. SecureHold places a Stripe hold, not a charge
3. From the order screen, you capture (take some or all of the money, up to the authorized amount) or release (no charge) the hold

### Built for production

* HPOS (High-Performance Order Storage) ready
* Signed and verified Stripe webhooks
* Stripe PHP SDK loaded in an isolated namespace, reducing the risk of PHP class conflicts with other plugins
* Guided Setup Wizard and one-click Health Check
* Card numbers never touch your server. Tokenization happens through Stripe.js via the required WooCommerce Stripe Gateway plugin.

### FREE Features

**Configuration**

* Global deposit amount, fixed or percentage
* Manual or immediate hold creation
* Priority Chain and Highest Deposit Wins conflict resolution
* Per-order and per-item aggregated deposit calculation

**Checkout**

* Configurable checkout deposit notice: text, style, and position

**Day-to-day management**

* Capture any amount up to what was authorized, or release the hold
* Deposits list and detail view with a detailed status timeline
* WooCommerce order integration (metabox actions)
* Customer-facing "My Deposits" page in My Account

**Automation**

* Automatic release after a configurable number of days (1–7)

**Emails**

* Customer and admin email notifications

**Diagnostics**

* Setup Wizard and Health Check tools
* Sanitized diagnostic bundle export for support requests

### PRO Features

Upgrade to PRO to unlock:

* Per-product and per-category deposit rules
* Live rule simulator: preview which rule applies before checkout
* Delayed, Scheduled, and By-Status timing strategies
* Full email branding (logo, colors, footer text)
* Extended log pagination and diagnostic tools (Stripe Inspector, database integrity check)
* Priority email support

### Documentation and Support

* Documentation: https://secureholdwp.com/docs/
* Support: https://secureholdwp.com/support/
* Free vs Pro comparison: https://secureholdwp.com/pricing/
* Refund Policy: https://secureholdwp.com/refund-policy/

### Requirements

* WordPress 6.0+
* WooCommerce
* WooCommerce Stripe Gateway plugin (required)
* A Stripe account (test or live mode)

== Installation ==

### Automatic installation

1. Go to Plugins -> Add New
2. Search for "SecureHold"
3. Click Install and Activate

### Manual installation

1. Upload the plugin files to `/wp-content/plugins/securehold-security-deposit-holds`
2. Activate the plugin through the Plugins screen

### After activation

Run the Setup Wizard to connect your Stripe account, configure the webhook, and verify your environment.

== Frequently Asked Questions ==

= Does this charge my customers? =

No. SecureHold places a Stripe hold (pre-authorization). Funds only transfer if you explicitly capture the hold. The hold itself can temporarily reduce your customer's available balance or spending limit, even though nothing is charged to you.

= What is a security deposit hold? =

A security deposit hold, sometimes called a card hold, card authorization, or Stripe authorization, temporarily reserves funds on your customer's card. No money moves unless you capture it.

= Can I capture only part of the deposit? =

Yes, in Free. From the order screen, enter any amount up to the authorized total. You can't capture more than what was originally authorized.

= Can I capture or release a hold more than once? =

No. Stripe closes the authorization after any capture, whether full or partial, and automatically releases any remaining amount. Releasing a hold is also final. Each hold supports one capture or one release, not both and not repeated.

= Is this a partial payment or deposit-on-order plugin? =

No. SecureHold never charges anything at checkout, it only places a hold. If you're looking for a plugin that collects a partial payment upfront, this isn't it.

= Is my customers' card data stored on my server? =

No. Card tokenization happens through Stripe.js via the required WooCommerce Stripe Gateway plugin. SecureHold stores only Stripe identifiers (PaymentIntent ID, Customer ID) locally, never raw card numbers.

= Do I need the WooCommerce Stripe Gateway plugin? =

Yes, it's required. SecureHold builds on top of it to add holds. It doesn't replace your existing checkout payment flow.

= Does this work with WooCommerce Bookings or other booking plugins? =

SecureHold works at the order level, so it fits most WooCommerce order flows, including bookings. We haven't tested every third-party booking plugin individually, so test your specific setup in Stripe test mode first.

= Is it compatible with High-Performance Order Storage (HPOS)? =

Yes, SecureHold officially declares HPOS compatibility.

= Does this work with guest checkout? =

Yes. SecureHold is designed to support guest checkout, including saving a payment method for later capture.

= Can I test this before going live? =

Yes. Use Stripe test mode to validate the full flow before switching to live keys.

= Can I customize the email templates? =

Email content and timing come standard in Free. Full visual branding (your logo, colors, and footer text) is available in Pro.

= Can I change the deposit amount after it's been authorized? =

The authorized amount is set when the hold is created, based on your deposit rule. You can capture any amount up to that total, including less than the full amount, but you can't increase it afterward. To use a different amount, release the hold and create a new one.

= What happens if I refund an order that has a deposit hold? =

If you haven't captured the hold yet, release it instead of refunding. No charge was ever made. Once you capture a deposit, it becomes a normal WooCommerce payment, refundable through WooCommerce's standard refund process.

= What's the difference between Free and Pro? =

Free covers a single global rule (with a choice of conflict resolution strategy and aggregation mode), manual and immediate timing, capture and release (including partial capture), auto-release, and the customer deposits page. Pro adds per-product and per-category rules, a live rule simulator, Delayed/Scheduled/By-Status timing, email branding, and additional diagnostic tools.

= What happens if I deactivate the plugin? =

Nothing is deleted. Your deposit records and settings stay exactly as they were, though the automatic release schedule pauses until you reactivate.

= What happens if I delete (uninstall) the plugin? =

Deleting the plugin permanently removes local deposit records, logs, and settings. Your Stripe account isn't affected, and any hold still open on Stripe stays there. Capture or release urgent holds before deleting the plugin. SecureHold can't manage them from WordPress once local data is gone.

= Is there a refund policy for Pro? =

Yes. Pro and Agency plans can be refunded within 14 days of purchase under certain conditions. See our refund policy for full terms. It doesn't apply to the Free plan, which costs nothing.

= What happens if an authorization expires before I act? =

Authorization windows are time-limited and vary by card network and payment method. Stripe cancels an uncaptured authorization once its window closes. Free's auto-release feature can release a hold automatically after a configurable number of days (1–7) as a safety net.

== Screenshots ==

1. Deposits list
2. Deposit details
3. Order metabox
4. Settings - connection
5. Settings - deposit rules
6. Settings - deposit automation
7. Checkout notice
8. Setup wizard
9. Health check

== External Services ==

This plugin connects to the Stripe API to create and manage payment authorization holds (pre-authorizations) on behalf of the store owner.

**Stripe API**

* Service: Stripe payment processing
* Endpoint: https://api.stripe.com
* Data transmitted: payment method data is collected and processed by Stripe.js and Stripe's servers. This plugin stores the following Stripe object IDs locally in the WordPress database for authorization hold management: PaymentIntent ID, Customer ID, and PaymentMethod ID. Order amount, currency, and order ID are also transmitted to Stripe as PaymentIntent parameters.
* Stripe Terms of Service: https://stripe.com/tos
* Stripe Privacy Policy: https://stripe.com/privacy

**Deactivation feedback (optional)**

When the plugin is deactivated, an optional feedback form appears in the WordPress admin. If the form is submitted, the following data is sent by email to support@secureholdwp.com: site URL, plugin version, deactivation reason, and any additional details provided. Submission is entirely optional. Clicking "Skip and Deactivate" deactivates the plugin without sending any data.

== Changelog ==

= 3.4.3 =

* Fix: product and category deposit rules created by the Rule Engine no longer stay active after the Rule Engine becomes unavailable — deposit calculation now correctly falls back to the global rule in that case.
* Fix: existing product and category rules are preserved and automatically resume being applied once the Rule Engine is available again; no rule data is lost.
* Removed: the legacy per-product "Security Deposit" tab on the WooCommerce product edit screen, which had no effect on the deposit amount actually applied at checkout.
* Fix: the Capture button on the WooCommerce order edit screen (classic and HPOS) now correctly opens the capture dialog; it previously had no effect.
* Improvement: the capture dialog markup is now shared between the order screen and the Deposits admin page instead of being duplicated.
* Fix: deposit amounts shown in the order timeline no longer display raw HTML markup.
* Add: the deposit notice now also displays on the WooCommerce Cart & Checkout Blocks checkout, not only the classic checkout.
* Removed: an unused legacy order metabox class and its companion script, superseded by the current metabox implementation.

= 3.4.2 =

* Maintenance release. Improved compatibility with WordPress 6.8 and WooCommerce 9.x.

= 1.0.0 =

* Initial release

== Upgrade Notice ==

= 1.0.0 =
Initial version of SecureHold WP
