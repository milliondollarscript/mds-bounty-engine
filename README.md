# Million Dollar Script - Bounty Engine

Bounty Engine is a local-first Million Dollar Script 3.0 extension for running public bounty, microgrant, and request boards without taking custody of reward funds.

## Features

- Bounty boards with posting fees, featured fees, atomic capacity limits, disclosure text, contact email, terms URL, private-data retention, and configurable hourly submission limits.
- Public bounty request form with nonce protection, honeypot spam reduction, explicit terms acceptance, strict date/link/email validation, and one-way IP-derived rate limiting.
- Public claim/submission forms for approved open bounties.
- Admin moderation for bounty approval, rejection, in-review state, completion, archive, and claim approval/rejection.
- Optional MDS 3.0 payment-provider checkout for posting/featured fees without direct WooCommerce calls.
- Independent moderation and payment gates prevent provider-mode listings from appearing until both requirements are satisfied, regardless of event order.
- Currency defaults from the active MDS 3.0/WooCommerce currency instead of hardcoded USD.
- Paginated privacy export and erasure hooks plus scheduled per-board retention cleanup.
- Paginated REST read endpoints for active boards and eligible bounties plus admin-scoped write/moderation endpoints registered in the Million Dollar Script API manifest.
- Direct WordPress email notifications for new bounty and claim submissions.
- MDS2-compatible submenu fallback for older installs.

## Shortcode

```text
[mds_bounty_engine id="1"]
```

## REST API

- `GET /wp-json/million-dollar-script/v1/bounty-engine/boards`
- `GET /wp-json/million-dollar-script/v1/bounty-engine/boards/{id}/bounties`
- `POST /wp-json/million-dollar-script/v1/bounty-engine/bounties` (admin-scoped)
- `POST /wp-json/million-dollar-script/v1/bounty-engine/bounties/{id}/moderate` (admin-scoped)
- `POST /wp-json/million-dollar-script/v1/bounty-engine/claims` (admin-scoped)
- `POST /wp-json/million-dollar-script/v1/bounty-engine/claims/{id}/moderate` (admin-scoped)

## Shared-Host Notes

The MVP does not require image generation, queues, payouts, escrow, or long-running jobs. If admins later need high-volume fraud review, escrow-like workflows, generated promo assets, or partner verification, those are better candidates for a separate hosted service after legal and compliance review.

## Changelog

### 0.2.0

- Added atomic capacity enforcement, strict lifecycle validation, explicit terms acceptance, configurable hourly throttling, checkout failure handling, pagination, privacy retention, and semantic light/dark status styling.

### 0.1.0

- Initial local-first Bounty Engine implementation.
