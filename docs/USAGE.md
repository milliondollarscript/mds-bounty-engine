# Bounty Engine

Bounty Engine lets a site owner run public bounty, microgrant, request, and challenge boards. It is local-first and does not take custody of reward funds.

## Practical Examples

- An open-source project can publish documentation, design, testing, or bug-fix bounties and review claims before confirming completion.
- A community organization can accept small project proposals for a microgrant program while keeping applications, deadlines, and decisions in WordPress.
- A business can run a public challenge board for research requests, creative briefs, or data contributions without presenting the site as an escrow service.

### Example workflow: a community microgrant board

1. Open **Million Dollar Script -> Bounty Engine**, create an active board, set its capacity and claim rules, and choose Manual payment handling.
2. Publish the **Bounty Board** block with request and claim forms enabled.
3. Review a submitted proposal under **Bounties**, approve it for public display, and review any later claims separately.
4. After the work is verified and any payment is handled outside the extension, mark the bounty complete so the public board reflects the final outcome.

## Setup

1. Activate Million Dollar Script and Bounty Engine.
2. Open **Million Dollar Script -> Bounty Engine**.
3. Review the seeded board or create a new board.
4. Set the board status, slug, description, posting fee, featured fee, capacity, claim settings, disclosure text, terms URL, contact email, retention period, and hourly submission limit.
5. Choose a payment mode:
   - **Manual** for review or payment handled outside automated checkout.
   - **Provider** for checkout through the active Million Dollar Script payment provider.
6. Add the board to a page with the block or shortcode.

## Admin Workspace

Existing boards open on **Bounties** so bounties and claims awaiting review are visible first. Use **Board settings** to create or configure a board. The active board and tab remain in the URL for direct links and pagination.

## Public Display

Use the **Bounty Board** block from the Million Dollar Script block category, or add:

```text
[mds_bounty_engine id="1"]
```

The block can select an existing board by ID. The slug field is optional and is only used when no board ID is selected.

Display options include:

- Show the public bounty request form.
- Show claim forms for open bounties.
- Show the board notice and disclosure text.

Public boards and bounty lists are paginated. Paused, draft, and archived boards are not returned by the public display or public REST endpoints.

## Moderation Workflow

Submitted bounties are held for review. Administrators can approve, reject, mark in review, complete, or archive entries. Claims can also be reviewed and approved or rejected.

Every public bounty or claim submission must accept the board's terms and no-escrow disclosure. Due dates must be real calendar dates that are not in the past, and submitted links must use HTTP or HTTPS. Claim eligibility is checked again when the record is saved, including the board state, claim setting, bounty deadline, moderation state, publication state, and payment state.

Board capacity is enforced while holding a database lock, which prevents simultaneous requests from exceeding the configured limit. The hourly submission setting applies to the combined bounty and claim traffic from a one-way IP-derived visitor key. Increase it only when a board legitimately expects frequent submissions from a shared network.

Featured entries can use an additional fee. Currency is read from Million Dollar Script or WooCommerce when available instead of being hardcoded.

In provider mode, moderation and payment are separate requirements. A bounty appears publicly only after an administrator approves it and its payment reaches a paid, processing, or completed state. These events can arrive in either order. Rejected, cancelled, failed, or refunded payments remove the bounty from public display without erasing its moderation record.

If checkout cannot be started, the bounty remains private with a failed payment state and the visitor receives a clear message that no charge was made. The listing is never reported as successfully paid or published.

## Payments

Bounty Engine uses the Million Dollar Script payment-provider API when provider checkout is enabled. It does not call WooCommerce, Stripe, Easy Digital Downloads, or other gateways directly.

Payment completion can update the extension's payment status through the Million Dollar Script payment source status hook.

In manual mode, approving a bounty confirms its manual or free payment state. Administrators should verify any offline payment before approving the listing.

## Legal And Privacy

Bounty Engine can contribute a **Bounty Board Terms** document through the Million Dollar Script extension setup flow. Review it before publishing.

The extension includes paginated WordPress privacy export and erasure hooks. Erasure anonymizes requester contact fields and removes private claim text and work links while retaining the minimum public or moderation record. Scheduled retention cleanup applies each board's retention period to rejected, archived, and completed records. Active submissions are retained while they are still needed for the board workflow.

Submission notifications use WordPress `wp_mail()`. Configure site mail delivery with a dedicated SMTP or transactional-email plugin when the host's default mail transport is unreliable.

Site owners remain responsible for reward terms, payment handling, dispute policies, refunds, taxes, moderation, and any regulated claims. The generated legal page is a starting point for review and is not legal advice.

## REST API

```text
GET  /wp-json/million-dollar-script/v1/bounty-engine/boards
GET  /wp-json/million-dollar-script/v1/bounty-engine/boards/{id}/bounties
POST /wp-json/million-dollar-script/v1/bounty-engine/bounties
POST /wp-json/million-dollar-script/v1/bounty-engine/bounties/{id}/moderate
POST /wp-json/million-dollar-script/v1/bounty-engine/claims
POST /wp-json/million-dollar-script/v1/bounty-engine/claims/{id}/moderate
```

Write and moderation endpoints are administrator-scoped unless a future API policy explicitly allows another actor.

The two public read endpoints support `page` and `per_page` parameters and return totals. They return active, display-ready board and bounty fields only. They do not expose requester names, contact email addresses, retention settings, payment-provider state, charged amounts, or other private operational records. Requests for inactive boards return `404`.

Administrator-created bounties and claims use the same validation contract as public submissions, including explicit `terms_accepted` input.

## Shared-Host Notes

Bounty Engine does not require queues, escrow processing, generated images, or shell tools. Hosted-service candidates include high-volume fraud review, escrow-like workflows, generated promotional assets, identity checks, and partner verification.
