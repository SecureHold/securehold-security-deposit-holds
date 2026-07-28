# What Is SecureHold WP?

## In Plain Terms

SecureHold WP is a WordPress plugin that lets you collect a **security deposit** from customers when they place a WooCommerce order — without charging them upfront.

It works by placing a **hold** on the customer's credit or debit card through Stripe. Think of it like the hold a hotel puts on your card at check-in. The money is reserved but not taken. At the end of the stay, the hotel either charges the deposit or releases it. SecureHold WP gives you the same capability for your WooCommerce store.

---

## What Is a Security Deposit Hold?

A security deposit hold is a **temporary reservation** of funds on a payment card. Here is what it means in practice:

- The customer's bank marks the hold amount as unavailable.
- No money leaves the customer's account.
- You (the merchant) control what happens next: you can **capture** (take the money) or **release** (cancel the reservation).
- If you do nothing, Stripe cancels the hold automatically after approximately 7 days.

> **A hold is not a charge.** The customer is not billed when the hold is created. Money only moves if you explicitly capture it.

---

## Hold vs. Charge — The Difference

| | Hold | Charge |
|---|---|---|
| Money leaves the customer's account | No | Yes |
| Appears on the customer's statement | Yes (as pending) | Yes (as completed) |
| You control the outcome | Yes — capture or release | Already settled |
| Reversible | Yes — release it | No (requires a refund) |
| Expires automatically | Yes — ~7 days | No |

---

## How SecureHold WP Works — Three Steps

**1. Customer checks out as normal.**
The customer pays for their order using Stripe (credit card, debit card). No extra step is required on their end.

**2. SecureHold WP places a hold in the background.**
Immediately after the order is placed, SecureHold WP creates a separate Stripe authorization for the deposit amount. This hold is invisible to the customer at checkout (except for an optional notice you can display).

**3. You capture or release when the time comes.**
From your WooCommerce admin, you can:
- **Capture** the hold — the deposit amount is charged to the customer's card.
- **Release** the hold — the reservation is removed and the customer's funds are fully available again.

---

## Who Is SecureHold WP For?

SecureHold WP is built for businesses that need financial protection before delivering a service or product. Common use cases:

**Equipment and vehicle rental**
Collect a damage deposit at booking. Release it when the equipment is returned in good condition, or capture it if there is damage.

**Short-term accommodation**
Place a security deposit hold at reservation. Release it after checkout, or capture it to cover unreported damage.

**Event venues**
Require a refundable deposit for bookings. Release it after the event, or charge it if there is damage to the venue.

**Professional services**
Collect a commitment deposit when a client books. Capture it if the client cancels late, or release it if the service is completed as agreed.

---

## What the Customer Experiences

From the customer's perspective, the process is straightforward:

1. They shop and check out exactly as they would on any WooCommerce store.
2. An optional notice at checkout informs them that a security deposit will be placed on their card.
3. Their bank statement shows the hold as a pending authorization (not a charge).
4. When you release the hold, the authorization disappears from their statement. When you capture, it becomes a completed charge.
5. With PRO, customers can view their deposit status in their My Account area.

Customers do not need to take any additional action. The hold is created automatically using the payment method they already provided at checkout.

---

## Is It Safe?

Yes. SecureHold WP uses Stripe's official pre-authorization API. The customer's card details are never stored by the plugin — they are handled entirely by Stripe, which is a PCI DSS Level 1 certified payment processor.

The hold uses the same payment method the customer used at checkout. No second card entry is required.

---

## FREE and PRO

SecureHold WP is available in a free version and a PRO version.

**FREE** includes everything you need to start collecting security deposits: immediate and manual hold timing, global and product/category deposit rules, guest checkout support, and a checkout notice.

**PRO** adds timing flexibility (delayed, scheduled, by order status), advanced rule engine options, auto-release automation, a customer-facing My Account deposits page, analytics, audit logs, and more.

See [README.md](README.md) for a full feature comparison.
