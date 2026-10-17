<?php
/**
 * Bounty Engine local repository.
 *
 * @package MillionDollarScript\Extensions\BountyEngine
 */

namespace MillionDollarScript\Extensions\BountyEngine;

if (!defined('ABSPATH')) {
    exit;
}

final class Repository {

    public const BOARD_POST_TYPE = 'mds_be_board';
    public const BOUNTY_POST_TYPE = 'mds_be_bounty';
    public const CLAIM_POST_TYPE = 'mds_be_claim';

    public static function maybe_seed() {
        if (self::boards(1)) {
            return;
        }

        self::save_board([
            'title' => __('Launch Bounty Board', 'mds-bounty-engine'),
            'slug' => 'launch-bounty-board',
            'description' => __('Post public requests, microgrants, featured tasks, and sponsor-backed challenges without taking custody of payouts.', 'mds-bounty-engine'),
            'status' => 'active',
            'currency' => self::default_currency(),
            'posting_fee' => 25,
            'featured_fee' => 50,
            'bounty_limit' => 50,
            'payment_mode' => 'manual',
            'claims_enabled' => '1',
            'disclosure' => __('Bounties are public requests. Payments, rewards, refunds, and delivery terms are handled directly by the parties unless your site states otherwise.', 'mds-bounty-engine'),
            'terms_url' => '',
            'contact_email' => get_option('admin_email'),
            'retention_days' => 365,
            'submission_limit_per_hour' => 10,
        ]);
    }

    public static function save_board(array $data, $board_id = 0) {
        $board_id = absint($board_id);
        $title = self::bounded_text($data['title'] ?? '', 160);
        $contact_email = sanitize_email((string) ($data['contact_email'] ?? get_option('admin_email')));
        if ('' === $title || !is_email($contact_email) || ($board_id && !self::board($board_id))) {
            return 0;
        }

        $postarr = [
            'post_type' => self::BOARD_POST_TYPE,
            'post_title' => $title,
            'post_status' => 'publish',
        ];

        $result = $board_id
            ? wp_update_post(array_merge($postarr, ['ID' => $board_id]), true)
            : wp_insert_post($postarr, true);

        if (is_wp_error($result) || !$result) {
            return 0;
        }

        $board_id = absint($result);
        $status = sanitize_key((string) ($data['status'] ?? 'draft'));
        if (!in_array($status, ['draft', 'active', 'paused', 'archived'], true)) {
            $status = 'draft';
        }

        $payment_mode = sanitize_key((string) ($data['payment_mode'] ?? 'manual'));
        if (!in_array($payment_mode, ['manual', 'provider'], true)) {
            $payment_mode = 'manual';
        }

        $meta = [
            '_mds_be_slug' => sanitize_title((string) ($data['slug'] ?? $title)) ?: 'bounty-board-' . $board_id,
            '_mds_be_description' => self::bounded_textarea($data['description'] ?? '', 4000),
            '_mds_be_status' => $status,
            '_mds_be_currency' => self::effective_currency($data['currency'] ?? self::default_currency()),
            '_mds_be_posting_fee' => self::money($data['posting_fee'] ?? 0),
            '_mds_be_featured_fee' => self::money($data['featured_fee'] ?? 0),
            '_mds_be_bounty_limit' => max(1, absint($data['bounty_limit'] ?? 50)),
            '_mds_be_payment_mode' => $payment_mode,
            '_mds_be_claims_enabled' => !empty($data['claims_enabled']) ? '1' : '0',
            '_mds_be_disclosure' => self::bounded_textarea($data['disclosure'] ?? '', 10000),
            '_mds_be_terms_url' => self::http_url($data['terms_url'] ?? ''),
            '_mds_be_contact_email' => $contact_email,
            '_mds_be_retention_days' => max(1, absint($data['retention_days'] ?? 365)),
            '_mds_be_submission_limit_per_hour' => max(1, min(500, absint($data['submission_limit_per_hour'] ?? 10))),
        ];

        foreach ($meta as $key => $value) {
            update_post_meta($board_id, $key, $value);
        }

        return $board_id;
    }

    public static function board($board_id) {
        $post = get_post(absint($board_id));
        if (!$post || self::BOARD_POST_TYPE !== $post->post_type) {
            return null;
        }

        return self::board_payload($post);
    }

    public static function board_by_slug($slug) {
        $slug = sanitize_title((string) $slug);
        if (!$slug) {
            return null;
        }

        $posts = get_posts([
            'post_type' => self::BOARD_POST_TYPE,
            'post_status' => 'publish',
            'meta_key' => '_mds_be_slug',
            'meta_value' => $slug,
            'posts_per_page' => 1,
            'no_found_rows' => true,
        ]);

        return $posts ? self::board_payload($posts[0]) : null;
    }

