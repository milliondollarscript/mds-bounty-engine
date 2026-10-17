<?php
/**
 * Local fixture checks for Bounty Engine.
 *
 * Run with:
 * ./scripts/wp eval-file wp-content/plugins/mds-bounty-engine/tests/mds3-fixture.php
 */

if (!defined('ABSPATH')) {
    exit;
}

use MillionDollarScript\Extensions\BountyEngine\Main;
use MillionDollarScript\Extensions\BountyEngine\Privacy;
use MillionDollarScript\Extensions\BountyEngine\Repository;

if (!class_exists(Repository::class) || !class_exists(Main::class)) {
    fwrite(STDERR, "Bounty Engine classes are not loaded.\n");
    exit(1);
}

wp_set_current_user(1);
Main::register_post_types();
$main = Main::instance();

$main->admin_menu();
global $submenu;
$has_menu = false;
foreach ((array) ($submenu['mds3'] ?? []) as $item) {
    if ('mds-bounty-engine' === (string) ($item[2] ?? '')) {
        $has_menu = true;
        break;
    }
}
if (!$has_menu) {
    fwrite(STDERR, "Bounty Engine did not register an extension submenu.\n");
    exit(1);
}

$onboarding = apply_filters('million-dollar-script/extension/onboarding/items', []);
if (empty($onboarding['mds-bounty-engine']['actions']) || empty($onboarding['mds-bounty-engine']['legal_documents'])) {
    fwrite(STDERR, "Bounty Engine onboarding is incomplete.\n");
    exit(1);
}

$blocks = apply_filters('million-dollar-script/editor/extension/blocks', []);
$block_names = array_map(static function ($block) {
    return is_array($block) ? (string) ($block['name'] ?? '') : '';
}, $blocks);
if (!in_array('mds-bounty-engine/board', $block_names, true)) {
    fwrite(STDERR, "Bounty Engine block is not registered.\n");
    exit(1);
}

$suffix = strtolower(wp_generate_password(8, false, false));
$email = 'bounty-fixture-' . $suffix . '@example.test';
$created_ids = [];

$board_id = Repository::save_board([
    'title' => 'Fixture Bounty Board ' . $suffix,
    'slug' => 'fixture-bounty-board-' . $suffix,
    'description' => 'Fixture board description.',
    'status' => 'active',
    'currency' => Repository::default_currency(),
    'posting_fee' => 10,
    'featured_fee' => 5,
    'bounty_limit' => 3,
    'payment_mode' => 'manual',
    'claims_enabled' => '1',
    'disclosure' => 'Fixture public disclosure.',
    'terms_url' => home_url('/terms/'),
    'contact_email' => $email,
    'retention_days' => 30,
    'submission_limit_per_hour' => 12,
]);
if (!$board_id) {
    fwrite(STDERR, "Fixture bounty board could not be created.\n");
    exit(1);
}
$created_ids[] = $board_id;

$board = Repository::board($board_id);
if (12 !== absint($board['submission_limit_per_hour'] ?? 0)) {
    fwrite(STDERR, "Board submission limits were not saved.\n");
    exit(1);
}

$invalid_date = Repository::validate_bounty_submission($board_id, [
    'title' => 'Invalid date',
    'description' => 'Invalid date fixture.',
    'requester_email' => $email,
    'due_at' => '2026-02-31',
    'terms_accepted' => '1',
]);
$missing_terms = Repository::validate_bounty_submission($board_id, [
    'title' => 'Missing terms',
    'description' => 'Missing terms fixture.',
    'requester_email' => $email,
]);
$invalid_url = Repository::validate_bounty_submission($board_id, [
    'title' => 'Invalid URL',
    'description' => 'Invalid URL fixture.',
    'requester_email' => $email,
    'external_url' => 'javascript:alert(1)',
    'terms_accepted' => '1',
]);
if (!is_wp_error($invalid_date) || !is_wp_error($missing_terms) || !is_wp_error($invalid_url)) {
    fwrite(STDERR, "Bounty input validation accepted an invalid date, URL, or missing terms.\n");
    exit(1);
}

