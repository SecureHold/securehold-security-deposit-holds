# How Stripe Holds Work

This page explains what happens behind the scenes when SecureHold WP creates, captures, or releases a security deposit hold through Stripe.

---

## The Three States of a Hold

Every security deposit hold moves through three possible states:

### 1. Authorized (hold active)

Stripe has reserved the deposit amount on the customer's card. The customer cannot spend that amount while the hold is active, but no money has left their account. The hold will remain active until you capture it, release it, or it expires.

From your side: the deposit appears in **SecureHold WP → Deposits** with status **Authorized**, and in your Stripe Dashboard as a PaymentIntent with status **Requires Capture**.

### 2. Captured (funds collected)

You chose to charge the deposit. The reserved funds have been transferred from the customer's card to your Stripe balance. This is the same as a normal card payment: the money moves and the transaction is complete.

This action is **not reversible** through SecureHold WP. If you need to return the funds after capturing, you must issue a refund separately via Stripe or WooCommerce.

### 3. Released (hold removed)

You chose to cancel the deposit. The authorization is removed and the customer's funds are fully available again. Nothing was charged. The release is reflected on the customer's card statement: the pending authorization disappears.

---

## What Stripe Does — Step by Step

When SecureHold WP creates a hold, the following happens:

1. **SecureHold WP contacts the Stripe API.** It sends a PaymentIntent creation request with `capture_method=manual`. This tells Stripe to authorize the card but not capture funds.

2. **Stripe contacts the customer's bank.** The bank verifies the card and reserves the deposit amount. This is called a pre-authorization.

3. **The bank confirms the hold.** The customer's available balance is reduced by the hold amount. The PaymentIntent status becomes **Requires Capture**.

4. **You take action at the right time.** When you are ready, you either capture (charge) or release the hold from your WooCommerce admin.

5. **Stripe processes your action.** If you capture, Stripe collects the funds. If you release, Stripe cancels the authorization and the customer's balance is restored.

---

## How SecureHold WP Creates the Hold

SecureHold WP creates a **separate** Stripe PaymentIntent for the security deposit. This is distinct from the PaymentIntent created by WooCommerce Stripe Gateway for the order payment.

In practical terms:
- The order payment (for the products) and the deposit hold are two separate transactions in Stripe.
- The deposit uses the same payment method the customer provided at checkout, so no second card entry is required.
- The deposit PaymentIntent will appear in your Stripe Dashboard alongside the order payment, but as a separate entry.

---

## What the Customer Sees

**At checkout:**
An optional notice (configured in SecureHold WP settings) informs the customer that a security deposit will be placed on their card. This is informational only; no action is required.

**On their bank statement:**
The deposit amount appears as a pending authorization, separate from the order total charge. Most banks display it as a pending transaction or a hold.

**When the hold is released:**
The pending authorization disappears. No charge has been made.

**When the hold is captured:**
The pending authorization becomes a completed charge on their statement.

---

## What You Control

As the merchant, you control:

- **Whether a hold is created** — via deposit rules (global, product, or category level).
- **The deposit amount** — a fixed amount or a percentage of the order total.
- **When the hold is created** — immediately after the order, after a delay, before a specific date, or manually.
- **What happens to the hold** — you decide to capture or release, from the WooCommerce admin.

What you cannot control:
- Stripe's authorization window. Stripe enforces a maximum hold duration of approximately 7 days before the hold expires. See [7-DAY-EXPIRATION.md](7-DAY-EXPIRATION.md).

---

## Webhooks — How Status Updates Reach SecureHold WP

Stripe sends real-time notifications (webhooks) to your site when a hold's status changes. SecureHold WP listens for these events and updates the deposit status in your admin accordingly.

The four events SecureHold WP listens for:

| Stripe event | What it means | SecureHold status |
|---|---|---|
| `payment_intent.amount_capturable_updated` | Hold authorized | Authorized |
| `payment_intent.succeeded` | Hold captured | Captured |
| `payment_intent.canceled` | Hold released or expired | Released |
| `payment_intent.payment_failed` | Authorization failed | Failed |

For this to work, your webhook must be configured correctly. See the [SETUP-WIZARD.md](SETUP-WIZARD.md) for setup instructions.

---

## Summary

| Concept | Plain Explanation |
|---|---|
| Hold / Authorization | Funds reserved on the card — not charged |
| Capture | Funds charged — money moves to your account |
| Release | Reservation removed — no charge, funds returned |
| PaymentIntent | Stripe's internal record for the hold transaction |
| Webhook | Stripe notifies your site of status changes in real time |
| 7-day window | Stripe cancels unheld PaymentIntents after ~7 days |