    public static function boards($limit = 50) {
        $posts = get_posts([
            'post_type' => self::BOARD_POST_TYPE,
            'post_status' => 'publish',
            'orderby' => 'date',
            'order' => 'DESC',
            'posts_per_page' => min(100, max(1, absint($limit))),
            'no_found_rows' => true,
        ]);

        return array_values(array_filter(array_map([__CLASS__, 'board_payload'], $posts)));
    }

    public static function boards_page($page = 1, $per_page = 20, $active_only = false) {
        $page = max(1, absint($page));
        $per_page = min(100, max(1, absint($per_page)));
        $args = [
            'post_type' => self::BOARD_POST_TYPE,
            'post_status' => 'publish',
            'orderby' => 'date',
            'order' => 'DESC',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'no_found_rows' => false,
        ];
        if ($active_only) {
            $args['meta_query'] = [['key' => '_mds_be_status', 'value' => 'active', 'compare' => '=']];
        }
        $query = new \WP_Query($args);

        return self::collection($query, [__CLASS__, 'board_payload'], $page, $per_page);
    }

    public static function create_bounty($board_id, array $data) {
        $board_id = absint($board_id);
        $validation = self::validate_bounty_submission($board_id, $data);
        if (is_wp_error($validation) || !self::acquire_board_lock($board_id)) {
            return 0;
        }

        $board = self::board($board_id);
        if (!$board || !self::has_open_bounty_slots($board_id)) {
            self::release_board_lock($board_id);
            return 0;
        }

        $title = self::bounded_text($data['title'] ?? '', 120);
        $description = self::bounded_textarea($data['description'] ?? '', 10000);

        $post_id = wp_insert_post([
            'post_type' => self::BOUNTY_POST_TYPE,
            'post_title' => $title,
            'post_content' => $description,
            'post_status' => 'pending',
        ], true);

        if (is_wp_error($post_id) || !$post_id) {
            self::release_board_lock($board_id);
            return 0;
        }

        $post_id = absint($post_id);
        $featured = !empty($data['featured']);
        $amount = self::money($board['posting_fee'] ?? 0) + ($featured ? self::money($board['featured_fee'] ?? 0) : 0);

        $meta = [
            '_mds_be_board_id' => absint($board_id),
            '_mds_be_requester_name' => self::bounded_text($data['requester_name'] ?? '', 80),
            '_mds_be_requester_email' => sanitize_email((string) ($data['requester_email'] ?? '')),
            '_mds_be_category' => self::bounded_text($data['category'] ?? '', 80),
            '_mds_be_reward_label' => self::bounded_text($data['reward_label'] ?? '', 120),
            '_mds_be_external_url' => self::http_url($data['external_url'] ?? ''),
            '_mds_be_due_at' => self::normalize_date($data['due_at'] ?? ''),
            '_mds_be_featured' => $featured ? '1' : '0',
            '_mds_be_status' => 'pending_review',
            '_mds_be_payment_status' => $amount > 0 ? 'requested' : 'not_required',
            '_mds_be_amount' => $amount,
            '_mds_be_currency' => self::effective_currency($board['currency'] ?? self::default_currency()),
            '_mds_be_completed_at' => '',
            '_mds_be_terms_accepted' => '1',
            '_mds_be_private_data_updated_at' => time(),
        ];

        foreach ($meta as $key => $value) {
            update_post_meta($post_id, $key, $value);
        }

        self::release_board_lock($board_id);

        return $post_id;
    }

    /**
     * @return true|\WP_Error
     */
    public static function validate_bounty_submission($board_id, array $data) {
        $board = self::board(absint($board_id));
        $title = self::bounded_text($data['title'] ?? '', 120);
        $description = self::bounded_textarea($data['description'] ?? '', 10000);
        $email = sanitize_email((string) ($data['requester_email'] ?? ''));
        $due_at = trim((string) ($data['due_at'] ?? ''));
        $external_url = trim((string) ($data['external_url'] ?? ''));

        if (!$board || 'active' !== (string) ($board['status'] ?? '')) {
            return new \WP_Error('mds_bounty_engine_board_closed', __('This bounty board is not accepting submissions.', 'mds-bounty-engine'));
        }
        if (!self::has_open_bounty_slots(absint($board_id))) {
            return new \WP_Error('mds_bounty_engine_board_full', __('This bounty board has reached its capacity.', 'mds-bounty-engine'));
        }
        if ('' === $title || '' === $description || !is_email($email)) {
            return new \WP_Error('mds_bounty_engine_invalid_bounty', __('Add a title, description, and valid requester email.', 'mds-bounty-engine'));
        }
        if (empty($data['terms_accepted'])) {
            return new \WP_Error('mds_bounty_engine_terms_required', __('Accept the posting terms and no-escrow disclosure before submitting.', 'mds-bounty-engine'));
        }
        if ('' !== $due_at && (!self::valid_date($due_at) || $due_at < wp_date('Y-m-d'))) {
            return new \WP_Error('mds_bounty_engine_invalid_due_date', __('Use a valid due date that is not in the past.', 'mds-bounty-engine'));
        }
        if ('' !== $external_url && '' === self::http_url($external_url)) {
            return new \WP_Error('mds_bounty_engine_invalid_url', __('Use a valid HTTP or HTTPS details URL.', 'mds-bounty-engine'));
        }

        return true;
    }