$bounty_id = Repository::create_bounty($board_id, [
    'title' => 'Fixture bounty ' . $suffix,
    'description' => 'Fixture bounty description.',
    'requester_name' => 'Fixture Requester',
    'requester_email' => $email,
    'category' => 'Testing',
    'reward_label' => 'Fixture reward',
    'external_url' => 'https://request.example.test/',
    'featured' => '1',
    'terms_accepted' => '1',
]);
if (!$bounty_id || !Repository::update_bounty_status($bounty_id, 'approve')) {
    fwrite(STDERR, "Fixture bounty approval failed.\n");
    exit(1);
}
$created_ids[] = $bounty_id;

$bounty = Repository::bounty($bounty_id);
if (!$bounty || 'open' !== $bounty['status'] || 15.0 !== (float) $bounty['amount']) {
    fwrite(STDERR, "Approved bounty state or fee calculation is incorrect.\n");
    exit(1);
}

$claim_id = Repository::create_claim($bounty_id, [
    'display_name' => 'Fixture Claimant',
    'email' => $email,
    'proposal' => 'Fixture claim proposal.',
    'work_url' => 'https://work.example.test/',
    'terms_accepted' => '1',
]);
if (!$claim_id || !Repository::update_claim_status($claim_id, 'approve')) {
    fwrite(STDERR, "Fixture claim approval failed.\n");
    exit(1);
}
$created_ids[] = $claim_id;

$claim = Repository::claim($claim_id);
$bounty = Repository::bounty($bounty_id);
if (!$claim || 'approved' !== $claim['status'] || !$bounty || 'in_review' !== $bounty['status']) {
    fwrite(STDERR, "Claim approval did not advance the bounty workflow.\n");
    exit(1);
}

$main->handle_payment_status('bounty-engine', $bounty_id, 'paid', []);
$bounty = Repository::bounty($bounty_id);
if (!$bounty || 'paid' !== $bounty['payment_status']) {
    fwrite(STDERR, "Payment status was not synchronized.\n");
    exit(1);
}

$html = $main->shortcode([
    'id' => $board_id,
    'show_post_form' => 'true',
    'show_claim_forms' => 'true',
    'show_notice' => 'true',
]);
if (false === strpos($html, 'Fixture bounty description.') || false === strpos($html, 'Fixture public disclosure.')) {
    fwrite(STDERR, "Approved bounty board content was not rendered publicly.\n");
    exit(1);
}

$export = $main->privacy_exporter($email, 1);
if (empty($export['done']) || empty($export['data'])) {
    fwrite(STDERR, "Privacy export did not include bounty and claim data.\n");
    exit(1);
}

$provider_board_id = Repository::save_board([
    'title' => 'Fixture Provider Board ' . $suffix,
    'slug' => 'fixture-provider-board-' . $suffix,
    'description' => 'Provider publication gate fixture.',
    'status' => 'active',
    'currency' => Repository::default_currency(),
    'posting_fee' => 10,
    'featured_fee' => 0,
    'bounty_limit' => 3,
    'payment_mode' => 'provider',
    'claims_enabled' => '1',
    'disclosure' => 'Fixture provider disclosure.',
    'contact_email' => $email,
]);
if (!$provider_board_id) {
    fwrite(STDERR, "Provider bounty board could not be created.\n");
    exit(1);
}
$created_ids[] = $provider_board_id;

$moderated_first_id = Repository::create_bounty($provider_board_id, [
    'title' => 'Moderated first ' . $suffix,
    'description' => 'Must remain private before provider payment.',
    'requester_email' => $email,
    'terms_accepted' => '1',
]);
$created_ids[] = $moderated_first_id;
Repository::update_bounty_status($moderated_first_id, 'approve');
if ('pending' !== get_post_status($moderated_first_id) || Repository::bounties($provider_board_id, ['public_only' => true])) {
    fwrite(STDERR, "Provider-pending bounty became public after moderation.\n");
    exit(1);
}
Repository::update_payment_status($moderated_first_id, 'paid');
if ('publish' !== get_post_status($moderated_first_id) || empty(Repository::bounties($provider_board_id, ['public_only' => true]))) {
    fwrite(STDERR, "Moderated bounty did not publish after provider payment.\n");
    exit(1);
}
Repository::update_payment_status($moderated_first_id, 'refunded');
if ('pending' !== get_post_status($moderated_first_id) || Repository::bounties($provider_board_id, ['public_only' => true])) {
    fwrite(STDERR, "Refunded bounty remained public.\n");
    exit(1);
}

