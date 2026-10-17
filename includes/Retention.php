<?php
/**
 * Scheduled private-data retention cleanup.
 *
 * @package MillionDollarScript\Extensions\BountyEngine
 */

namespace MillionDollarScript\Extensions\BountyEngine;

if (!defined('ABSPATH')) {
    exit;
}

final class Retention {

    public static function run() {
        $page = 1;
        do {
            $boards = Repository::boards_page($page, 50);
            foreach ($boards['items'] as $board) {
                $iterations = 0;
                do {
                    $changed = Repository::cleanup_board_private_data(
                        absint($board['id'] ?? 0),
                        max(1, absint($board['retention_days'] ?? 365))
                    );
                    $iterations++;
                } while ($changed > 0 && $iterations < 20);
            }
            $page++;
        } while ($page <= absint($boards['pages'] ?? 1));

        return true;
    }
}
