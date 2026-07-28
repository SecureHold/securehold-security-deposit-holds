# Hold Not Created — Diagnosis Guide

Use this guide if a security deposit hold was not created after a customer placed an order.

Work through each section in order. Most issues are resolved by the first three checks.

---

## Quick Symptom Summary

You are on the right page if:
- An order was placed and paid via Stripe, but no hold appears in **SecureHold WP → Deposits**.
- A hold exists in the Deposits list but shows status **Failed** or **Pending Manual**.
- The order metabox shows no deposit information.

---

## Check 1 — Did the Order Pay Successfully?

Go to **WooCommerce → Orders** and open the order in question.

- [ ] The order status is **Processing** or **Completed** (not **Pending** or **Failed**).
- [ ] The payment method shown is **Stripe** (or a Stripe variant such as Stripe Credit Card).

**If the order payment failed or is pending:** SecureHold WP cannot create a hold on an unpaid order. This is a WooCommerce payment issue, not a SecureHold issue. Resolve the payment first.

**If the payment method is not Stripe:** SecureHold WP only creates holds for orders paid via Stripe. Orders paid via other gateways (PayPal, cash on delivery, etc.) will not generate a hold.

---

## Check 2 — Does the Hold Exist but Is Hidden or Filtered?

Go to **SecureHold WP → Deposits** and clear all active filters (status, date range, search).

- [ ] The deposit does not appear even with all filters cleared.
- [ ] Alternatively, it appears with a status you did not expect (Failed, Pending Manual, Scheduled).

**If the deposit exists with status Scheduled (PRO):** The hold is waiting for its configured timing condition to be met (delay, date, status trigger). This is expected behavior.

**If the deposit exists with status Failed:** Continue to Check 5.

**If the deposit exists with status Pending Manual:** The timing strategy is set to Manual. You must create the hold manually. Open the WooCommerce order, find the SecureHold WP metabox, and click **Create Hold**.

---

## Check 3 — Is the Product Excluded?

Go to **SecureHold WP → Settings → Deposit Rules**.

- [ ] The product is not listed in the **Excluded Products** field.
- [ ] The product's category is not listed in the **Excluded Categories** field.

**If the product is excluded:** Excluded products and categories do not generate holds. Remove the exclusion if you want holds for this product, or keep it if the exclusion is intentional.

---

## Check 4 — Does the Order Meet the Minimum Cart Amount?

Go to **SecureHold WP → Settings → Deposit Rules**.

- [ ] The **Minimum Cart Amount** field is empty, or the order total is above the configured minimum.

**If the order total is below the minimum:** SecureHold WP will not create a hold for orders that do not reach the minimum threshold. Either lower the minimum or increase the order amount.

---

## Check 5 — Is There a Failed Hold? What Does It Say?

Go to **SecureHold WP → Deposits** and find the failed deposit. Click **View Details**.

Look at the **Notes** or **Status** section for an error message. Common failure reasons:

**"Payment method is single-use"**
The customer's payment method was not configured to be reusable during checkout. This is the most common failure mode for guest orders.
→ See [SINGLE-USE-PAYMENT-METHOD.md](SINGLE-USE-PAYMENT-METHOD.md) for diagnosis and next steps.

**"No Stripe customer ID found"**
SecureHold WP could not find the Stripe customer associated with the order. This can happen if the WooCommerce Stripe Gateway did not create a Stripe customer at checkout.

What to check:
- Open the WooCommerce order and look for the meta value `_stripe_customer_id`. If it is empty, Stripe did not save a customer for this order.
- This is more common with guest orders and some WooCommerce Stripe Gateway versions.

**"No payment method found"**
SecureHold WP could not identify a reusable payment method on the order.

What to check:
- Open the WooCommerce order and look for `_stripe_payment_method` or `_stripe_payment_method_id` in the order meta.
- If empty, Stripe did not save the payment method. This is related to the single-use payment method issue — see [SINGLE-USE-PAYMENT-METHOD.md](SINGLE-USE-PAYMENT-METHOD.md).

**An API error message from Stripe**
Stripe rejected the hold creation request.

What to check:
- Confirm your Stripe API keys are correct and match the current mode (test or live).
- Check that the keys are entered in **SecureHold WP → Settings → Connection**, not only in WooCommerce Stripe settings.
- Enable debug logging (next check) to see the full error.

---

## Check 6 — Enable Debug Logging

If the cause is not yet clear, enable detailed logging.

1. Go to **SecureHold WP → Settings → Connection**.
2. Enable **Debug Logging**.
3. Click **Save Changes**.
4. Place a new test order and trigger the hold creation again.
5. Check the logs in **WooCommerce → Status → Logs** — select the `securehold-stripe-deposits` log source.

Look for lines marked `[error]` or `[warning]`. The log entries will show exactly where the hold creation process failed.

After diagnosis, disable debug logging to keep your logs clean.

---

## Check 7 — Is There a Stripe Mode Mismatch?

Go to **SecureHold WP → Settings → Connection**.

- [ ] The mode indicator shows no alignment warning.
- [ ] SecureHold WP and WooCommerce Stripe Gateway are both in the same mode (both test or both live).

If a yellow warning banner is shown indicating a mode mismatch, resolve it by aligning both plugins to the same mode. Mismatched modes cause holds to fail silently.

---

## Manual Retry

If you have identified and resolved the root cause, you can attempt to create the hold manually:

1. Open the WooCommerce order.
2. Find the **SecureHold WP** metabox.
3. If no hold exists, click **Create Hold**.

Manual hold creation requires the customer's payment method to still be saved and reusable on their Stripe customer record.

---

## When to Contact Support

Contact support if:
- You have worked through all checks above and the issue is not resolved.
- Debug logs show an error you do not understand.
- The hold fails consistently for all orders, not just one.

Before contacting support, export a Support Bundle from **SecureHold WP → Tools → System** and have it ready to share. See [GETTING-SUPPORT.md](GETTING-SUPPORT.md) for what to include in your support request.