$paid_first_id = Repository::create_bounty($provider_board_id, [
    'title' => 'Paid first ' . $suffix,
    'description' => 'Must remain private before moderation.',
    'requester_email' => $email,
    'terms_accepted' => '1',
]);
$created_ids[] = $paid_first_id;
Repository::update_payment_status($paid_first_id, 'paid');
if ('pending' !== get_post_status($paid_first_id)) {
    fwrite(STDERR, "Payment published an unmoderated bounty.\n");
    exit(1);
}
Repository::update_bounty_status($paid_first_id, 'approve');
if ('publish' !== get_post_status($paid_first_id)) {
    fwrite(STDERR, "Paid bounty did not publish after moderation.\n");
    exit(1);
}

do_action('rest_api_init');
$routes = rest_get_server()->get_routes();
foreach (['/million-dollar-script/v1/bounty-engine/boards', '/million-dollar-script/v1/bounty-engine/bounties', '/million-dollar-script/v1/bounty-engine/claims'] as $route) {
    if (empty($routes[$route])) {
        fwrite(STDERR, "Missing REST route: {$route}.\n");
        exit(1);
    }
}

$manifest = $main->api_endpoint_manifest([]);
$manifest_ids = wp_list_pluck($manifest, 'id');
$expected_manifest_ids = [
    'bounty-engine-boards-read',
    'bounty-engine-bounties-read',
    'bounty-engine-bounties-create',
    'bounty-engine-bounties-moderate',
    'bounty-engine-claims-create',
    'bounty-engine-claims-moderate',
];
if (array_diff($expected_manifest_ids, $manifest_ids)) {
    fwrite(STDERR, "Bounty Engine API manifest is incomplete.\n");
    exit(1);
}

$public_board = Repository::public_board_payload(Repository::board($provider_board_id));
$public_bounty = Repository::public_bounty_payload(Repository::bounty($paid_first_id));
foreach (['contact_email', 'retention_days', 'payment_mode'] as $private_key) {
    if (array_key_exists($private_key, $public_board)) {
        fwrite(STDERR, "Public bounty board API payload exposed {$private_key}.\n");
        exit(1);
    }
}
foreach (['requester_name', 'requester_email', 'payment_status', 'amount', 'currency'] as $private_key) {
    if (array_key_exists($private_key, $public_bounty)) {
        fwrite(STDERR, "Public bounty API payload exposed {$private_key}.\n");
        exit(1);
    }
}

$public_provider_bounties = Repository::bounties_page($provider_board_id, 1, 24, true);
if (1 !== absint($public_provider_bounties['total']) || absint($paid_first_id) !== absint($public_provider_bounties['items'][0]['id'] ?? 0)) {
    fwrite(STDERR, "Public provider pagination counted unpaid or refunded bounties.\n");
    exit(1);
}

$provider_board = Repository::board($provider_board_id);
Repository::save_board(array_merge($provider_board, ['status' => 'paused']), $provider_board_id);
$claim_while_paused = Repository::validate_claim_submission($paid_first_id, [
    'display_name' => 'Paused Claimant',
    'email' => 'paused-' . $suffix . '@example.test',
    'proposal' => 'This must be rejected while the board is paused.',
    'terms_accepted' => '1',
]);
$paused_response = rest_do_request(new WP_REST_Request('GET', '/million-dollar-script/v1/bounty-engine/boards/' . $provider_board_id . '/bounties'));
if (Repository::public_board_payload(Repository::board($provider_board_id)) || !is_wp_error($claim_while_paused) || 404 !== $paused_response->get_status()) {
    fwrite(STDERR, "Paused bounty board remained public or accepted a claim.\n");
    exit(1);
}
Repository::save_board(array_merge($provider_board, ['status' => 'active']), $provider_board_id);

