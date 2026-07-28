# The 7-Day Expiration Rule — What You Must Know

> ⚠️ **This is one of the most important operational facts about Stripe security deposit holds. Read this before accepting your first real hold.**

---

## What Happens After 7 Days

Stripe authorization holds do not last indefinitely. Stripe enforces a maximum authorization window of approximately **7 days** from the moment the hold is created.

If you have not captured or released the hold before the 7-day window closes:

- **Stripe automatically cancels the authorization.** The hold expires.
- The customer's funds are released back to them. No charge is made.
- In SecureHold WP, the deposit status updates to **Released** (via webhook).
- You can no longer charge the customer through this hold.

This happens automatically and silently. SecureHold WP does not currently send a pre-expiration alert when a hold is approaching its deadline.

---

## Why Does Stripe Do This?

This is a Stripe platform rule, not a SecureHold WP limitation. Stripe (and the card networks behind it — Visa, Mastercard, etc.) set a maximum window for pre-authorization holds to protect cardholders. After this window, the reservation expires and the card issuer releases the funds.

**SecureHold WP cannot extend this window.** The 7-day limit is enforced by Stripe and by your customer's bank.

---

## What This Means for Your Business

You need an **operational process** to ensure every active hold is either captured or released before 7 days pass.

If your workflow requires holding a deposit for more than 7 days (for example, a two-week equipment rental), you have two options:

1. **Capture the deposit at the start of the rental period.** If there is no damage, issue a refund at the end. This converts the hold into a real charge from day one.

2. **Release and re-authorize closer to the end of the rental.** This requires creating a new hold manually when the original is approaching expiry. Note: this requires the customer's payment method to still be reusable.

Neither option is perfect. The right choice depends on your business model and your relationship with your customers. Plan this workflow before you go live.

---

## What You Should Do in Practice

**Set a reminder or process for every active hold.**

At minimum, before going live, establish an internal process:
- Review active holds in **SecureHold WP → Deposits** at least every 5 days.
- Filter by status **Authorized** to see only active holds that need attention.
- Capture or release any hold that is approaching 7 days.

**Use auto-release if appropriate (PRO).**

If your typical workflow ends with releasing the deposit (no damage), the PRO version of SecureHold WP can automatically release holds after a configurable number of days. This prevents holds from expiring silently.

Auto-release fires a day or more before your chosen interval, giving Stripe time to process the cancellation cleanly. Configure it in **SecureHold WP → Settings → Deposit Rules → Global Settings**.

**Check expiration dates in the deposit details.**

Each deposit detail page shows the authorization date. Count 7 days from that date to determine the expiration deadline.

---

## If a Hold Has Already Expired

If a hold expired before you could capture it:

1. The funds cannot be recovered through that hold. It is gone.
2. You may create a new manual hold on the order — but this requires the customer's payment method to still be saved and reusable. Go to the WooCommerce order and use the **Create Hold** option in the SecureHold WP metabox.
3. If the payment method is no longer available, you will need to collect a new payment from the customer through other means.

This is why it is critical to act within the 7-day window. Expired holds cannot be reactivated.

---

## Summary

| Fact | Detail |
|---|---|
| Hold duration | Approximately 7 days from creation |
| Who enforces it | Stripe and the card networks |
| What happens at expiry | Hold is automatically canceled, funds released |
| Can SecureHold extend it | No |
| Can you recover an expired hold | No — you must create a new hold if the PM is still available |
| Best practice | Capture or release every hold within 5–6 days to be safe |
| PRO option | Auto-release after a configurable number of days |

---

## Related

- [CAPTURE-VS-RELEASE.md](CAPTURE-VS-RELEASE.md) — how to capture or release a hold before it expires
- [HOW-STRIPE-HOLDS-WORK.md](HOW-STRIPE-HOLDS-WORK.md) — how Stripe pre-authorization works
