# Single-Use Payment Method — What It Means and What to Do

This page explains a specific hold creation failure where the customer's payment method could not be reused for the deposit hold.

---

## What This Error Means

When a customer pays for a WooCommerce order via Stripe, their card is used for the order payment. For SecureHold WP to place a **separate** deposit hold, it needs to reuse that same card — without requiring the customer to enter their card details again.

This reuse is only possible if the card was saved as a **reusable payment method** during checkout.

If the card was not saved as reusable, it is treated as **single-use**: it was valid for one transaction only. SecureHold WP cannot use it again to create the deposit hold, and the hold fails.

---

## Why Does This Happen?

Stripe has a setting called `setup_future_usage` that must be set on the payment transaction during checkout. When this setting is present, Stripe saves the payment method and makes it available for future off-session use (such as placing a deposit hold).

SecureHold WP automatically attempts to configure this setting during every Stripe checkout using multiple layers of injection. In most cases, this works transparently. However, certain combinations of gateway versions, themes, or competing plugins can interfere with this process.

**Common causes:**

- **Guest checkout on certain WooCommerce Stripe Gateway versions.** Some older versions of the WooCommerce Stripe Gateway do not create a Stripe customer for guest orders. Without a Stripe customer, the payment method cannot be attached and saved for reuse.

- **A competing plugin modifies the Stripe payment flow.** If another plugin also hooks into Stripe's checkout process at a high priority, it may undo the payment method saving that SecureHold WP set up.

- **Incomplete or unusual payment flows.** Some payment methods or regional Stripe configurations behave differently from standard card checkout.

---

## What to Check

**Step 1: Confirm the error**

Go to **SecureHold WP → Deposits**, find the failed deposit, and click **View Details**. The failure reason should mention "single-use", "payment method not reusable", or a similar message.

**Step 2: Verify this affects all orders or just one**

- If only one order has this problem, it may be an isolated case related to that customer's payment method or card type. Try creating the hold manually (see below).
- If this affects every order, there is likely a configuration issue that needs to be resolved.

**Step 3: Check for competing plugins**

Deactivate other plugins that interact with Stripe or WooCommerce checkout (one at a time) and place a test order after each deactivation. If the hold succeeds after removing a specific plugin, that plugin is interfering with the payment method saving process.

**Step 4: Check the WooCommerce Stripe Gateway version**

Make sure you are using the latest version of the WooCommerce Stripe Gateway. Older versions have known limitations around payment method saving for guest checkout.

**Step 5: Enable debug logging**

1. Go to **SecureHold WP → Settings → Connection** and enable **Debug Logging**.
2. Place a test order.
3. Check the logs in **WooCommerce → Status → Logs** (source: `securehold-stripe-deposits`).
4. Look for lines referencing `PI Diagnosis`, `pm_reusable`, or `setup_future_usage`. These lines confirm whether the payment method saving succeeded or failed, and which stage of the process was responsible.

**Step 6: Use the Checkout Engine Status diagnostic (PRO)**

If you have SecureHold WP PRO, go to **SecureHold WP → Tools → Diagnostics → Checkout Engine Status** for a detailed report on how the payment method saving process performed during recent checkouts.

---

## Guest Checkout — Additional Context

Guest checkout holds are supported but require Stripe to save the payment method at checkout. When a guest checks out:

1. Stripe creates a PaymentIntent for the order.
2. SecureHold WP ensures the payment method is configured as reusable.
3. SecureHold WP creates a Stripe Customer in the background and attaches the payment method to that customer.
4. The deposit hold is created using the attached payment method.

If step 2 fails (Stripe did not mark the payment method as reusable), steps 3 and 4 cannot succeed. The hold fails with a single-use error.

This is the most common cause of failed holds for guest orders.

---

## Retry Guidance

**If the payment method issue is resolved**, you can attempt to create the hold manually:

1. Open the WooCommerce order that had the failed hold.
2. Find the **SecureHold WP** metabox.
3. Click **Create Hold**.

Manual hold creation will succeed only if the payment method is now saved and reusable on the customer's Stripe record. If it is still single-use, the retry will also fail.

**If the customer's payment method cannot be reused**, you will need to collect a new payment from the customer through other means (a separate Stripe payment link, invoice, etc.).

---

## When to Contact Support

Contact support if:
- This error is happening for all orders, not just isolated cases.
- You have disabled competing plugins and the issue persists.
- Debug logs show an error you cannot interpret.

Before contacting support, collect the following:
- The WooCommerce order ID
- The SecureHold WP deposit ID (from the Deposits list)
- Relevant log entries (from WooCommerce → Status → Logs, source: securehold-stripe-deposits)
- Whether the affected order was a guest order or a logged-in customer order
- The version of WooCommerce Stripe Gateway installed

See [GETTING-SUPPORT.md](GETTING-SUPPORT.md) for full guidance on preparing a support request.
