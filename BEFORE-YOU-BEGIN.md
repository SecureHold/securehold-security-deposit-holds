# Before You Begin — Pre-Installation Checklist

Before installing SecureHold WP, review this checklist. Having everything in place before you start will make the setup process smooth and prevent common errors.

---

## Required Software

- [ ] **WordPress** is installed and running (version 6.0 or later recommended)
- [ ] **WooCommerce** is installed and active (version 7.0 or later recommended)
- [ ] **WooCommerce Stripe Gateway** is installed and active. This is the official Stripe plugin for WooCommerce, available free from WordPress.org

> The WooCommerce Stripe Gateway handles the payment at checkout. SecureHold WP adds the security deposit layer on top of it. Both plugins must be present.

---

## Required Stripe Account

- [ ] You have a **Stripe account** (free to create at [stripe.com](https://stripe.com))
- [ ] Your WooCommerce Stripe Gateway is already connected to Stripe and processing payments successfully
- [ ] You can log in to your Stripe Dashboard

> If WooCommerce Stripe is not yet connected and working, set that up first. SecureHold WP requires the Stripe payment flow to already be functional before it can place deposit holds.

---

## Recommended Starting Point

- [ ] **Start in Stripe test mode.** Complete your full setup and verify your first hold using Stripe test cards before switching to live mode. Mistakes in test mode are free. Mistakes in live mode affect real customer cards.

Do not switch to live mode until you have confirmed the test flow works end to end. See [FIRST-TEST-HOLD-CHECKLIST.md](FIRST-TEST-HOLD-CHECKLIST.md).

---

## Things to Know Before You Install

### Holds and WooCommerce order payments are separate

SecureHold WP creates a **separate** Stripe transaction for the security deposit. This is independent of the WooCommerce order payment. Your customer's order total is charged by WooCommerce Stripe as normal. The deposit hold is a second, independent Stripe authorization.

You will see two separate entries in your Stripe Dashboard for each order: the order payment and the deposit hold.

### Guest checkout is supported

SecureHold WP supports guest checkout. Customers do not need to have a WordPress account for the deposit hold to work. However, guest checkout depends on Stripe correctly saving the payment method during checkout. If you experience issues with guest holds, see [SINGLE-USE-PAYMENT-METHOD.md](SINGLE-USE-PAYMENT-METHOD.md).

### WP-Cron is required for automated timing (PRO)

If you plan to use the PRO timing strategies (Delayed, Scheduled, or By Status), your WordPress site must be able to run scheduled tasks via WP-Cron. Most hosting environments support this by default. If your host disables WP-Cron (`DISABLE_WP_CRON=true` in `wp-config.php`), scheduled holds and auto-release will not fire automatically.

For the FREE version with Immediate or Manual timing, WP-Cron is not required.

### The deposit hold is not shown as a separate WooCommerce order

SecureHold WP does not create a second WooCommerce order for the deposit. The hold is managed as a deposit record linked to the existing order. It appears in **SecureHold WP → Deposits** and in the WooCommerce order metabox, not as a separate order.

---

## Before Going Live — Understand the 7-Day Rule

Stripe authorization holds expire after approximately 7 days. If you do not capture or release a hold within that window, Stripe cancels it automatically and you can no longer charge the customer through that hold.

**You must have an operational process to act on holds before they expire.** Plan this before going live.

See [7-DAY-EXPIRATION.md](7-DAY-EXPIRATION.md) for full details.

---

## Ready to Install?

If all the above items are checked, you are ready to proceed.

→ Continue to [QUICK-START.md](QUICK-START.md) for the full installation walkthrough.
