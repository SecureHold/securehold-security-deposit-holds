# SecureHold WP — Quick Start Guide

This guide takes you from a fresh installation to your first verified security deposit hold. Follow each step in order. Do not skip the test phase before going live.

---

## Prerequisites

Before installing, confirm the following:

- WordPress is installed and running
- WooCommerce is installed and active
- The official WooCommerce Stripe Gateway plugin is installed and active
- You have a Stripe account (a free account is sufficient to start)
- Your Stripe account is in **test mode** for initial setup

If any of these are missing, see [BEFORE-YOU-BEGIN.md](BEFORE-YOU-BEGIN.md).

---

## Step 1 — Install and Activate SecureHold WP

**Automatic installation (recommended):**

1. In your WordPress admin, go to **Plugins → Add New**.
2. Search for **SecureHold WP**.
3. Click **Install Now**, then **Activate**.

**Manual installation:**

1. Download the plugin ZIP from your account or from WordPress.org.
2. Go to **Plugins → Add New → Upload Plugin**.
3. Upload the ZIP and click **Install Now**, then **Activate**.

After activation, you will be prompted to run the Setup Wizard. Click **Run the Setup Wizard** to continue.

---

## Step 2 — Run the Setup Wizard

The Setup Wizard walks you through the essential configuration in seven steps. For a full reference of each step, see [SETUP-WIZARD.md](SETUP-WIZARD.md).

### Step 1: Welcome

An introduction to SecureHold WP. Read the overview and click **Get Started**.

### Step 2: Required Plugins

The wizard checks that WooCommerce and the WooCommerce Stripe Gateway are both installed and active. If either is missing, you can install them directly from this screen.

- Both must show a green checkmark before you can continue.

### Step 3: WooCommerce Configuration

The wizard verifies that WooCommerce is configured correctly: currency, checkout settings, and payment gateway. Review any warnings and resolve them before proceeding.

### Step 4: Stripe SDK

SecureHold WP includes the Stripe PHP SDK. This step confirms the SDK is present and ready.

- If you see a warning here, your plugin installation may be incomplete. Download a fresh copy from your account and reinstall.

### Step 5: API Keys

Enter your Stripe API keys.

**Start in test mode:**

1. Log in to your [Stripe Dashboard](https://dashboard.stripe.com).
2. Make sure **Test mode** is enabled (toggle in the top-right of the Stripe Dashboard).
3. Go to **Developers → API Keys**.
4. Copy your **Test Publishable Key** and **Test Secret Key**.
5. Paste them into the corresponding fields in the wizard.
6. Leave the mode selector set to **Test**.

### Step 6: Webhook

Webhooks allow Stripe to notify SecureHold WP when a hold is authorized, captured, released, or fails.

**In your Stripe Dashboard (test mode):**

1. Go to **Developers → Webhooks**.
2. Click **Add endpoint**.
3. Enter your webhook URL:
   ```
   https://yoursite.com/wp-json/securehold/v1/webhook
   ```
   Replace `yoursite.com` with your actual domain.
4. Under **Events to listen to**, add these four events:
   - `payment_intent.amount_capturable_updated`
   - `payment_intent.succeeded`
   - `payment_intent.canceled`
   - `payment_intent.payment_failed`
5. Click **Add endpoint**.
6. On the webhook detail page, reveal and copy the **Signing secret** (starts with `whsec_`).
7. Paste it into the **Webhook Signing Secret** field in the wizard.

### Step 7: Done

Setup is complete. Click **Go to Dashboard** to begin.

---

## Step 3 — Configure Your First Deposit

Before placing a test order, set a deposit amount.

1. Go to **SecureHold WP → Settings**.
2. Click the **Deposit Rules** tab.
3. Under **Global Settings**, set a **Default Hold Amount** (for example, `100`).
4. Leave all other settings at their defaults for now.
5. Click **Save Changes**.

---

## Step 4 — Place a Test Order

1. Open your WooCommerce store in a browser (or a private/incognito window).
2. Add any product to the cart and proceed to checkout.
3. Select **Stripe** as the payment method.
4. Use the following Stripe test card:

   | Field | Value |
   |---|---|
   | Card number | `4242 4242 4242 4242` |
   | Expiry | Any future date (e.g. `12/34`) |
   | CVC | Any 3 digits (e.g. `123`) |
   | Postcode | Any valid postcode |

5. Complete the order.

---

## Step 5 — Verify the Hold

**In SecureHold WP:**

1. Go to **SecureHold WP → Deposits**.
2. Your test order should appear with status **Authorized**.
3. Click the order row to open the deposit details.

**In your Stripe Dashboard (test mode):**

1. Go to **Payments → PaymentIntents**.
2. Find the entry for your test order's hold. It should show **Requires Capture**.

**In WooCommerce:**

1. Open the order in **WooCommerce → Orders**.
2. The **SecureHold WP** metabox should show the authorized hold amount.

If the hold does not appear, see [HOLD-CREATION-FAILURE-FLOW.md](HOLD-CREATION-FAILURE-FLOW.md).

---

## Step 6 — Test a Release

1. In **SecureHold WP → Deposits**, find your test hold.
2. Click **Release** (either from the deposits list kebab menu or from the deposit details page).
3. Confirm the release.

**Expected result:**

- SecureHold status changes to **Released**.
- Stripe shows the PaymentIntent as **Canceled**.
- No funds were moved.

---

## Success Checklist

Before moving on, confirm every item below:

- [ ] Plugin installed and activated without errors
- [ ] Setup Wizard completed all 7 steps
- [ ] API keys entered and saved (test mode)
- [ ] Webhook configured in Stripe test dashboard with correct URL and signing secret
- [ ] Default deposit amount configured
- [ ] Test order placed using the `4242 4242 4242 4242` test card
- [ ] Hold appears in SecureHold → Deposits with status **Authorized**
- [ ] Stripe PaymentIntent shows **Requires Capture**
- [ ] WooCommerce order metabox shows the hold
- [ ] Release test completed — status changed to **Released** / Stripe shows **Canceled**

If all items are checked, your test setup is working correctly.

---

## Common Mistakes at This Stage

**Using live API keys during testing.**
Always use test keys during setup. Live keys create real holds on real credit cards.

**Skipping the webhook step.**
Without a webhook, SecureHold WP cannot receive real-time status updates from Stripe. Hold statuses will not update automatically.

**Entering the wrong webhook signing secret.**
The signing secret is separate from your API keys. It starts with `whsec_` and is found on the webhook endpoint detail page in Stripe, not on the API Keys page.

**Placing a test order without the Stripe test card.**
Real card numbers will not work in Stripe test mode. Use `4242 4242 4242 4242`.

---

## Next Step — Going Live

When your test setup is fully working and you are ready to accept real deposits:

→ Follow the [GOING-LIVE-CHECKLIST.md](GOING-LIVE-CHECKLIST.md) before switching modes.

Do not switch to live mode until the test success checklist above is complete.
