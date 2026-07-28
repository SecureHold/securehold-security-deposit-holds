# Security Policy

## Supported Versions

Only the latest stable version of SecureHold WP, as published on the [WordPress.org plugin directory](https://wordpress.org/plugins/securehold-security-deposit-holds/), is supported with security fixes. Older versions are not maintained; please update to the latest release before reporting an issue.

## Reporting a Vulnerability

If you believe you have found a security vulnerability in SecureHold WP, please report it privately by email to **support@secureholdwp.com**, rather than through a public GitHub Issue.

Please include, if possible:

- A description of the vulnerability and its potential impact
- Steps to reproduce it
- The plugin version and environment (WordPress, WooCommerce, PHP versions) where it was observed

We will acknowledge your report and work with you on a fix and, where appropriate, a coordinated disclosure timeline.

## Do Not Include Sensitive Data in Public Reports

Never post the following in a public GitHub Issue, pull request, or discussion:

- Stripe API keys or secret keys
- Webhook signing secrets
- Customer data
- Payment information
- Order identifiers containing personal data

If a report accidentally includes any of the above, contact us immediately so the information can be removed and, if necessary, the affected credentials rotated.
