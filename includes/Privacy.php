<?php
/**
 * WordPress privacy export and erasure integration.
 *
 * @package MillionDollarScript\Extensions\BountyEngine
 */

namespace MillionDollarScript\Extensions\BountyEngine;

if (!defined('ABSPATH')) {
    exit;
}

final class Privacy {

    private const PAGE_SIZE = 50;

    public static function exporter($email_address, $page = 1) {
        $page = max(1, absint($page));
        $records = Repository::personal_records_page($email_address, $page, self::PAGE_SIZE);
        $items = [];

        foreach ($records['items'] as $item) {
            $record = (array) ($item['record'] ?? []);
            if ('bounty' === (string) ($item['type'] ?? '')) {
                $items[] = [
                    'group_id' => 'mds-bounty-engine',
                    'group_label' => __('Bounty Engine', 'mds-bounty-engine'),
                    'item_id' => 'bounty-' . absint($record['id'] ?? 0),
                    'data' => [
                        ['name' => __('Bounty', 'mds-bounty-engine'), 'value' => (string) ($record['title'] ?? '')],
                        ['name' => __('Status', 'mds-bounty-engine'), 'value' => (string) ($record['status'] ?? '')],
                        ['name' => __('Requester', 'mds-bounty-engine'), 'value' => (string) ($record['requester_name'] ?? '')],
                        ['name' => __('Requester email', 'mds-bounty-engine'), 'value' => (string) ($record['requester_email'] ?? '')],
                    ],
                ];
                continue;
            }

            $bounty = Repository::bounty(absint($record['bounty_id'] ?? 0));
            $items[] = [
                'group_id' => 'mds-bounty-engine',
                'group_label' => __('Bounty Engine', 'mds-bounty-engine'),
                'item_id' => 'claim-' . absint($record['id'] ?? 0),
                'data' => [
                    ['name' => __('Claim for', 'mds-bounty-engine'), 'value' => (string) ($bounty['title'] ?? __('Deleted bounty', 'mds-bounty-engine'))],
                    ['name' => __('Status', 'mds-bounty-engine'), 'value' => (string) ($record['status'] ?? '')],
                    ['name' => __('Display name', 'mds-bounty-engine'), 'value' => (string) ($record['display_name'] ?? '')],
                    ['name' => __('Email', 'mds-bounty-engine'), 'value' => (string) ($record['email'] ?? '')],
                    ['name' => __('Proposal', 'mds-bounty-engine'), 'value' => (string) ($record['proposal'] ?? '')],
                    ['name' => __('Work URL', 'mds-bounty-engine'), 'value' => (string) ($record['work_url'] ?? '')],
                ],
            ];
        }

        return [
            'data' => $items,
            'done' => $page >= absint($records['pages'] ?? 1),
        ];
    }

    public static function eraser($email_address, $page = 1) {
        unset($page);
        $records = Repository::personal_records_page($email_address, 1, self::PAGE_SIZE);
        $changed = 0;

        foreach ($records['items'] as $item) {
            $record = (array) ($item['record'] ?? []);
            $ok = 'bounty' === (string) ($item['type'] ?? '')
                ? Repository::anonymize_bounty(absint($record['id'] ?? 0))
                : Repository::anonymize_claim(absint($record['id'] ?? 0), true);
            if ($ok) {
                $changed++;
            }
        }

        $remaining = Repository::personal_records_page($email_address, 1, 1);

        return [
            'items_removed' => $changed > 0,
            'items_retained' => $changed > 0,
            'messages' => $changed ? [__('Bounty Engine contact fields were anonymized. Private claim text and work links were removed while public and moderation records were retained.', 'mds-bounty-engine')] : [],
            'done' => empty($remaining['items']),
        ];
    }
}
