# Getting Support

Before submitting a support request, please work through this page. Good preparation leads to faster resolutions and avoids unnecessary back-and-forth.

---

## Before Contacting Support

Most common issues are covered by the troubleshooting documentation. Please check the relevant guide first:

| Issue | Guide |
|---|---|
| Hold was not created | [HOLD-CREATION-FAILURE-FLOW.md](HOLD-CREATION-FAILURE-FLOW.md) |
| "Single-use payment method" error | [SINGLE-USE-PAYMENT-METHOD.md](SINGLE-USE-PAYMENT-METHOD.md) |
| Webhook not working | See the Webhook section in [SETUP-WIZARD.md](SETUP-WIZARD.md) |
| Hold disappeared or expired | [7-DAY-EXPIRATION.md](7-DAY-EXPIRATION.md) |
| Capture or release failed | [CAPTURE-VS-RELEASE.md](CAPTURE-VS-RELEASE.md) |

---

## Before Contacting Support — Checklist

- [ ] I have read the relevant troubleshooting guide above
- [ ] I have checked **SecureHold WP → Health Check** and reviewed any warnings
- [ ] I have enabled debug logging and reviewed the logs
- [ ] I can reproduce the issue consistently, or I have noted the exact steps that triggered it
- [ ] I have the WooCommerce order ID for the affected order
- [ ] I know whether the issue occurred in test mode or live mode

---

## Try to Reproduce in Test Mode First

If possible, reproduce the issue in test mode before contacting support. Test mode uses Stripe test cards and does not affect real customers or real funds. Reproducing in test mode:

- Makes it safe to share order details and logs
- Allows faster iteration without risk
- Confirms the issue is reproducible (not a one-time event)

If the issue only occurs in live mode and you cannot reproduce it in test mode, note this clearly in your support request.

---

## Information to Collect Before Submitting

Include the following in your support request. The more detail you provide upfront, the faster the issue can be diagnosed.

### 1. WooCommerce Order ID

The ID of the order where the issue occurred. Find it in **WooCommerce → Orders** (e.g. Order #1042).

### 2. SecureHold WP Deposit ID (if applicable)

The deposit ID from **SecureHold WP → Deposits → View Details** (e.g. Deposit #17).

### 3. Stripe Mode

Was the issue in **test mode** or **live mode**? State this clearly.

### 4. Stripe PaymentIntent ID (if available)

If a hold was created (even if it failed), the Stripe PaymentIntent ID (starts with `pi_`) is shown on the deposit details page. Include it if present.

### 5. Plugin Versions

Go to **WordPress → Dashboard → Updates** or **Plugins → Installed Plugins** and note:
- SecureHold WP version
- WooCommerce version
- WooCommerce Stripe Gateway version
- WordPress version

### 6. Health Check Results

Go to **SecureHold WP → Health Check** and take a screenshot or copy the status of all checks.

### 7. Relevant Log Entries

1. Go to **SecureHold WP → Settings → Connection** and enable **Debug Logging**. Save.
2. Reproduce the issue (place a new test order or trigger the action again).
3. Go to **WooCommerce → Status → Logs**.
4. Select `securehold-stripe-deposits` as the log source.
5. Copy the relevant log entries — especially any lines marked `[error]` or `[warning]`.

### 8. Support Bundle (recommended)

Go to **SecureHold WP → Tools → System → Export Support Bundle**. This exports a structured file with system information, plugin versions, and configuration details (no API keys or secrets are included). Attach this file to your support request.

### 9. Screenshots

Include screenshots of:
- The deposit details page (if a deposit was created)
- Any error messages shown in the admin
- The relevant Health Check results

---

## How to Describe Your Issue Clearly

A clear issue description speeds up resolution significantly. Use this structure:

**What I expected to happen:**
> After a customer placed an order and paid via Stripe, a deposit hold of $100 should have appeared in SecureHold WP → Deposits with status Authorized.

**What actually happened:**
> No deposit appeared in the Deposits list. The order completed normally (status: Processing).

**Steps to reproduce:**
> 1. Added product X to cart
> 2. Checked out as guest using test card 4242 4242 4242 4242
> 3. Order #1042 was created and paid
> 4. Checked SecureHold WP → Deposits — no entry found

**What I have already tried:**
> Checked exclusions — product is not excluded. Minimum cart amount is not set. Reviewed logs — no error entries found. Health Check shows all green.

---

## Where to Submit a Support Request

- **WordPress.org plugin support forum:** For free version issues — [wordpress.org/support/plugin/securehold-wp](https://wordpress.org/support/plugin/securehold-wp)
- **SecureHold WP support portal:** For PRO license holders — [secureholdwp.com/support](https://secureholdwp.com/support)

Please do not include Stripe API keys, secret keys, or webhook signing secrets in your support request. These are sensitive credentials and should never be shared.