    public static function bounty($bounty_id) {
        $post = get_post(absint($bounty_id));
        if (!$post || self::BOUNTY_POST_TYPE !== $post->post_type) {
            return null;
        }

        return self::bounty_payload($post);
    }

    public static function bounties($board_id, array $args = []) {
        $public_only = !empty($args['public_only']);
        $posts = get_posts([
            'post_type' => self::BOUNTY_POST_TYPE,
            'post_status' => $public_only ? ['publish'] : ['publish', 'pending', 'draft'],
            'meta_key' => '_mds_be_board_id',
            'meta_value' => absint($board_id),
            'posts_per_page' => min(100, max(1, absint($args['limit'] ?? 50))),
            'no_found_rows' => true,
        ]);

        $bounties = array_values(array_filter(array_map([__CLASS__, 'bounty_payload'], $posts)));
        if ($public_only) {
            $bounties = array_values(array_filter($bounties, function ($bounty) {
                return in_array((string) ($bounty['status'] ?? ''), ['open', 'in_review', 'completed'], true)
                    && self::payment_allows_publication($bounty);
            }));
        }

        usort($bounties, function ($a, $b) {
            if (!empty($a['featured']) !== !empty($b['featured'])) {
                return !empty($a['featured']) ? -1 : 1;
            }

            $status_weight = ['open' => 0, 'in_review' => 1, 'completed' => 2, 'pending_review' => 3, 'rejected' => 4, 'archived' => 5];
            $a_weight = $status_weight[(string) ($a['status'] ?? '')] ?? 6;
            $b_weight = $status_weight[(string) ($b['status'] ?? '')] ?? 6;
            if ($a_weight !== $b_weight) {
                return $a_weight <=> $b_weight;
            }

            return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
        });

        return $bounties;
    }

    public static function bounties_page($board_id, $page = 1, $per_page = 20, $public_only = false) {
        $page = max(1, absint($page));
        $per_page = min(100, max(1, absint($per_page)));
        $board = self::board($board_id);
        if (!$board || ($public_only && 'active' !== (string) ($board['status'] ?? ''))) {
            return self::empty_collection($page, $per_page);
        }

        $meta_query = [['key' => '_mds_be_board_id', 'value' => absint($board_id), 'compare' => '=']];
        if ($public_only) {
            $meta_query[] = ['key' => '_mds_be_status', 'value' => ['open', 'in_review', 'completed'], 'compare' => 'IN'];
            if ('provider' === (string) ($board['payment_mode'] ?? 'manual')) {
                $meta_query[] = [
                    'relation' => 'OR',
                    ['key' => '_mds_be_amount', 'value' => 0, 'type' => 'NUMERIC', 'compare' => '<='],
                    ['key' => '_mds_be_payment_status', 'value' => ['not_required', 'paid', 'completed', 'processing'], 'compare' => 'IN'],
                ];
            }
        }
        $query = new \WP_Query([
            'post_type' => self::BOUNTY_POST_TYPE,
            'post_status' => $public_only ? 'publish' : ['publish', 'pending', 'draft'],
            'meta_key' => '_mds_be_featured',
            'meta_query' => $meta_query,
            'orderby' => ['meta_value_num' => 'DESC', 'date' => 'DESC'],
            'posts_per_page' => $per_page,
            'paged' => $page,
            'no_found_rows' => false,
        ]);

        return self::collection($query, [__CLASS__, 'bounty_payload'], $page, $per_page);
    }

    public static function create_claim($bounty_id, array $data) {
        $validation = self::validate_claim_submission($bounty_id, $data);
        if (is_wp_error($validation)) {
            return 0;
        }

        $bounty = self::bounty($bounty_id);
        $name = self::bounded_text($data['display_name'] ?? '', 80);
        $proposal = self::bounded_textarea($data['proposal'] ?? '', 10000);

        $post_id = wp_insert_post([
            'post_type' => self::CLAIM_POST_TYPE,
            'post_title' => sprintf(__('Claim for %s', 'mds-bounty-engine'), $bounty['title']),
            'post_content' => $proposal,
            'post_status' => 'pending',
        ], true);

        if (is_wp_error($post_id) || !$post_id) {
            return 0;
        }

        $post_id = absint($post_id);
        $meta = [
            '_mds_be_bounty_id' => absint($bounty_id),
            '_mds_be_board_id' => absint($bounty['board_id'] ?? 0),
            '_mds_be_display_name' => $name,
            '_mds_be_email' => sanitize_email((string) ($data['email'] ?? '')),
            '_mds_be_work_url' => self::http_url($data['work_url'] ?? ''),
            '_mds_be_status' => 'pending_review',
            '_mds_be_terms_accepted' => '1',
            '_mds_be_private_data_updated_at' => time(),
        ];

        foreach ($meta as $key => $value) {
            update_post_meta($post_id, $key, $value);
        }

        return $post_id;
    }

