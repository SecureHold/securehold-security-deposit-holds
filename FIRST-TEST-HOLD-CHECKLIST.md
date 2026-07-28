# First Test Hold — Verification Checklist

Use this checklist after completing the Setup Wizard to confirm your configuration is working correctly from end to end.

Complete every item before considering your test setup done. Do not switch to live mode until all items are checked.

---

## Before Placing the Test Order

- [ ] **SecureHold WP is in test mode**
  Go to **SecureHold WP → Settings → Connection**. The mode should show **Test**.

- [ ] **WooCommerce Stripe Gateway is also in test mode**
  Go to **WooCommerce → Settings → Payments → Stripe → Manage**. Confirm test mode is enabled.

- [ ] **Webhook is configured in your Stripe test dashboard**
  Log in to [dashboard.stripe.com](https://dashboard.stripe.com), enable test mode (toggle top right), go to **Developers → Webhooks**, and confirm your endpoint URL is listed and active.

- [ ] **Webhook signing secret is entered in SecureHold WP**
  Go to **SecureHold WP → Settings → Connection**. Confirm the webhook signing secret field is not empty.

- [ ] **A deposit amount is configured**
  Go to **SecureHold WP → Settings → Deposit Rules**. Confirm a default hold amount is set (for example, `100`).

---

## Place the Test Order

- [ ] **Visit your WooCommerce store and add a product to the cart**

- [ ] **Proceed to checkout and select Stripe as the payment method**

- [ ] **Enter the Stripe test card details:**

  | Field | Value |
  |---|---|
  | Card number | `4242 4242 4242 4242` |
  | Expiry date | Any future date (e.g. `12/34`) |
  | CVC | Any 3 digits (e.g. `123`) |
  | Name / Postcode | Any valid value |

- [ ] **Complete the order**
  You should reach the WooCommerce order confirmation page.

---

## Verify the Hold in SecureHold WP

- [ ] **The deposit appears in the Deposits list**
  Go to **SecureHold WP → Deposits**. Your test order should appear in the list.

- [ ] **The deposit status is Authorized**
  The status badge next to the deposit should show **Authorized** (green).

- [ ] **The deposit amount matches your configured amount**
  Click **View Details** on the deposit row and confirm the authorized amount is correct.

---

## Verify the Hold in Stripe

- [ ] **Open your Stripe test dashboard** at [dashboard.stripe.com](https://dashboard.stripe.com) (test mode enabled)

- [ ] **Go to Payments → PaymentIntents**

- [ ] **Find the PaymentIntent for the deposit hold**
  It should be separate from the order payment. The deposit PaymentIntent will have status **Requires Capture**.

  > Note: The order payment (charged by WooCommerce Stripe) and the deposit hold (created by SecureHold WP) are two separate PaymentIntents. Both will appear in Stripe.

---

## Verify the Hold in WooCommerce

- [ ] **Open the order in WooCommerce → Orders**

- [ ] **The SecureHold WP metabox is visible on the order page**

- [ ] **The metabox shows the authorized deposit amount and status**

---

## Test the Release Action

- [ ] **In SecureHold WP → Deposits, click the action menu (⋮) on the test deposit row**

- [ ] **Click Release and confirm**

- [ ] **The deposit status changes to Released**

- [ ] **In your Stripe test dashboard, the PaymentIntent status changes to Canceled**

- [ ] **No funds were charged** — verify your Stripe balance was not affected

---

## All Steps Passed?

If every item above is checked, your test configuration is working correctly.

→ When you are ready to accept real deposits, proceed to [GOING-LIVE-CHECKLIST.md](GOING-LIVE-CHECKLIST.md).

---

## If Any Step Failed

- Deposit not appearing → [HOLD-CREATION-FAILURE-FLOW.md](HOLD-CREATION-FAILURE-FLOW.md)
- Deposit status is Failed → [SINGLE-USE-PAYMENT-METHOD.md](SINGLE-USE-PAYMENT-METHOD.md)
- Stripe shows no PaymentIntent → Check your API keys and mode alignment
- Webhook not updating status → Verify the webhook URL and signing secret in both Stripe and SecureHold WP settings
- Need more help → [GETTING-SUPPORT.md](GETTING-SUPPORT.md)