for ($index = 0; $index < 101; $index++) {
    $scaled_claim_id = wp_insert_post([
        'post_type' => Repository::CLAIM_POST_TYPE,
        'post_status' => 'pending',
        'post_title' => 'Scaled claim ' . $index,
        'post_content' => 'Scaled claim fixture.',
    ]);
    update_post_meta($scaled_claim_id, '_mds_be_bounty_id', $bounty_id);
    update_post_meta($scaled_claim_id, '_mds_be_board_id', $board_id);
    update_post_meta($scaled_claim_id, '_mds_be_status', 'pending_review');
    update_post_meta($scaled_claim_id, '_mds_be_email', 'scale-' . $index . '@example.test');
    $created_ids[] = $scaled_claim_id;
}
if (102 !== Repository::claim_count($bounty_id)) {
    fwrite(STDERR, "Claim totals were truncated above 100 records.\n");
    exit(1);
}
$claims_page = Repository::claims_page($bounty_id, 2, 10);
if (102 !== absint($claims_page['total']) || 11 !== absint($claims_page['pages']) || 10 !== count($claims_page['items'])) {
    fwrite(STDERR, "Admin claim pagination did not return accurate totals.\n");
    exit(1);
}

$retention_email = 'retention-' . $suffix . '@example.test';
$retention_bounty_id = Repository::create_bounty($board_id, [
    'title' => 'Retention bounty ' . $suffix,
    'description' => 'Private requester details should expire after rejection.',
    'requester_name' => 'Retention Requester',
    'requester_email' => $retention_email,
    'terms_accepted' => '1',
]);
$created_ids[] = $retention_bounty_id;
Repository::update_bounty_status($retention_bounty_id, 'reject');
$old_date = '2020-01-01 00:00:00';
wp_update_post(['ID' => $retention_bounty_id, 'post_date' => $old_date, 'post_date_gmt' => $old_date]);
update_post_meta($retention_bounty_id, '_mds_be_private_data_updated_at', strtotime($old_date . ' UTC'));
if (!Repository::cleanup_board_private_data($board_id, 30) || !empty(Repository::bounty($retention_bounty_id)['requester_email'])) {
    fwrite(STDERR, "Configured retention did not anonymize stale rejected bounty data.\n");
    exit(1);
}

for ($index = 0; $index < 55; $index++) {
    $privacy_bounty_id = wp_insert_post([
        'post_type' => Repository::BOUNTY_POST_TYPE,
        'post_status' => 'draft',
        'post_title' => 'Paged privacy bounty ' . $index,
    ]);
    update_post_meta($privacy_bounty_id, '_mds_be_board_id', $board_id);
    update_post_meta($privacy_bounty_id, '_mds_be_status', 'rejected');
    update_post_meta($privacy_bounty_id, '_mds_be_requester_email', $email);
    update_post_meta($privacy_bounty_id, '_mds_be_requester_name', 'Paged Requester');
    $created_ids[] = $privacy_bounty_id;
}
$export_page_one = Privacy::exporter($email, 1);
$export_page_two = Privacy::exporter($email, 2);
if (!empty($export_page_one['done']) || count($export_page_one['data']) < 50 || empty($export_page_two['done']) || empty($export_page_two['data'])) {
    fwrite(STDERR, "Privacy export did not continue across every matching page.\n");
    exit(1);
}
$erase_iterations = 0;
do {
    $erase = Privacy::eraser($email, $erase_iterations + 1);
    $erase_iterations++;
} while (empty($erase['done']) && $erase_iterations < 5);
if (empty($erase['done']) || !empty(Repository::personal_records_page($email, 1, 1)['items'])) {
    fwrite(STDERR, "Privacy erasure did not anonymize every matching paged record.\n");
    exit(1);
}

foreach ($created_ids as $post_id) {
    wp_delete_post($post_id, true);
}

echo "Success: Bounty Engine fixture passed.\n";