    /**
     * @return true|\WP_Error
     */
    public static function validate_claim_submission($bounty_id, array $data) {
        $bounty = self::bounty(absint($bounty_id));
        $board = $bounty ? self::board(absint($bounty['board_id'] ?? 0)) : null;
        $name = self::bounded_text($data['display_name'] ?? '', 80);
        $proposal = self::bounded_textarea($data['proposal'] ?? '', 10000);
        $email = sanitize_email((string) ($data['email'] ?? ''));
        $work_url = trim((string) ($data['work_url'] ?? ''));

        if (!$bounty || !$board || 'active' !== (string) ($board['status'] ?? '') || empty($board['claims_enabled'])) {
            return new \WP_Error('mds_bounty_engine_claims_closed', __('Claims are not currently open for this bounty.', 'mds-bounty-engine'));
        }
        if (!in_array((string) ($bounty['status'] ?? ''), ['open', 'in_review'], true) || 'publish' !== get_post_status(absint($bounty_id)) || !self::payment_allows_publication($bounty)) {
            return new \WP_Error('mds_bounty_engine_bounty_unavailable', __('This bounty is not available for claims.', 'mds-bounty-engine'));
        }
        if (!empty($bounty['due_at']) && (string) $bounty['due_at'] < wp_date('Y-m-d')) {
            return new \WP_Error('mds_bounty_engine_bounty_expired', __('The bounty due date has passed.', 'mds-bounty-engine'));
        }
        if ('' === $name || '' === $proposal || !is_email($email)) {
            return new \WP_Error('mds_bounty_engine_invalid_claim', __('Add a name, proposal, and valid email address.', 'mds-bounty-engine'));
        }
        if (empty($data['terms_accepted'])) {
            return new \WP_Error('mds_bounty_engine_claim_terms_required', __('Accept the claim and no-escrow terms before submitting.', 'mds-bounty-engine'));
        }
        if ('' !== $work_url && '' === self::http_url($work_url)) {
            return new \WP_Error('mds_bounty_engine_invalid_work_url', __('Use a valid HTTP or HTTPS work URL.', 'mds-bounty-engine'));
        }

        return true;
    }

    public static function claim($claim_id) {
        $post = get_post(absint($claim_id));
        if (!$post || self::CLAIM_POST_TYPE !== $post->post_type) {
            return null;
        }

        return self::claim_payload($post);
    }

    public static function claims($bounty_id = 0, array $args = []) {
        $meta_query = [];
        if ($bounty_id) {
            $meta_query[] = [
                'key' => '_mds_be_bounty_id',
                'value' => absint($bounty_id),
            ];
        }

        $posts = get_posts([
            'post_type' => self::CLAIM_POST_TYPE,
            'post_status' => !empty($args['public_only']) ? ['publish'] : ['publish', 'pending', 'draft'],
            'meta_query' => $meta_query,
            'posts_per_page' => min(100, max(1, absint($args['limit'] ?? 50))),
            'no_found_rows' => true,
        ]);

        return array_values(array_filter(array_map([__CLASS__, 'claim_payload'], $posts)));
    }

    public static function claims_page($bounty_id = 0, $page = 1, $per_page = 20) {
        $page = max(1, absint($page));
        $per_page = min(100, max(1, absint($per_page)));
        $meta_query = [];
        if ($bounty_id) {
            $meta_query[] = ['key' => '_mds_be_bounty_id', 'value' => absint($bounty_id), 'compare' => '='];
        }
        $query = new \WP_Query([
            'post_type' => self::CLAIM_POST_TYPE,
            'post_status' => ['publish', 'pending', 'draft'],
            'meta_query' => $meta_query,
            'orderby' => 'date',
            'order' => 'DESC',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'no_found_rows' => false,
        ]);

        return self::collection($query, [__CLASS__, 'claim_payload'], $page, $per_page);
    }

    public static function claim_count($bounty_id) {
        $query = new \WP_Query([
            'post_type' => self::CLAIM_POST_TYPE,
            'post_status' => ['publish', 'pending', 'draft'],
            'meta_key' => '_mds_be_bounty_id',
            'meta_value' => absint($bounty_id),
            'posts_per_page' => 1,
            'no_found_rows' => false,
        ]);

        return absint($query->found_posts);
    }

