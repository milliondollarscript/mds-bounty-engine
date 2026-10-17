# Changelog

## Unreleased

- Added opt-in uninstall cleanup through the core extension policy for extension-owned boards, bounties, claims, settings, transients, and schedules.

## 0.2.0 - 2026-07-13

- Open existing boards on the Bounties queue while keeping new board creation on Board settings.
- Split board configuration from paginated bounty and claim moderation with directly addressable Board settings and Bounties tabs.
- Added locked capacity enforcement and accurate bounty and claim counts without 100-record truncation.
- Added strict shared validation for active boards, claim eligibility, calendar dates, HTTP/HTTPS links, required email addresses, and explicit no-escrow terms acceptance.
- Added configurable hourly submission limits using one-way IP-derived visitor keys.
- Added clear provider checkout failure handling so failed checkout never appears successful or public.
- Limited public REST responses to active boards and payment-eligible bounties, removed requester names, and added pagination metadata.
- Added paginated WordPress privacy exports and erasure plus scheduled per-board retention cleanup.
- Added direct WordPress email notifications for bounty and claim submissions.
- Added paginated admin moderation, flattened claim rows, responsive public pagination, stable checkbox geometry, and explicit semantic notice colors for light and dark modes.

## 0.1.1 - 2026-07-13

- Published each REST route through the flat Million Dollar Script endpoint manifest.
- Matched discovery security levels to the public-read and administrator-only runtime callbacks.
- Removed private contact, retention, payment, and charged-amount fields from public REST responses.
- Added manifest and public-payload regression coverage.

## 0.1.0

- Added the initial Bounty Engine extension.
