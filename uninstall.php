<?php
/** Bounty Engine uninstall cleanup. */
if (!defined('WP_UNINSTALL_PLUGIN') || !class_exists('\\MillionDollarScript\\Extensions\\CleanupPolicy')) { exit; }
\MillionDollarScript\Extensions\CleanupPolicy::cleanup('mds-bounty-engine', [
    'post_types' => ['mds_be_board', 'mds_be_bounty', 'mds_be_claim'],
    'option_prefixes' => ['mds_bounty_engine_'],
    'transient_prefixes' => ['mds_bounty_engine_'],
    'cron_hooks' => ['mds_bounty_engine_privacy_cleanup'],
]);