    public static function personal_records_page($email, $page = 1, $per_page = 50) {
        $email = sanitize_email((string) $email);
        $page = max(1, absint($page));
        $per_page = min(100, max(1, absint($per_page)));
        if (!is_email($email)) {
            return self::empty_collection($page, $per_page);
        }

        $query = new \WP_Query([
            'post_type' => [self::BOUNTY_POST_TYPE, self::CLAIM_POST_TYPE],
            'post_status' => ['publish', 'pending', 'draft'],
            'meta_query' => [
                'relation' => 'OR',
                ['key' => '_mds_be_requester_email', 'value' => $email, 'compare' => '='],
                ['key' => '_mds_be_email', 'value' => $email, 'compare' => '='],
            ],
            'orderby' => 'ID',
            'order' => 'ASC',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'no_found_rows' => false,
        ]);

        return self::collection($query, static function ($post) {
            return self::BOUNTY_POST_TYPE === $post->post_type
                ? ['type' => 'bounty', 'record' => self::bounty_payload($post)]
                : ['type' => 'claim', 'record' => self::claim_payload($post)];
        }, $page, $per_page);
    }

    public static function anonymize_bounty($bounty_id) {
        if (!self::bounty($bounty_id)) {
            return false;
        }

        update_post_meta(absint($bounty_id), '_mds_be_requester_email', '');
        update_post_meta(absint($bounty_id), '_mds_be_requester_name', __('Anonymous requester', 'mds-bounty-engine'));
        update_post_meta(absint($bounty_id), '_mds_be_private_data_updated_at', time());

        return true;
    }

    public static function anonymize_claim($claim_id, $remove_submission = false) {
        if (!self::claim($claim_id)) {
            return false;
        }

        update_post_meta(absint($claim_id), '_mds_be_email', '');
        update_post_meta(absint($claim_id), '_mds_be_display_name', __('Anonymous claimant', 'mds-bounty-engine'));
        if ($remove_submission) {
            update_post_meta(absint($claim_id), '_mds_be_work_url', '');
            wp_update_post(['ID' => absint($claim_id), 'post_content' => '']);
        }
        update_post_meta(absint($claim_id), '_mds_be_private_data_updated_at', time());

        return true;
    }

    public static function cleanup_board_private_data($board_id, $retention_days, $limit = 100) {
        $board_id = absint($board_id);
        $retention_days = max(1, absint($retention_days));
        $limit = min(250, max(1, absint($limit)));
        $cutoff = time() - ($retention_days * DAY_IN_SECONDS);
        $changed = 0;

        $bounties = self::retention_posts(
            self::BOUNTY_POST_TYPE,
            $board_id,
            '_mds_be_requester_email',
            $cutoff,
            $limit,
            [['key' => '_mds_be_status', 'value' => ['rejected', 'archived', 'completed'], 'compare' => 'IN']]
        );
        foreach ($bounties as $post) {
            if (self::private_data_timestamp($post) <= $cutoff && self::anonymize_bounty($post->ID)) {
                $changed++;
            }
        }

        $claims = self::retention_posts(self::CLAIM_POST_TYPE, $board_id, '_mds_be_email', $cutoff, $limit);
        foreach ($claims as $post) {
            if (self::private_data_timestamp($post) > $cutoff) {
                continue;
            }
            $claim = self::claim_payload($post);
            $bounty = self::bounty(absint($claim['bounty_id'] ?? 0));
            $terminal = 'rejected' === (string) ($claim['status'] ?? '')
                || !$bounty
                || in_array((string) ($bounty['status'] ?? ''), ['rejected', 'archived', 'completed'], true);
            if ($terminal && self::anonymize_claim($post->ID, true)) {
                $changed++;
            }
        }

        return $changed;
    }

    public static function update_bounty_status($bounty_id, $status) {
        $bounty = self::bounty($bounty_id);
        if (!$bounty) {
            return false;
        }

        $status = sanitize_key((string) $status);
        if ('approve' === $status || 'reopen' === $status) {
            update_post_meta($bounty_id, '_mds_be_status', 'open');
            update_post_meta($bounty_id, '_mds_be_private_data_updated_at', time());
            self::confirm_manual_payment($bounty);
            self::reconcile_publication($bounty_id);
            return true;
        }

        if ('review' === $status) {
            update_post_meta($bounty_id, '_mds_be_status', 'in_review');
            update_post_meta($bounty_id, '_mds_be_private_data_updated_at', time());
            self::confirm_manual_payment($bounty);
            self::reconcile_publication($bounty_id);
            return true;
        }

        if ('complete' === $status) {
            update_post_meta($bounty_id, '_mds_be_status', 'completed');
            update_post_meta($bounty_id, '_mds_be_completed_at', current_time('mysql', true));
            update_post_meta($bounty_id, '_mds_be_private_data_updated_at', time());
            self::confirm_manual_payment($bounty);
            self::reconcile_publication($bounty_id);
            return true;
        }

        if ('reject' === $status) {
            wp_update_post(['ID' => absint($bounty_id), 'post_status' => 'draft']);
            update_post_meta($bounty_id, '_mds_be_status', 'rejected');
            update_post_meta($bounty_id, '_mds_be_private_data_updated_at', time());
            return true;
        }

        if ('archive' === $status) {
            wp_update_post(['ID' => absint($bounty_id), 'post_status' => 'draft']);
            update_post_meta($bounty_id, '_mds_be_status', 'archived');
            update_post_meta($bounty_id, '_mds_be_private_data_updated_at', time());
            return true;
        }

        return false;
    }

