# Going Live — Production Transition Checklist

> ⚠️ **Do not switch to live mode until your test setup is fully working.** Complete the [FIRST-TEST-HOLD-CHECKLIST.md](FIRST-TEST-HOLD-CHECKLIST.md) before proceeding.

This checklist guides you through switching SecureHold WP from test mode to live (production) mode. Follow every step in order.

---

## Before You Start

- [ ] **All items in the First Test Hold Checklist are complete**
  Your test setup worked end to end: hold created, hold visible in Stripe, release tested successfully.

- [ ] **You have your Stripe live API keys ready**
  Log in to [dashboard.stripe.com](https://dashboard.stripe.com), switch to **Live mode** (toggle top right), and go to **Developers → API Keys**.
  - Publishable key: starts with `pk_live_`
  - Secret key: starts with `sk_live_` (click **Reveal** to view)

- [ ] **You understand that live holds affect real customer credit cards**
  In live mode, all holds are real. Test cards will not work. Any mistake (wrong amount, unexpected capture) affects a real customer's card.

---

## Configure the Live Webhook in Stripe

You must create a **separate webhook endpoint** in your Stripe **live** environment. The test webhook you created earlier only works in test mode.

- [ ] **In your Stripe Dashboard, switch to Live mode**
  Use the toggle in the top-right corner. The dashboard header turns darker when in live mode.

- [ ] **Go to Developers → Webhooks**

- [ ] **Click Add endpoint**

- [ ] **Enter your webhook URL:**
  ```
  https://yoursite.com/wp-json/securehold/v1/webhook
  ```
  Use your actual domain. This URL is the same as your test webhook URL.

- [ ] **Add the four required events:**
  - `payment_intent.amount_capturable_updated`
  - `payment_intent.succeeded`
  - `payment_intent.canceled`
  - `payment_intent.payment_failed`

- [ ] **Click Add endpoint to save**

- [ ] **Reveal and copy the Signing secret** (starts with `whsec_`)
  This is a different signing secret from your test webhook. Do not mix them.

---

## Update SecureHold WP Settings

- [ ] **Go to SecureHold WP → Settings → Connection**

- [ ] **Switch the mode to Live**

- [ ] **Enter your live Stripe publishable key** (`pk_live_...`)

- [ ] **Enter your live Stripe secret key** (`sk_live_...`)

- [ ] **Enter the live webhook signing secret** (`whsec_...` from the live webhook you just created)

- [ ] **Click Save Changes**

---

## Align WooCommerce Stripe Gateway

- [ ] **Go to WooCommerce → Settings → Payments → Stripe → Manage**

- [ ] **Confirm that WooCommerce Stripe Gateway is also in Live mode**

  > ⚠️ **Mode mismatch will cause failures.** If SecureHold WP is in live mode but WooCommerce Stripe is in test mode (or vice versa), holds will fail or behave unexpectedly. Both must be in the same mode.

- [ ] **Check the mode alignment indicator in SecureHold WP**
  On the Connection tab, if a yellow warning banner appears saying the modes are misaligned, resolve it before proceeding.

---

## Verify the Live Setup

- [ ] **Place a real order with a real card**
  Use a card you control. Choose your lowest-price product to minimize the hold amount.

- [ ] **Confirm the hold appears in SecureHold WP → Deposits** with status **Authorized**

- [ ] **Confirm the hold appears in your Stripe live dashboard** as a PaymentIntent with status **Requires Capture**

- [ ] **Release the hold immediately** — this was a verification hold, not a real deposit
  Go to the deposit and click **Release**. Verify the status changes to Released.

- [ ] **Confirm no unexpected charge appeared on your card**

---

## Operational Reminders Before Accepting Customer Orders

- [ ] **You have a process to capture or release holds within 7 days**
  Stripe authorization holds expire after approximately 7 days. See [7-DAY-EXPIRATION.md](7-DAY-EXPIRATION.md).

- [ ] **Your team knows how to capture and release deposits**
  Anyone who may need to act on deposits has read [CAPTURE-VS-RELEASE.md](CAPTURE-VS-RELEASE.md).

- [ ] **Email notifications are configured as intended**
  Go to **SecureHold WP → Settings → Notifications** and verify which emails are enabled.

- [ ] **Your deposit amount and rules are set correctly for production**
  Go to **SecureHold WP → Settings → Deposit Rules** and review your global amount, product rules, and category rules.

---

## Do Not Mix Test and Live

> ❌ **Never use live API keys with test-mode holds.**
> ❌ **Never use test API keys in live mode.**
> ❌ **Never paste your test webhook signing secret into the live signing secret field.**

Test and live are completely separate Stripe environments. Keys, webhooks, customers, and PaymentIntents do not carry across. Any mixing will cause silent failures or errors.

---

## You Are Now Live

Once all items above are checked, SecureHold WP is running in production mode and your customers will receive real deposit holds when they order.

Monitor **SecureHold WP → Deposits** regularly to ensure holds are actioned before the 7-day expiration window.
