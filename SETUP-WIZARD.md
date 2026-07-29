# Setup Wizard — Step-by-Step Reference

The Setup Wizard appears automatically after you activate SecureHold WP for the first time. It guides you through the seven essential configuration steps.

You can also access the wizard at any time from **SecureHold WP → Setup Wizard** in the WordPress admin menu.

---

## Step 1 — Welcome

**Purpose:** Introduction to SecureHold WP and an overview of what the wizard will help you configure.

**What to do:** Read the welcome message and click **Get Started** to proceed.

**Success looks like:** The wizard advances to Step 2.

---

## Step 2 — Required Plugins

**Purpose:** Verify that WooCommerce and the WooCommerce Stripe Gateway are installed and active. Both are required for SecureHold WP to function.

**What the wizard checks:**
- WooCommerce — installed and active
- WooCommerce Stripe Gateway — installed and active

**What to do:**
- If both show a green checkmark, click **Continue**.
- If either shows a warning or error, click the **Install & Activate** button next to the missing plugin. The wizard will download and activate it automatically.

**Success looks like:** Both plugins show as installed and active. The **Continue** button becomes available.

**Common problem:** The WooCommerce Stripe Gateway is installed but not active. Click **Activate** next to it. You do not need to reinstall it.

---

## Step 3 — WooCommerce Configuration

**Purpose:** Verify that WooCommerce is set up correctly: currency, payment methods, and general configuration.

**What to do:** Review any items flagged by the wizard. If WooCommerce Stripe is not yet configured with your Stripe account, complete that setup first, then return to this step.

**Success looks like:** No red warnings. Proceed to Step 4.

**Common problem:** WooCommerce Stripe Gateway is installed but has no API keys entered. Go to **WooCommerce → Settings → Payments → Stripe** and add your Stripe keys there, then come back.

---

## Step 4 — Stripe SDK

**Purpose:** Confirm that the Stripe PHP SDK is bundled and available. The SDK is required for SecureHold WP to communicate with the Stripe API.

**What to do:** This step is automatic. The wizard checks whether the SDK files are present.

**Success looks like:** A green confirmation box: "Stripe SDK is bundled and ready."

**Common problem:** A warning states that SDK files are missing. This usually means the plugin was installed from an incomplete or corrupted ZIP file.

Fix: Download a fresh copy of SecureHold WP from your account or from WordPress.org, and reinstall via **Plugins → Add New → Upload Plugin**.

---

## Step 5 — API Keys

**Purpose:** Connect SecureHold WP to your Stripe account by entering your Stripe API keys.

**What to do:**

1. Log in to your [Stripe Dashboard](https://dashboard.stripe.com).
2. Confirm that **Test mode** is enabled (recommended for initial setup) using the toggle in the top-right corner.
3. Go to **Developers → API Keys**.
4. Copy the **Publishable key** (starts with `pk_test_`).
5. Copy the **Secret key** (starts with `sk_test_`). Click **Reveal** if it is hidden.
6. In the wizard, select **Test** as the mode.
7. Paste the publishable key and secret key into the corresponding fields.
8. Click **Save and Continue**.

**Success looks like:** Keys are saved and the wizard advances to Step 6. A confirmation message confirms the mode is set to Test.

**Common problem:** Keys are rejected or the wizard shows an API error. Double-check that you copied the full key (they are long strings starting with `pk_test_` and `sk_test_`). Make sure you are using test keys, not live keys.

---

## Step 6 — Webhook

**Purpose:** Configure a Stripe webhook so that SecureHold WP receives real-time notifications when a hold is authorized, captured, released, or fails.

**Your webhook URL:**
```
https://yoursite.com/wp-json/securehold/v1/webhook
```
Replace `yoursite.com` with your actual domain.

**What to do:**

**In your Stripe Dashboard (keep this open alongside WordPress):**

1. Go to **Developers → Webhooks**.
2. Click **Add endpoint**.
3. Paste your webhook URL into the **Endpoint URL** field.
4. Under **Select events**, add the following four events:
   - `payment_intent.amount_capturable_updated`
   - `payment_intent.succeeded`
   - `payment_intent.canceled`
   - `payment_intent.payment_failed`
5. Click **Add endpoint** to save.
6. On the webhook detail page that opens, find the **Signing secret** section and click **Reveal**.
7. Copy the signing secret (starts with `whsec_`).

**In the SecureHold WP wizard:**

8. Paste the signing secret into the **Webhook Signing Secret** field.
9. Click **Save and Continue**.

**Success looks like:** The signing secret is saved and the wizard advances to Step 7.

**Common problem:** Entering the API secret key instead of the webhook signing secret. These are different values. The signing secret is found on the webhook endpoint detail page in Stripe, not on the API Keys page. It starts with `whsec_`.

**Common problem:** The site is on a local development environment (localhost). Stripe cannot reach a localhost URL. For local testing, you can use a tunneling service such as ngrok to expose your local site to the internet.

---

## Step 7 — Done

**Purpose:** Confirm that setup is complete and guide you to the next step.

**What to do:** Click **Go to Dashboard** to open the SecureHold WP dashboard, or click **Configure Settings** to fine-tune your deposit rules.

**Recommended next action:** Before configuring deposit rules, run a test order to confirm the basic setup is working. See [FIRST-TEST-HOLD-CHECKLIST.md](FIRST-TEST-HOLD-CHECKLIST.md).

---

## After the Wizard

Once the wizard is complete, the following settings are ready:

- Stripe API keys (test mode)
- Webhook endpoint and signing secret
- Default deposit amount (set to a placeholder; update this in Settings → Deposit Rules)

**What to configure next:**

1. Go to **SecureHold WP → Settings → Deposit Rules** and set your default hold amount and timing strategy.
2. Optionally configure product-level or category-level deposit rules if you need different amounts per product type.
3. Run a test order to verify the full flow. See [FIRST-TEST-HOLD-CHECKLIST.md](FIRST-TEST-HOLD-CHECKLIST.md).
4. When ready for production, follow [GOING-LIVE-CHECKLIST.md](GOING-LIVE-CHECKLIST.md).