    public static function update_claim_status($claim_id, $status) {
        $claim = self::claim($claim_id);
        if (!$claim) {
            return false;
        }

        $status = sanitize_key((string) $status);
        if ('approve' === $status) {
            wp_update_post(['ID' => absint($claim_id), 'post_status' => 'publish']);
            update_post_meta($claim_id, '_mds_be_status', 'approved');
            update_post_meta($claim_id, '_mds_be_private_data_updated_at', time());
            self::update_bounty_status(absint($claim['bounty_id']), 'review');
            return true;
        }

        if ('reject' === $status) {
            wp_update_post(['ID' => absint($claim_id), 'post_status' => 'draft']);
            update_post_meta($claim_id, '_mds_be_status', 'rejected');
            update_post_meta($claim_id, '_mds_be_private_data_updated_at', time());
            return true;
        }

        return false;
    }

    public static function update_payment_status($bounty_id, $status) {
        $bounty = self::bounty($bounty_id);
        if (!$bounty) {
            return false;
        }

        $status = sanitize_key((string) $status);
        if (!in_array($status, ['requested', 'pending', 'processing', 'paid', 'completed', 'failed', 'cancelled', 'expired', 'refunded', 'not_required'], true)) {
            return false;
        }

        update_post_meta(absint($bounty_id), '_mds_be_payment_status', $status);
        self::reconcile_publication($bounty_id);
        return true;
    }

    private static function confirm_manual_payment(array $bounty) {
        $board = self::board(absint($bounty['board_id'] ?? 0));
        if (!$board || 'manual' !== (string) ($board['payment_mode'] ?? 'manual')) {
            return;
        }

        $payment_status = sanitize_key((string) ($bounty['payment_status'] ?? ''));
        if ((float) ($bounty['amount'] ?? 0) > 0 && !in_array($payment_status, ['paid', 'completed', 'processing'], true)) {
            update_post_meta(absint($bounty['id'] ?? 0), '_mds_be_payment_status', 'paid');
        }
    }

    private static function reconcile_publication($bounty_id) {
        $bounty = self::bounty($bounty_id);
        if (!$bounty) {
            return;
        }

        $approved = in_array((string) ($bounty['status'] ?? ''), ['open', 'in_review', 'completed'], true);
        if (!$approved) {
            return;
        }

        wp_update_post([
            'ID' => absint($bounty_id),
            'post_status' => self::payment_allows_publication($bounty) ? 'publish' : 'pending',
        ]);
    }

    private static function payment_allows_publication(array $bounty) {
        if ((float) ($bounty['amount'] ?? 0) <= 0) {
            return true;
        }

        $status = sanitize_key((string) ($bounty['payment_status'] ?? ''));
        if (in_array($status, ['not_required', 'paid', 'completed', 'processing'], true)) {
            return true;
        }

        $board = self::board(absint($bounty['board_id'] ?? 0));

        return $board && 'manual' === (string) ($board['payment_mode'] ?? 'manual');
    }

    public static function has_open_bounty_slots($board_id) {
        $board = self::board($board_id);
        if (!$board) {
            return false;
        }

        return self::used_bounty_slots($board_id) < absint($board['bounty_limit'] ?? 0);
    }

    public static function used_bounty_slots($board_id) {
        $query = new \WP_Query([
            'post_type' => self::BOUNTY_POST_TYPE,
            'post_status' => ['publish', 'pending'],
            'meta_key' => '_mds_be_board_id',
            'meta_value' => absint($board_id),
            'posts_per_page' => 1,
            'no_found_rows' => false,
        ]);

        return absint($query->found_posts);
    }

    public static function board_payload($post) {
        $post = is_object($post) ? $post : get_post(absint($post));
        if (!$post) {
            return null;
        }

        return [
            'id' => absint($post->ID),
            'title' => get_the_title($post),
            'slug' => (string) get_post_meta($post->ID, '_mds_be_slug', true),
            'description' => (string) get_post_meta($post->ID, '_mds_be_description', true),
            'status' => (string) get_post_meta($post->ID, '_mds_be_status', true),
            'currency' => (string) get_post_meta($post->ID, '_mds_be_currency', true),
            'posting_fee' => (float) get_post_meta($post->ID, '_mds_be_posting_fee', true),
            'featured_fee' => (float) get_post_meta($post->ID, '_mds_be_featured_fee', true),
            'bounty_limit' => absint(get_post_meta($post->ID, '_mds_be_bounty_limit', true)),
            'used_bounties' => self::used_bounty_slots($post->ID),
            'payment_mode' => (string) get_post_meta($post->ID, '_mds_be_payment_mode', true),
            'claims_enabled' => '1' === (string) get_post_meta($post->ID, '_mds_be_claims_enabled', true),
            'disclosure' => (string) get_post_meta($post->ID, '_mds_be_disclosure', true),
            'terms_url' => (string) get_post_meta($post->ID, '_mds_be_terms_url', true),
            'contact_email' => (string) get_post_meta($post->ID, '_mds_be_contact_email', true),
            'retention_days' => absint(get_post_meta($post->ID, '_mds_be_retention_days', true)),
            'submission_limit_per_hour' => absint(get_post_meta($post->ID, '_mds_be_submission_limit_per_hour', true)) ?: 10,
        ];
    }

