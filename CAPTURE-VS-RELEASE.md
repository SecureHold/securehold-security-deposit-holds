# Capture vs. Release — Understanding Your Two Options

When a security deposit hold is active (status: **Authorized**), you have exactly two options: capture the hold or release it. This page explains what each action does, when to use it, and what happens after.

---

## Capture — Charge the Deposit

**What it means:**
Capturing a hold transfers the reserved funds from the customer's card to your Stripe balance. The customer is charged the deposit amount.

**When to use it:**
- The customer caused damage to your property or equipment.
- The customer did not fulfill the agreed terms (late cancellation, no-show, etc.).
- You agreed in advance that the deposit is non-refundable upon completion.

**What happens after capture:**
- The deposit amount is transferred to your Stripe account.
- In SecureHold WP, the status changes to **Captured**.
- In Stripe, the PaymentIntent status changes to **Succeeded**.
- If you have email notifications configured, the customer receives a "deposit captured" notification.

> ⚠️ **Capture is effectively irreversible in this workflow.**
> Once captured, the funds have been charged. If you need to return the money, you must issue a separate refund via Stripe or WooCommerce. There is no "undo capture" button.

---

## Release — Return the Deposit

**What it means:**
Releasing a hold cancels the authorization. The reserved funds are freed and become fully available to the customer. No money is charged.

**When to use it:**
- The customer returned the equipment or property in good condition.
- The service was completed without incident.
- The customer cancelled within the allowed window.
- You no longer need the deposit for any reason.

**What happens after release:**
- No money moves. The customer is not charged.
- In SecureHold WP, the status changes to **Released**.
- In Stripe, the PaymentIntent status changes to **Canceled**.
- The pending authorization disappears from the customer's bank statement.
- If you have email notifications configured, the customer receives a "deposit released" notification.

> ℹ️ **Release is not a refund.** Because no money was ever charged, there is nothing to refund. The authorization is simply removed.

---

## Side-by-Side Comparison

| | Capture | Release |
|---|---|---|
| Funds move to your account | Yes | No |
| Customer is charged | Yes | No |
| Action is reversible | No (requires separate refund) | Not applicable |
| Stripe status after | Succeeded | Canceled |
| SecureHold status after | Captured | Released |
| Customer notification | Yes (if configured) | Yes (if configured) |

---

## How to Capture or Release

**From the Deposits list:**
1. Go to **SecureHold WP → Deposits**.
2. Find the deposit you want to act on.
3. Click the action menu (⋮) on the right side of the row.
4. Select **Capture** or **Release**.

**From the order metabox:**
1. Open the WooCommerce order in **WooCommerce → Orders**.
2. Find the **SecureHold WP** metabox on the order page.
3. Click **Capture** or **Release**.

**From the deposit details page:**
1. Go to **SecureHold WP → Deposits**.
2. Click **View Details** on the deposit row.
3. Use the action buttons in the header area.

---

## Before You Click — Quick Checklist

Before capturing or releasing, confirm the following:

**Before capturing:**
- [ ] You have verified the reason for keeping the deposit (damage, cancellation, etc.)
- [ ] You are acting on the correct order and deposit
- [ ] You have communicated with the customer if required
- [ ] You understand the capture cannot be undone without a separate refund

**Before releasing:**
- [ ] The service or rental has been completed satisfactorily
- [ ] No damage or outstanding charges remain
- [ ] You are acting on the correct order and deposit

---

## What If the Hold Has Expired?

If approximately 7 days have passed since the hold was created and you have not captured or released it, Stripe will have automatically canceled the authorization. The deposit status in SecureHold WP will show **Released** (updated via webhook).

At this point, the customer can no longer be charged through this hold. You would need to create a new hold manually, which requires the customer's payment method to still be available and reusable.

To avoid this situation, always capture or release holds before the 7-day window closes. See [7-DAY-EXPIRATION.md](7-DAY-EXPIRATION.md) for full details.
