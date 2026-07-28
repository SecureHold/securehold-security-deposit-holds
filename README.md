# SecureHold WP — Stripe Security Deposits for WooCommerce

SecureHold WP lets WooCommerce merchants place Stripe pre-authorization holds (security deposits) on customer credit cards at order time. The hold ring-fences funds on the customer's card without charging them. You decide later whether to capture (charge) or release (cancel) the hold.

> **A hold is not a charge.** The customer's card is authorized, not debited. Money only moves if you explicitly capture the hold.

---

## What It Does

1. Customer places a WooCommerce order and pays via Stripe.
2. SecureHold WP creates a separate Stripe PaymentIntent for the security deposit, using the same payment method — no second checkout required.
3. The authorized hold appears in your SecureHold Deposits list and in your Stripe Dashboard.
4. You capture the hold (funds transfer to you) or release it (authorization removed) from within WooCommerce.

---

## Typical Use Cases

- Equipment and vehicle rental (damage deposit)
- Short-term accommodation (security deposit)
- Event venues (refundable deposit against damage)
- Professional services (commitment deposit)

---

## Requirements

- WordPress 6.0 or later
- WooCommerce 7.0 or later
- WooCommerce Stripe Gateway (official plugin by Stripe / WooCommerce)
- Stripe account (test account recommended for initial setup)
- PHP 7.4 or later

The Stripe PHP SDK is bundled with the plugin. No manual Composer installation is required.

---

## FREE vs. PRO

| Feature | FREE | PRO |
|---|---|---|
| Immediate hold strategy | ✅ | ✅ |
| Manual hold strategy | ✅ | ✅ |
| Global deposit rules | ✅ | ✅ |
| Product-level rules | ✅ | ✅ |
| Category-level rules | ✅ | ✅ |
| Checkout deposit notice | ✅ | ✅ |
| Guest checkout support | ✅ | ✅ |
| Health Check & self-test | ✅ | ✅ |
| Delayed / Scheduled / By Status timing | — | ✅ |
| Highest Deposit Wins rule policy | — | ✅ |
| Per Item Aggregated mode | — | ✅ |
| Auto-release (cron-based) | — | ✅ |
| My Account deposits tab | — | ✅ |
| Dashboard analytics | — | ✅ |
| Full audit log viewer | — | ✅ |
| Email branding | — | ✅ |
| Advanced diagnostic tools | — | ✅ |

---

## Documentation

The following guides cover installation, first setup, and the most critical concepts before going live.

| File | Purpose |
|---|---|
| [WHAT-IS-SECUREHOLD.md](WHAT-IS-SECUREHOLD.md) | What the plugin does and who it is for |
| [HOW-STRIPE-HOLDS-WORK.md](HOW-STRIPE-HOLDS-WORK.md) | How Stripe pre-authorization holds work |
| [CAPTURE-VS-RELEASE.md](CAPTURE-VS-RELEASE.md) | Difference between capturing and releasing a hold |
| [7-DAY-EXPIRATION.md](7-DAY-EXPIRATION.md) | Stripe's 7-day expiration rule — critical operational note |
| [BEFORE-YOU-BEGIN.md](BEFORE-YOU-BEGIN.md) | Pre-installation requirements and checklist |
| [QUICK-START.md](QUICK-START.md) | Full installation and first hold walkthrough |
| [SETUP-WIZARD.md](SETUP-WIZARD.md) | Step-by-step Setup Wizard reference |
| [FIRST-TEST-HOLD-CHECKLIST.md](FIRST-TEST-HOLD-CHECKLIST.md) | Verification checklist after test setup |
| [GOING-LIVE-CHECKLIST.md](GOING-LIVE-CHECKLIST.md) | Checklist for switching to production mode |
| [HOLD-CREATION-FAILURE-FLOW.md](HOLD-CREATION-FAILURE-FLOW.md) | Troubleshooting: hold not created |
| [SINGLE-USE-PAYMENT-METHOD.md](SINGLE-USE-PAYMENT-METHOD.md) | Troubleshooting: single-use payment method error |
| [GETTING-SUPPORT.md](GETTING-SUPPORT.md) | How to prepare and submit a support request |

---

## Distribution Notes

- `readme.txt` is the WordPress.org plugin directory readme.
- `LICENSE.txt` contains the license terms (GPLv2 or later).
- `.distignore` defines files excluded from the distribution ZIP.
- `composer.json`, `composer.lock`, and `scoper.inc.php` are development/build files, excluded from distribution via `.distignore`.

### For Contributors

`composer.lock` is intentionally committed to this repository. It pins exact dependency versions so the PHP dependencies (including the bundled Stripe SDK) build the same way every time. The `vendor/` directory itself is not committed — it is generated from `composer.lock` and bundled into the plugin only when building the distribution artifact.

---

## License

GPLv2 or later. See `LICENSE.txt`.