    public static function public_board_payload($board) {
        $board = is_array($board) ? $board : self::board_payload($board);
        if (!$board || 'active' !== (string) ($board['status'] ?? '')) {
            return null;
        }

        return array_intersect_key($board, array_flip([
            'id',
            'title',
            'slug',
            'description',
            'status',
            'currency',
            'posting_fee',
            'featured_fee',
            'bounty_limit',
            'used_bounties',
            'claims_enabled',
            'disclosure',
            'terms_url',
        ]));
    }

    public static function bounty_payload($post) {
        $post = is_object($post) ? $post : get_post(absint($post));
        if (!$post) {
            return null;
        }

        return [
            'id' => absint($post->ID),
            'board_id' => absint(get_post_meta($post->ID, '_mds_be_board_id', true)),
            'title' => get_the_title($post),
            'description' => (string) $post->post_content,
            'status' => (string) get_post_meta($post->ID, '_mds_be_status', true),
            'requester_name' => (string) get_post_meta($post->ID, '_mds_be_requester_name', true),
            'requester_email' => (string) get_post_meta($post->ID, '_mds_be_requester_email', true),
            'category' => (string) get_post_meta($post->ID, '_mds_be_category', true),
            'reward_label' => (string) get_post_meta($post->ID, '_mds_be_reward_label', true),
            'external_url' => (string) get_post_meta($post->ID, '_mds_be_external_url', true),
            'due_at' => (string) get_post_meta($post->ID, '_mds_be_due_at', true),
            'featured' => '1' === (string) get_post_meta($post->ID, '_mds_be_featured', true),
            'payment_status' => (string) get_post_meta($post->ID, '_mds_be_payment_status', true),
            'amount' => (float) get_post_meta($post->ID, '_mds_be_amount', true),
            'currency' => (string) get_post_meta($post->ID, '_mds_be_currency', true),
            'completed_at' => (string) get_post_meta($post->ID, '_mds_be_completed_at', true),
            'claim_count' => self::claim_count($post->ID),
            'created_at' => mysql_to_rfc3339($post->post_date_gmt ?: $post->post_date),
        ];
    }

    public static function public_bounty_payload($bounty) {
        $bounty = is_array($bounty) ? $bounty : self::bounty_payload($bounty);
        if (!$bounty) {
            return null;
        }

        return array_intersect_key($bounty, array_flip([
            'id',
            'board_id',
            'title',
            'description',
            'status',
            'category',
            'reward_label',
            'external_url',
            'due_at',
            'featured',
            'completed_at',
            'claim_count',
            'created_at',
        ]));
    }

    public static function claim_payload($post) {
        $post = is_object($post) ? $post : get_post(absint($post));
        if (!$post) {
            return null;
        }

        return [
            'id' => absint($post->ID),
            'bounty_id' => absint(get_post_meta($post->ID, '_mds_be_bounty_id', true)),
            'board_id' => absint(get_post_meta($post->ID, '_mds_be_board_id', true)),
            'display_name' => (string) get_post_meta($post->ID, '_mds_be_display_name', true),
            'email' => (string) get_post_meta($post->ID, '_mds_be_email', true),
            'proposal' => (string) $post->post_content,
            'work_url' => (string) get_post_meta($post->ID, '_mds_be_work_url', true),
            'status' => (string) get_post_meta($post->ID, '_mds_be_status', true),
            'created_at' => mysql_to_rfc3339($post->post_date_gmt ?: $post->post_date),
        ];
    }

    private static function normalize_date($value) {
        $value = self::bounded_text($value, 10);
        if (self::valid_date($value)) {
            return $value;
        }

        return '';
    }

    public static function valid_date($value) {
        $value = trim((string) $value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, wp_timezone());
        $errors = \DateTimeImmutable::getLastErrors();

        return false !== $date
            && (false === $errors || (0 === (int) ($errors['warning_count'] ?? 0) && 0 === (int) ($errors['error_count'] ?? 0)))
            && $date->format('Y-m-d') === $value;
    }

    private static function money($value) {
        if (class_exists('\\MillionDollarScript\\Commerce\\Currency') && method_exists('\\MillionDollarScript\\Commerce\\Currency', 'amount')) {
            return \MillionDollarScript\Commerce\Currency::amount($value);
        }

        return round(max(0, (float) $value), 2);
    }

    public static function default_currency() {
        $settings = get_option('mds3_settings', []);
        $settings = is_array($settings) ? $settings : [];
        $fallback = '';

        if (function_exists('get_woocommerce_currency')) {
            $fallback = get_woocommerce_currency();
        }
        if (!$fallback && !empty($settings['currency'])) {
            $fallback = (string) $settings['currency'];
        }
        if (class_exists('\\MillionDollarScript\\Commerce\\Currency')) {
            return \MillionDollarScript\Commerce\Currency::current_code($settings);
        }

        return self::currency($fallback ?: 'USD');
    }

    public static function effective_currency($value = '') {
        if (class_exists('\\MillionDollarScript\\Commerce\\Currency') && method_exists('\\MillionDollarScript\\Commerce\\Currency', 'effective_code')) {
            return \MillionDollarScript\Commerce\Currency::effective_code($value);
        }

        if (self::currency_locked()) {
            return self::default_currency();
        }

        return self::currency($value ?: self::default_currency());
    }

    public static function currency_locked() {
        $settings = get_option('mds3_settings', []);
        $settings = is_array($settings) ? $settings : [];

        return class_exists('\\MillionDollarScript\\Commerce\\Currency') && \MillionDollarScript\Commerce\Currency::provider_locks_currency($settings);
    }

    private static function currency($value) {
        if (class_exists('\\MillionDollarScript\\Commerce\\Currency') && method_exists('\\MillionDollarScript\\Commerce\\Currency', 'normalize_code')) {
            return \MillionDollarScript\Commerce\Currency::normalize_code($value, self::default_currency());
        }

        $code = strtoupper(substr(preg_replace('/[^A-Z]/', '', strtoupper(sanitize_text_field((string) $value))), 0, 3));

        return '' !== $code ? $code : 'USD';
    }

    private static function acquire_board_lock($board_id) {
        global $wpdb;
        $lock_name = 'mds_be_board_' . absint($board_id);

        return 1 === (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 3)', $lock_name));
    }

    private static function release_board_lock($board_id) {
        global $wpdb;
        $lock_name = 'mds_be_board_' . absint($board_id);
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
    }

    private static function private_data_timestamp($post) {
        $updated = absint(get_post_meta($post->ID, '_mds_be_private_data_updated_at', true));
        if ($updated) {
            return $updated;
        }

        $created = strtotime((string) ($post->post_date_gmt ?: $post->post_date));

        return $created ?: time();
    }

    private static function retention_posts($post_type, $board_id, $email_key, $cutoff, $limit, array $extra_meta = []) {
        $base_meta = array_merge([
            ['key' => '_mds_be_board_id', 'value' => absint($board_id), 'compare' => '='],
            ['key' => sanitize_key((string) $email_key), 'value' => '', 'compare' => '!='],
        ], $extra_meta);
        $common = [
            'post_type' => sanitize_key((string) $post_type),
            'post_status' => ['publish', 'pending', 'draft'],
            'orderby' => 'date',
            'order' => 'ASC',
            'posts_per_page' => max(1, absint($limit)),
            'no_found_rows' => true,
        ];
        $modern = get_posts(array_merge($common, [
            'meta_query' => array_merge($base_meta, [[
                'key' => '_mds_be_private_data_updated_at',
                'value' => absint($cutoff),
                'type' => 'NUMERIC',
                'compare' => '<=',
            ]]),
        ]));
        $remaining = max(0, absint($limit) - count($modern));
        if (!$remaining) {
            return $modern;
        }

        $legacy = get_posts(array_merge($common, [
            'meta_query' => array_merge($base_meta, [['key' => '_mds_be_private_data_updated_at', 'compare' => 'NOT EXISTS']]),
            'date_query' => [['before' => gmdate('Y-m-d H:i:s', absint($cutoff)), 'inclusive' => true]],
            'posts_per_page' => $remaining,
        ]));

        return array_merge($modern, $legacy);
    }

    private static function collection(\WP_Query $query, $callback, $page, $per_page) {
        $items = array_values(array_filter(array_map($callback, $query->posts)));
        $total = absint($query->found_posts);

        return [
            'items' => $items,
            'page' => max(1, absint($page)),
            'per_page' => max(1, absint($per_page)),
            'total' => $total,
            'pages' => max(1, (int) ceil($total / max(1, absint($per_page)))),
        ];
    }

    private static function empty_collection($page, $per_page) {
        return [
            'items' => [],
            'page' => max(1, absint($page)),
            'per_page' => max(1, absint($per_page)),
            'total' => 0,
            'pages' => 1,
        ];
    }

    private static function http_url($value) {
        return esc_url_raw(self::bounded_raw($value, 2048), ['http', 'https']);
    }

    private static function bounded_raw($value, $length) {
        $value = trim((string) $value);
        $length = max(1, absint($length));

        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }

    private static function bounded_text($value, $length) {
        return sanitize_text_field(self::bounded_raw($value, $length));
    }

    private static function bounded_textarea($value, $length) {
        return sanitize_textarea_field(self::bounded_raw($value, $length));
    }
}
