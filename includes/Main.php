<?php
/**
 * Bounty Engine runtime, admin UI, frontend, REST API, and privacy hooks.
 *
 * @package MillionDollarScript\Extensions\BountyEngine
 */

namespace MillionDollarScript\Extensions\BountyEngine;

if (!defined('ABSPATH')) {
    exit;
}

final class Main {

    public const REST_NAMESPACE = 'million-dollar-script/v1';
    public const REST_BASE = 'bounty-engine';
    public const CRON_HOOK = 'mds_bounty_engine_privacy_cleanup';

    private static $instance;

    public static function instance() {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function register() {
        add_action('init', [__CLASS__, 'register_post_types'], 5);
        add_action('init', [$this, 'load_textdomain']);
        add_action('init', [$this, 'register_shortcodes']);
        add_action('admin_menu', [$this, 'admin_menu'], 20);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('wp_enqueue_scripts', [$this, 'frontend_assets']);
        add_action('admin_post_mds_bounty_engine_save_board', [$this, 'handle_save_board']);
        add_action('admin_post_mds_bounty_engine_moderate_bounty', [$this, 'handle_moderate_bounty']);
        add_action('admin_post_mds_bounty_engine_moderate_claim', [$this, 'handle_moderate_claim']);
        add_action('admin_post_mds_bounty_engine_submit_bounty', [$this, 'handle_submit_bounty']);
        add_action('admin_post_nopriv_mds_bounty_engine_submit_bounty', [$this, 'handle_submit_bounty']);
        add_action('admin_post_mds_bounty_engine_submit_claim', [$this, 'handle_submit_claim']);
        add_action('admin_post_nopriv_mds_bounty_engine_submit_claim', [$this, 'handle_submit_claim']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('million-dollar-script/payment/source/status', [$this, 'handle_payment_status'], 10, 4);
        add_action(self::CRON_HOOK, [$this, 'process_privacy_cleanup']);
        add_filter('million-dollar-script/api/endpoint/manifest', [$this, 'api_endpoint_manifest']);
        add_filter('million-dollar-script/extension/onboarding/items', [$this, 'extension_onboarding_items']);
        add_filter('million-dollar-script/editor/extension/blocks', [$this, 'editor_blocks']);
        add_filter('wp_privacy_personal_data_exporters', [$this, 'privacy_exporters']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'privacy_erasers']);
    }

    public static function register_post_types() {
        register_post_type(Repository::BOARD_POST_TYPE, [
            'label' => __('Bounty Boards', 'mds-bounty-engine'),
            'public' => false,
            'show_ui' => false,
            'show_in_rest' => false,
            'supports' => ['title'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ]);

        register_post_type(Repository::BOUNTY_POST_TYPE, [
            'label' => __('Bounties', 'mds-bounty-engine'),
            'public' => false,
            'show_ui' => false,
            'show_in_rest' => false,
            'supports' => ['title', 'editor'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ]);

        register_post_type(Repository::CLAIM_POST_TYPE, [
            'label' => __('Bounty Claims', 'mds-bounty-engine'),
            'public' => false,
            'show_ui' => false,
            'show_in_rest' => false,
            'supports' => ['title', 'editor'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ]);
    }

    public function load_textdomain() {
        load_plugin_textdomain('mds-bounty-engine', false, dirname(MDS_BOUNTY_ENGINE_BASENAME) . '/languages');
    }

    public function register_shortcodes() {
        add_shortcode('mds_bounty_engine', [$this, 'shortcode']);
    }

    public function editor_blocks(array $blocks) {
        $blocks[] = [
            'name' => 'mds-bounty-engine/board',
            'title' => __('Bounty Board', 'mds-bounty-engine'),
            'description' => __('Display a Million Dollar Script bounty board with optional posting and claim forms.', 'mds-bounty-engine'),
            'icon' => 'awards',
            'category' => 'widgets',
            'shortcode' => 'mds_bounty_engine',
            'attributes' => [
                'id' => ['type' => 'number', 'default' => 0],
                'slug' => ['type' => 'string', 'default' => ''],
                'show_post_form' => ['type' => 'boolean', 'default' => true],
                'show_claim_forms' => ['type' => 'boolean', 'default' => true],
                'show_notice' => ['type' => 'boolean', 'default' => true],
            ],
            'controls' => [
                [
                    'attribute' => 'id',
                    'type' => 'entity',
                    'label' => __('Bounty board', 'mds-bounty-engine'),
                    'help' => __('Choose a saved board by numeric ID. Leave this on First active board to let the block pick automatically, or use Custom board ID only when you already know the ID.', 'mds-bounty-engine'),
                    'empty_label' => __('First active board', 'mds-bounty-engine'),
                    'custom_label' => __('Custom board ID', 'mds-bounty-engine'),
                    'custom_help' => __('Enter the numeric database ID for a board that is not shown in the list. This is different from the board slug.', 'mds-bounty-engine'),
                    'options' => $this->block_entity_options(Repository::boards(100), __('Untitled board', 'mds-bounty-engine')),
                ],
                [
                    'attribute' => 'slug',
                    'type' => 'text',
                    'label' => __('Board slug', 'mds-bounty-engine'),
                    'help' => __('Optional URL-safe board slug used only when no specific board ID is selected. Leave blank unless you want this block to find a board by slug.', 'mds-bounty-engine'),
                ],
                [
                    'attribute' => 'show_post_form',
                    'type' => 'toggle',
                    'label' => __('Show bounty posting form', 'mds-bounty-engine'),
                    'help' => __('Display the public form visitors use to submit new bounty posts when capacity is available.', 'mds-bounty-engine'),
                ],
                [
                    'attribute' => 'show_claim_forms',
                    'type' => 'toggle',
                    'label' => __('Show claim forms', 'mds-bounty-engine'),
                    'help' => __('Display claim forms on eligible bounty cards.', 'mds-bounty-engine'),
                ],
                [
                    'attribute' => 'show_notice',
                    'type' => 'toggle',
                    'label' => __('Show no-escrow notice', 'mds-bounty-engine'),
                    'help' => __('Display the public notice explaining that bounty reward handling is outside this extension.', 'mds-bounty-engine'),
                ],
            ],
            'preview' => [
                'title' => __('Bounty Board', 'mds-bounty-engine'),
                'description' => __('Shows approved bounties, reward details, public claims, and optional posting forms.', 'mds-bounty-engine'),
                'rows' => [
                    __('Bounty cards with status and reward labels', 'mds-bounty-engine'),
                    __('Optional claim forms on each bounty', 'mds-bounty-engine'),
                    __('Optional public posting form', 'mds-bounty-engine'),
                ],
            ],
            'render_callback' => [$this, 'render_editor_block'],
        ];

        return $blocks;
    }

    private function block_entity_options(array $items, $fallback_title) {
        $options = [];
        foreach ($items as $item) {
            $id = absint($item['id'] ?? 0);
            if (!$id) {
                continue;
            }

            $label = sprintf('#%d %s', $id, (string) ($item['title'] ?? $fallback_title));
            if (!empty($item['status'])) {
                $label .= ' - ' . ucfirst((string) $item['status']);
            }

            $options[] = [
                'label' => $label,
                'value' => (string) $id,
            ];
        }

        return $options;
    }

    public function render_editor_block(array $attributes = []) {
        return $this->shortcode([
            'id' => absint($attributes['id'] ?? 0),
            'slug' => sanitize_title((string) ($attributes['slug'] ?? '')),
            'show_post_form' => $this->truthy($attributes['show_post_form'] ?? true) ? 'true' : 'false',
            'show_claim_forms' => $this->truthy($attributes['show_claim_forms'] ?? true) ? 'true' : 'false',
            'show_notice' => $this->truthy($attributes['show_notice'] ?? true) ? 'true' : 'false',
        ]);
    }

    public function admin_menu() {
        add_submenu_page(
            $this->admin_parent_slug(),
            __('Bounty Engine', 'mds-bounty-engine'),
            __('Bounty Engine', 'mds-bounty-engine'),
            'manage_options',
            'mds-bounty-engine',
            [$this, 'admin_page']
        );
    }

    public function admin_assets($hook) {
        if (false === strpos((string) $hook, 'mds-bounty-engine')) {
            return;
        }

        if (class_exists('\MillionDollarScript\Core\Runtime')) {
            wp_enqueue_style('million-dollar-script-admin', \MillionDollarScript\Core\Runtime::url('assets/mds3/') . 'css/admin.css', [], \MillionDollarScript\Core\Runtime::version());
            wp_enqueue_script('million-dollar-script-admin', \MillionDollarScript\Core\Runtime::url('assets/mds3/') . 'js/admin.js', [], \MillionDollarScript\Core\Runtime::version(), true);
        }
        wp_enqueue_style('mds-bounty-engine-admin', MDS_BOUNTY_ENGINE_URL . 'assets/css/admin.css', [], MDS_BOUNTY_ENGINE_VERSION);
    }

    public function extension_onboarding_items(array $items) {
        $items['mds-bounty-engine'] = [
            'name' => __('Bounty Engine', 'mds-bounty-engine'),
            'summary' => __('Define bounty posting fees, claim rules, moderation, privacy retention, and no-escrow expectations before opening public boards.', 'mds-bounty-engine'),
            'priority' => 35,
            'actions' => [
                ['label' => __('Open Bounty Engine', 'mds-bounty-engine'), 'url' => admin_url('admin.php?page=mds-bounty-engine'), 'primary' => true],
            ],
            'legal_documents' => [
                [
                    'slug' => 'bounty-board-terms',
                    'title' => __('Bounty Board Terms', 'mds-bounty-engine'),
                    'description' => __('Terms for public bounty posts, paid featured listings, claims, moderation, privacy retention, and no-escrow handling.', 'mds-bounty-engine'),
                    'page_slug' => 'bounty-board-terms',
                    'content' => $this->bounty_legal_content(),
                ],
            ],
        ];

        return $items;
    }

    private function bounty_legal_content() {
        return '<h2>' . esc_html__('Bounty Board Terms', 'mds-bounty-engine') . '</h2>'
            . '<p>' . esc_html__('These terms apply to bounty boards, public requests, paid posts, featured listings, submitter contact details, responses, and claims published through Bounty Engine on {{site_name}}.', 'mds-bounty-engine') . '</p>'
            . '<h3>' . esc_html__('No Escrow or Outcome Guarantee', 'mds-bounty-engine') . '</h3>'
            . '<p>' . esc_html__('Unless a separate written policy says otherwise, {{site_name}} lists bounty opportunities but does not hold escrow, guarantee payment, guarantee claimant performance, verify every submission, or mediate every dispute. Bounty payments, delivery, acceptance, taxes, refunds, chargebacks, reward eligibility, and disputes are handled directly by the posting party and claimant.', 'mds-bounty-engine') . '</p>'
            . '<h3>' . esc_html__('Moderation and Privacy', 'mds-bounty-engine') . '</h3>'
            . '<p>' . esc_html__('Bounty Engine may collect posts, claims, names, email addresses, links, payment references, messages, moderation records, timestamps, and IP-derived abuse signals. This information is used to publish boards, manage claims, contact participants, prevent abuse, support users, handle disputes, and maintain accounting or audit records.', 'mds-bounty-engine') . '</p>'
            . '<h3>' . esc_html__('Content Rules and Removal', 'mds-bounty-engine') . '</h3>'
            . '<p>' . esc_html__('Posting parties and claimants are responsible for accurate requests, lawful offers, intellectual-property permissions, safe links, deliverable descriptions, eligibility rules, deadlines, payment promises, and tax obligations. {{site_name}} may reject, hide, edit for formatting, relabel, pause, or remove submissions that are inaccurate, unlawful, misleading, abusive, infringing, unsafe, malicious, payment-risky, or inconsistent with site rules.', 'mds-bounty-engine') . '</p>'
            . '<h3>' . esc_html__('Requests and Contact', 'mds-bounty-engine') . '</h3>'
            . '<p>' . esc_html__('Participants can send support, correction, takedown, privacy, access, deletion, export, and dispute-related requests through {{contact_method}}. Requests may be limited by legal, accounting, fraud-prevention, security, backup, and dispute-retention obligations.', 'mds-bounty-engine') . '</p>';
    }

    public function frontend_assets() {
        wp_register_style('mds-bounty-engine', MDS_BOUNTY_ENGINE_URL . 'assets/css/style.css', [], MDS_BOUNTY_ENGINE_VERSION);
    }

    public function admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage bounty boards.', 'mds-bounty-engine'));
        }

        $board_page = max(1, absint($_GET['be_board_page'] ?? 1));
        $board_collection = Repository::boards_page($board_page, 20);
        $boards = $board_collection['items'];
        $selected_id = absint($_GET['board_id'] ?? ($boards[0]['id'] ?? 0));
        $board = $selected_id ? Repository::board($selected_id) : null;
        $bounty_page = max(1, absint($_GET['be_bounty_page'] ?? 1));
        $claim_bounty_id = absint($_GET['be_claim_bounty'] ?? 0);
        $claim_page = max(1, absint($_GET['be_claim_page'] ?? 1));
        $bounty_collection = $board ? Repository::bounties_page($board['id'], $bounty_page, 20) : ['items' => [], 'page' => 1, 'pages' => 1, 'total' => 0];
        $bounties = $bounty_collection['items'];
        $new_board = [
            'id' => 0,
            'title' => '',
            'slug' => '',
            'description' => '',
            'status' => 'draft',
            'currency' => Repository::default_currency(),
            'posting_fee' => 25,
            'featured_fee' => 50,
            'bounty_limit' => 50,
            'payment_mode' => 'manual',
            'claims_enabled' => true,
            'disclosure' => __('Bounties are public requests. Payments, rewards, refunds, and delivery terms are handled directly by the parties unless your site states otherwise.', 'mds-bounty-engine'),
            'terms_url' => '',
            'contact_email' => get_option('admin_email'),
            'retention_days' => 365,
            'submission_limit_per_hour' => 10,
        ];
        $form_board = $board ?: $new_board;
        $currency_locked = Repository::currency_locked();
        if ($currency_locked) {
            $form_board['currency'] = Repository::effective_currency($form_board['currency'] ?? '');
        }
        $default_tab = $board ? 'bounties' : 'settings';
        $active_tab = sanitize_key((string) ($_GET['tab'] ?? $default_tab));
        $allowed_tabs = $board ? ['settings', 'bounties'] : ['settings'];
        if (!in_array($active_tab, $allowed_tabs, true)) {
            $active_tab = $default_tab;
        }
        ?>
        <div class="wrap mds3-admin mds-bounty-engine-admin">
            <div class="mds-bounty-engine-hero">
                <div>
                    <p class="mds-bounty-engine-eyebrow"><?php esc_html_e('Million Dollar Script extension', 'mds-bounty-engine'); ?></p>
                    <h1><?php esc_html_e('Bounty Engine', 'mds-bounty-engine'); ?></h1>
                    <p><?php esc_html_e('Run public request boards with paid posting, featured bounties, contributor claims, and no-escrow moderation.', 'mds-bounty-engine'); ?></p>
                </div>
                <div class="mds-extension-header-actions has-shortcode">
                    <?php echo wp_kses_post(\MillionDollarScript\Extensions\Admin::docs_button('mds-bounty-engine')); ?>
                    <?php if ($board) : ?>
                        <?php echo wp_kses_post(\MillionDollarScript\Extensions\Admin::shortcode_copy(sprintf('[mds_bounty_engine id="%d"]', absint($board['id'])))); ?>
                    <?php endif; ?>
                </div>
            </div>
            <hr class="wp-header-end">

            <?php $this->admin_notice(); ?>

            <nav class="nav-tab-wrapper wp-clearfix mds-bounty-engine-tabs" aria-label="<?php esc_attr_e('Bounty Engine workspace', 'mds-bounty-engine'); ?>">
                <?php if ($board) : ?>
                    <a class="nav-tab<?php echo 'bounties' === $active_tab ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url($this->admin_url(['board_id' => $selected_id, 'tab' => 'bounties'])); ?>" <?php echo 'bounties' === $active_tab ? 'aria-current="page"' : ''; ?>><?php echo esc_html(sprintf(__('Bounties (%d)', 'mds-bounty-engine'), absint($bounty_collection['total']))); ?></a>
                <?php endif; ?>
                <a class="nav-tab<?php echo 'settings' === $active_tab ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url($this->admin_url(['board_id' => $selected_id, 'tab' => 'settings'])); ?>" <?php echo 'settings' === $active_tab ? 'aria-current="page"' : ''; ?>><?php esc_html_e('Board settings', 'mds-bounty-engine'); ?></a>
            </nav>

            <div class="mds-bounty-engine-layout">
                <section class="mds3-card mds-bounty-engine-panel">
                    <h2><?php esc_html_e('Boards', 'mds-bounty-engine'); ?></h2>
                    <div class="mds-bounty-engine-list">
                        <?php foreach ($boards as $item) : ?>
                            <a class="mds-bounty-engine-list-item <?php echo absint($item['id']) === absint($selected_id) ? 'is-selected' : ''; ?>" href="<?php echo esc_url($this->admin_url(['board_id' => $item['id'], 'tab' => $active_tab])); ?>">
                                <strong><?php echo esc_html($item['title']); ?></strong>
                                <span><?php echo esc_html($item['status']); ?> · <?php echo esc_html(absint($item['used_bounties']) . '/' . absint($item['bounty_limit'])); ?> · <?php echo esc_html($this->format_money($item['posting_fee'], $item['currency'])); ?></span>
                            </a>
                        <?php endforeach; ?>
                        <a class="button" href="<?php echo esc_url($this->admin_url(['board_id' => 0, 'tab' => 'settings'])); ?>"><?php esc_html_e('New board', 'mds-bounty-engine'); ?></a>
                    </div>
                    <?php $this->pagination($board_collection, 'be_board_page', ['board_id' => $selected_id, 'tab' => $active_tab]); ?>
                </section>

                <?php if ('settings' === $active_tab) : ?>
                <section class="mds3-card mds-bounty-engine-panel">
                    <h2><?php echo $board ? esc_html__('Board settings', 'mds-bounty-engine') : esc_html__('Create board', 'mds-bounty-engine'); ?></h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mds-bounty-engine-form">
                        <?php wp_nonce_field('mds_bounty_engine_save_board_' . absint($form_board['id'])); ?>
                        <input type="hidden" name="action" value="mds_bounty_engine_save_board">
                        <input type="hidden" name="board_id" value="<?php echo esc_attr((string) $form_board['id']); ?>">

                        <?php $this->field('title', __('Title', 'mds-bounty-engine'), $form_board['title'], 'text', true); ?>
                        <?php $this->field('slug', __('Slug', 'mds-bounty-engine'), $form_board['slug'], 'text'); ?>
                        <?php $this->field('description', __('Description', 'mds-bounty-engine'), $form_board['description'], 'textarea'); ?>
                        <?php $this->select('status', __('Status', 'mds-bounty-engine'), $form_board['status'], ['draft' => __('Draft', 'mds-bounty-engine'), 'active' => __('Active', 'mds-bounty-engine'), 'paused' => __('Paused', 'mds-bounty-engine'), 'archived' => __('Archived', 'mds-bounty-engine')]); ?>
                        <?php $this->field('currency', __('Currency', 'mds-bounty-engine'), $form_board['currency'], 'text', true, $currency_locked); ?>
                        <?php if ($currency_locked) : ?><p class="mds-bounty-engine-note"><?php esc_html_e('The active payment provider controls currency for this site.', 'mds-bounty-engine'); ?></p><?php endif; ?>
                        <?php $this->field('posting_fee', __('Posting fee', 'mds-bounty-engine'), $form_board['posting_fee'], 'number'); ?>
                        <?php $this->field('featured_fee', __('Featured fee', 'mds-bounty-engine'), $form_board['featured_fee'], 'number'); ?>
                        <?php $this->field('bounty_limit', __('Board capacity', 'mds-bounty-engine'), $form_board['bounty_limit'], 'number'); ?>
                        <?php $this->select('payment_mode', __('Payment mode', 'mds-bounty-engine'), $form_board['payment_mode'], ['manual' => __('Manual or free posting', 'mds-bounty-engine'), 'provider' => __('Use MDS payment provider', 'mds-bounty-engine')]); ?>
                        <?php $this->field('contact_email', __('Contact email', 'mds-bounty-engine'), $form_board['contact_email'], 'email'); ?>
                        <?php $this->field('retention_days', __('Private data retention days', 'mds-bounty-engine'), $form_board['retention_days'], 'number'); ?>
                        <?php $this->field('submission_limit_per_hour', __('Submissions per visitor per hour', 'mds-bounty-engine'), $form_board['submission_limit_per_hour'], 'number'); ?>
                        <?php $this->field('terms_url', __('Terms URL', 'mds-bounty-engine'), $form_board['terms_url'], 'url'); ?>
                        <?php $this->field('disclosure', __('Payment and reward disclosure', 'mds-bounty-engine'), $form_board['disclosure'], 'textarea'); ?>
                        <label class="mds-bounty-engine-check"><input type="checkbox" name="claims_enabled" value="1" <?php checked(!empty($form_board['claims_enabled'])); ?>> <span><?php esc_html_e('Accept public claims/submissions', 'mds-bounty-engine'); ?></span></label>
                        <p class="mds-bounty-engine-note"><?php esc_html_e('No escrow or payout custody is included. Use direct agreements, invoices, or approved third-party payment providers for rewards.', 'mds-bounty-engine'); ?></p>
                        <p class="submit"><button class="button button-primary" type="submit"><?php esc_html_e('Save board', 'mds-bounty-engine'); ?></button></p>
                    </form>
                </section>
                <?php endif; ?>

            <?php if ($board && 'bounties' === $active_tab) : ?>
                <section class="mds3-card mds-bounty-engine-panel">
                    <h2><?php esc_html_e('Bounties', 'mds-bounty-engine'); ?></h2>
                    <?php if (!$bounties) : ?>
                        <p><?php esc_html_e('No bounties yet.', 'mds-bounty-engine'); ?></p>
                        <p><a class="button" href="<?php echo esc_url($this->admin_url(['board_id' => $board['id'], 'tab' => 'settings'])); ?>"><?php esc_html_e('Review board settings', 'mds-bounty-engine'); ?></a></p>
                    <?php else : ?>
                        <div class="mds-bounty-engine-submissions">
                            <?php foreach ($bounties as $bounty) : ?>
                                <article>
                                    <div>
                                        <strong><?php echo esc_html($bounty['title']); ?></strong>
                                        <span><?php echo esc_html($bounty['status']); ?> · <?php echo esc_html($bounty['payment_status']); ?> · <?php echo esc_html($this->format_money($bounty['amount'], $bounty['currency'])); ?> · <?php echo esc_html(sprintf(_n('%d claim', '%d claims', absint($bounty['claim_count']), 'mds-bounty-engine'), absint($bounty['claim_count']))); ?></span>
                                        <p><?php echo esc_html($bounty['category'] ?: wp_trim_words($bounty['description'], 18)); ?></p>
                                    </div>
                                    <div class="mds-bounty-engine-actions">
                                        <?php $this->bounty_button($bounty['id'], 'approve', __('Approve', 'mds-bounty-engine')); ?>
                                        <?php $this->bounty_button($bounty['id'], 'reject', __('Reject', 'mds-bounty-engine')); ?>
                                        <?php $this->bounty_button($bounty['id'], 'review', __('Mark in review', 'mds-bounty-engine')); ?>
                                        <?php $this->bounty_button($bounty['id'], 'complete', __('Complete', 'mds-bounty-engine')); ?>
                                        <?php $this->bounty_button($bounty['id'], 'archive', __('Archive', 'mds-bounty-engine')); ?>
                                        <?php if (!empty($bounty['external_url'])) : ?>
                                            <a class="button" href="<?php echo esc_url($bounty['external_url']); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Open', 'mds-bounty-engine'); ?></a>
                                        <?php endif; ?>
                                    </div>
                                    <?php $reviewing_all_claims = absint($bounty['id']) === $claim_bounty_id; ?>
                                    <?php $claims_collection = Repository::claims_page($bounty['id'], $reviewing_all_claims ? $claim_page : 1, 10); ?>
                                    <?php $claims = $claims_collection['items']; ?>
                                    <?php if ($claims) : ?>
                                        <div class="mds-bounty-engine-claims">
                                            <h3><?php echo esc_html(sprintf(_n('%d claim', '%d claims', absint($claims_collection['total']), 'mds-bounty-engine'), absint($claims_collection['total']))); ?></h3>
                                            <?php foreach ($claims as $claim) : ?>
                                                <div>
                                                    <span><?php echo esc_html($claim['display_name']); ?> · <?php echo esc_html($claim['status']); ?></span>
                                                    <p><?php echo esc_html(wp_trim_words($claim['proposal'], 24)); ?></p>
                                                    <div class="mds-bounty-engine-actions">
                                                        <?php $this->claim_button($claim['id'], 'approve', __('Approve claim', 'mds-bounty-engine')); ?>
                                                        <?php $this->claim_button($claim['id'], 'reject', __('Reject claim', 'mds-bounty-engine')); ?>
                                                        <?php if (!empty($claim['work_url'])) : ?>
                                                            <a class="button" href="<?php echo esc_url($claim['work_url']); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Work', 'mds-bounty-engine'); ?></a>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                            <?php if (absint($claims_collection['pages']) > 1 && !$reviewing_all_claims) : ?>
                                                <p class="mds-bounty-engine-claim-limit"><a class="button" href="<?php echo esc_url($this->admin_url(['board_id' => $board['id'], 'tab' => 'bounties', 'be_bounty_page' => $bounty_page, 'be_claim_bounty' => $bounty['id']])); ?>"><?php echo esc_html(sprintf(__('Review all %d claims', 'mds-bounty-engine'), absint($claims_collection['total']))); ?></a></p>
                                            <?php elseif ($reviewing_all_claims) : ?>
                                                <?php $this->pagination($claims_collection, 'be_claim_page', ['board_id' => $board['id'], 'tab' => 'bounties', 'be_bounty_page' => $bounty_page, 'be_claim_bounty' => $bounty['id']]); ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        </div>
                        <?php $this->pagination($bounty_collection, 'be_bounty_page', ['board_id' => $board['id'], 'tab' => 'bounties']); ?>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public function handle_save_board() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to save bounty boards.', 'mds-bounty-engine'));
        }

        $board_id = absint($_POST['board_id'] ?? 0);
        check_admin_referer('mds_bounty_engine_save_board_' . $board_id);
        $saved = Repository::save_board([
            'title' => wp_unslash($_POST['title'] ?? ''),
            'slug' => wp_unslash($_POST['slug'] ?? ''),
            'description' => wp_unslash($_POST['description'] ?? ''),
            'status' => wp_unslash($_POST['status'] ?? 'draft'),
            'currency' => Repository::currency_locked() ? Repository::default_currency() : wp_unslash($_POST['currency'] ?? Repository::default_currency()),
            'posting_fee' => wp_unslash($_POST['posting_fee'] ?? 0),
            'featured_fee' => wp_unslash($_POST['featured_fee'] ?? 0),
            'bounty_limit' => wp_unslash($_POST['bounty_limit'] ?? 50),
            'payment_mode' => wp_unslash($_POST['payment_mode'] ?? 'manual'),
            'claims_enabled' => !empty($_POST['claims_enabled']) ? '1' : '0',
            'disclosure' => wp_unslash($_POST['disclosure'] ?? ''),
            'terms_url' => wp_unslash($_POST['terms_url'] ?? ''),
            'contact_email' => wp_unslash($_POST['contact_email'] ?? ''),
            'retention_days' => wp_unslash($_POST['retention_days'] ?? 365),
            'submission_limit_per_hour' => wp_unslash($_POST['submission_limit_per_hour'] ?? 10),
        ], $board_id);

        $this->redirect(['board_id' => $saved ?: $board_id, 'tab' => 'settings', 'mds_be_status' => $saved ? 'saved' : 'error']);
    }

    public function handle_moderate_bounty() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to moderate bounties.', 'mds-bounty-engine'));
        }

        $bounty_id = absint($_POST['bounty_id'] ?? 0);
        check_admin_referer('mds_bounty_engine_moderate_bounty_' . $bounty_id);
        $bounty = Repository::bounty($bounty_id);
        $status = sanitize_key((string) ($_POST['moderation'] ?? ''));
        $ok = $bounty && Repository::update_bounty_status($bounty_id, $status);
        $this->redirect([
            'board_id' => absint($bounty['board_id'] ?? 0),
            'tab' => 'bounties',
            'mds_be_status' => $ok ? 'moderated' : 'error',
        ]);
    }

    public function handle_moderate_claim() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to moderate claims.', 'mds-bounty-engine'));
        }

        $claim_id = absint($_POST['claim_id'] ?? 0);
        check_admin_referer('mds_bounty_engine_moderate_claim_' . $claim_id);
        $claim = Repository::claim($claim_id);
        $status = sanitize_key((string) ($_POST['moderation'] ?? ''));
        $ok = $claim && Repository::update_claim_status($claim_id, $status);
        $this->redirect([
            'board_id' => absint($claim['board_id'] ?? 0),
            'tab' => 'bounties',
            'mds_be_status' => $ok ? 'claim_moderated' : 'error',
        ]);
    }

    public function handle_submit_bounty() {
        $board_id = absint($_POST['board_id'] ?? 0);
        check_admin_referer('mds_bounty_engine_submit_bounty_' . $board_id);
        $board = Repository::board($board_id);
        $referrer = esc_url_raw(wp_unslash($_POST['_wp_http_referer'] ?? wp_get_referer() ?: home_url('/')));
        if (!$board || 'active' !== (string) ($board['status'] ?? '') || !Repository::has_open_bounty_slots($board_id)) {
            $this->safe_redirect($referrer, ['bounty_engine_status' => 'closed']);
        }
        if (!empty($_POST['company'])) {
            $this->safe_redirect($referrer, ['bounty_engine_status' => 'submitted']);
        }
        if ($this->rate_limited('submission_' . $board_id, absint($board['submission_limit_per_hour'] ?? 10))) {
            $this->safe_redirect($referrer, ['bounty_engine_status' => 'rate_limited']);
        }

        $data = [
            'title' => wp_unslash($_POST['title'] ?? ''),
            'description' => wp_unslash($_POST['description'] ?? ''),
            'requester_name' => wp_unslash($_POST['requester_name'] ?? ''),
            'requester_email' => wp_unslash($_POST['requester_email'] ?? ''),
            'category' => wp_unslash($_POST['category'] ?? ''),
            'reward_label' => wp_unslash($_POST['reward_label'] ?? ''),
            'external_url' => wp_unslash($_POST['external_url'] ?? ''),
            'due_at' => wp_unslash($_POST['due_at'] ?? ''),
            'featured' => !empty($_POST['featured']) ? '1' : '0',
            'terms_accepted' => !empty($_POST['terms_accepted']) ? '1' : '0',
        ];
        $validation = Repository::validate_bounty_submission($board_id, $data);
        if (is_wp_error($validation)) {
            $this->safe_redirect($referrer, ['bounty_engine_status' => $this->validation_status($validation)]);
        }

        $bounty_id = Repository::create_bounty($board_id, $data);
        if (!$bounty_id) {
            $this->safe_redirect($referrer, ['bounty_engine_status' => 'failed']);
        }

        $bounty = Repository::bounty($bounty_id);
        $this->notify_bounty_submission($board, $bounty);
        if ($this->should_start_checkout($board, $bounty)) {
            try {
                $checkout = $this->start_checkout($board, $bounty, $referrer);
            } catch (\Throwable $error) {
                $checkout = new \WP_Error('mds_bounty_engine_checkout_failed', $error->getMessage());
            }
            if (is_array($checkout) && !empty($checkout['checkout_url'])) {
                $this->checkout_redirect((string) $checkout['checkout_url'], $referrer);
            }
            Repository::update_payment_status($bounty_id, 'failed');
            $this->safe_redirect($referrer, ['bounty_engine_status' => 'checkout_failed']);
        }

        $this->safe_redirect($referrer, ['bounty_engine_status' => 'submitted']);
    }

    public function handle_submit_claim() {
        $bounty_id = absint($_POST['bounty_id'] ?? 0);
        check_admin_referer('mds_bounty_engine_submit_claim_' . $bounty_id);
        $bounty = Repository::bounty($bounty_id);
        $board = $bounty ? Repository::board(absint($bounty['board_id'] ?? 0)) : null;
        $referrer = esc_url_raw(wp_unslash($_POST['_wp_http_referer'] ?? wp_get_referer() ?: home_url('/')));
        if (!$bounty || !$board || empty($board['claims_enabled'])) {
            $this->safe_redirect($referrer, ['bounty_engine_status' => 'claims_closed']);
        }
        if (!empty($_POST['company'])) {
            $this->safe_redirect($referrer, ['bounty_engine_status' => 'claim_submitted']);
        }
        if ($this->rate_limited('submission_' . absint($board['id'] ?? 0), absint($board['submission_limit_per_hour'] ?? 10))) {
            $this->safe_redirect($referrer, ['bounty_engine_status' => 'rate_limited']);
        }

        $data = [
            'display_name' => wp_unslash($_POST['display_name'] ?? ''),
            'email' => wp_unslash($_POST['email'] ?? ''),
            'proposal' => wp_unslash($_POST['proposal'] ?? ''),
            'work_url' => wp_unslash($_POST['work_url'] ?? ''),
            'terms_accepted' => !empty($_POST['terms_accepted']) ? '1' : '0',
        ];
        $validation = Repository::validate_claim_submission($bounty_id, $data);
        if (is_wp_error($validation)) {
            $this->safe_redirect($referrer, ['bounty_engine_status' => $this->validation_status($validation)]);
        }

        $claim_id = Repository::create_claim($bounty_id, $data);
        if ($claim_id) {
            $this->notify_claim_submission($board, $bounty, Repository::claim($claim_id));
        }

        $this->safe_redirect($referrer, ['bounty_engine_status' => $claim_id ? 'claim_submitted' : 'failed']);
    }

    public function shortcode($atts) {
        $atts = shortcode_atts([
            'id' => 0,
            'slug' => '',
            'show_post_form' => 'true',
            'show_claim_forms' => 'true',
            'show_notice' => 'true',
        ], $atts, 'mds_bounty_engine');
        $show_post_form = $this->truthy($atts['show_post_form']);
        $show_claim_forms = $this->truthy($atts['show_claim_forms']);
        $show_notice = $this->truthy($atts['show_notice']);
        if (absint($atts['id'])) {
            $board = Repository::board(absint($atts['id']));
        } elseif ('' !== (string) $atts['slug']) {
            $board = Repository::board_by_slug((string) $atts['slug']);
        } else {
            $board = $this->first_active_board();
        }
        if (!$board || 'active' !== (string) ($board['status'] ?? '')) {
            return current_user_can('manage_options') ? '<p class="mds-bounty-engine-empty">' . esc_html__('Bounty board is not active.', 'mds-bounty-engine') . '</p>' : '';
        }

        wp_enqueue_style('mds-bounty-engine');
        $board['currency'] = Repository::effective_currency($board['currency'] ?? '');
        $page_key = 'bounty_page_' . absint($board['id']);
        $bounty_page = max(1, absint($_GET[$page_key] ?? 1));
        $bounty_collection = Repository::bounties_page($board['id'], $bounty_page, 24, true);
        $bounties = array_values(array_filter(array_map([Repository::class, 'public_bounty_payload'], $bounty_collection['items'])));
        ob_start();
        ?>
        <section class="mds-bounty-engine" data-board-id="<?php echo esc_attr((string) $board['id']); ?>">
            <header class="mds-bounty-engine__header">
                <span><?php esc_html_e('Bounty Board', 'mds-bounty-engine'); ?></span>
                <h2><?php echo esc_html($board['title']); ?></h2>
                <?php if (!empty($board['description'])) : ?>
                    <p><?php echo esc_html($board['description']); ?></p>
                <?php endif; ?>
                <div class="mds-bounty-engine__summary">
                    <span><?php echo esc_html(sprintf(__('%1$d of %2$d bounties posted', 'mds-bounty-engine'), absint($board['used_bounties']), absint($board['bounty_limit']))); ?></span>
                    <span><?php echo esc_html(sprintf(__('Posting fee %s', 'mds-bounty-engine'), $this->format_money($board['posting_fee'], $board['currency']))); ?></span>
                    <span><?php echo esc_html(sprintf(__('Featured +%s', 'mds-bounty-engine'), $this->format_money($board['featured_fee'], $board['currency']))); ?></span>
                </div>
                <?php if (!empty($board['disclosure'])) : ?>
                    <small><?php echo esc_html($board['disclosure']); ?></small>
                <?php endif; ?>
            </header>

            <?php if ($show_notice) : ?>
                <?php $this->public_notice(); ?>
            <?php endif; ?>

            <div class="mds-bounty-engine__grid">
                <?php foreach ($bounties as $bounty) : ?>
                    <article class="mds-bounty-engine__card is-<?php echo esc_attr((string) ($bounty['status'] ?? 'open')); ?> <?php echo !empty($bounty['featured']) ? 'is-featured' : ''; ?>">
                        <div class="mds-bounty-engine__meta">
                            <?php if (!empty($bounty['featured'])) : ?><span><?php esc_html_e('Featured', 'mds-bounty-engine'); ?></span><?php endif; ?>
                            <span><?php echo esc_html(ucwords(str_replace('_', ' ', (string) $bounty['status']))); ?></span>
                            <?php if (!empty($bounty['category'])) : ?><span><?php echo esc_html($bounty['category']); ?></span><?php endif; ?>
                        </div>
                        <h3><?php echo esc_html($bounty['title']); ?></h3>
                        <p><?php echo esc_html($bounty['description']); ?></p>
                        <?php if (!empty($bounty['reward_label'])) : ?><p class="mds-bounty-engine__price"><?php echo esc_html($bounty['reward_label']); ?></p><?php endif; ?>
                        <div class="mds-bounty-engine__meta">
                            <?php if (!empty($bounty['due_at'])) : ?><span><?php echo esc_html(sprintf(__('Due %s', 'mds-bounty-engine'), $this->format_date($bounty['due_at']))); ?></span><?php endif; ?>
                            <span><?php echo esc_html(sprintf(_n('%d claim', '%d claims', absint($bounty['claim_count']), 'mds-bounty-engine'), absint($bounty['claim_count']))); ?></span>
                        </div>
                        <?php if (!empty($bounty['external_url'])) : ?>
                            <a href="<?php echo esc_url($bounty['external_url']); ?>" rel="nofollow sponsored noopener" target="_blank"><?php esc_html_e('Open details', 'mds-bounty-engine'); ?></a>
                        <?php endif; ?>
                        <?php if ($show_claim_forms && !empty($board['claims_enabled']) && 'completed' !== (string) ($bounty['status'] ?? '') && (empty($bounty['due_at']) || (string) $bounty['due_at'] >= wp_date('Y-m-d'))) : ?>
                            <?php $this->claim_form($board, $bounty); ?>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php $this->public_pagination($bounty_collection, $page_key); ?>

            <?php if (empty($bounties)) : ?>
                <p class="mds-bounty-engine__empty"><?php esc_html_e('Approved bounties will appear here after moderation.', 'mds-bounty-engine'); ?></p>
            <?php endif; ?>

            <?php if ($show_post_form && Repository::has_open_bounty_slots(absint($board['id']))) : ?>
                <?php $this->bounty_form($board); ?>
            <?php endif; ?>
        </section>
        <?php
        return trim((string) ob_get_clean());
    }

    private function truthy($value) {
        if (is_bool($value)) {
            return $value;
        }

        return !in_array(strtolower(trim((string) $value)), ['0', 'false', 'no', 'off'], true);
    }

    public function register_rest_routes() {
        \MillionDollarScript\Extensions\Support::register_rest_route(self::REST_BASE . '/boards', [[
            'methods' => 'GET',
            'callback' => function ($request) {
                $collection = Repository::boards_page(
                    max(1, absint($request->get_param('page'))),
                    min(100, max(1, absint($request->get_param('per_page') ?: 24))),
                    true
                );
                $collection['boards'] = array_values(array_filter(array_map([Repository::class, 'public_board_payload'], $collection['items'])));
                unset($collection['items']);

                return rest_ensure_response([
                    'boards' => $collection['boards'],
                    'page' => $collection['page'],
                    'per_page' => $collection['per_page'],
                    'total' => $collection['total'],
                    'pages' => $collection['pages'],
                ]);
            },
            'permission_callback' => '__return_true',
        ]]);

        \MillionDollarScript\Extensions\Support::register_rest_route(self::REST_BASE . '/boards/(?P<id>\d+)/bounties', [[
            'methods' => 'GET',
            'callback' => function ($request) {
                $board_id = absint($request['id'] ?? 0);
                $board = Repository::public_board_payload(Repository::board($board_id));
                if (!$board) {
                    return new \WP_Error('mds_bounty_engine_board_not_found', __('Active bounty board not found.', 'mds-bounty-engine'), ['status' => 404]);
                }
                $collection = Repository::bounties_page(
                    $board_id,
                    max(1, absint($request->get_param('page'))),
                    min(100, max(1, absint($request->get_param('per_page') ?: 24))),
                    true
                );
                return rest_ensure_response([
                    'board' => $board,
                    'bounties' => array_values(array_filter(array_map([Repository::class, 'public_bounty_payload'], $collection['items']))),
                    'page' => $collection['page'],
                    'per_page' => $collection['per_page'],
                    'total' => $collection['total'],
                    'pages' => $collection['pages'],
                ]);
            },
            'permission_callback' => '__return_true',
        ]]);

        \MillionDollarScript\Extensions\Support::register_rest_route(self::REST_BASE . '/bounties', [[
            'methods' => 'POST',
            'callback' => function ($request) {
                $board_id = absint($request['board_id'] ?? 0);
                $params = (array) $request->get_json_params();
                $validation = Repository::validate_bounty_submission($board_id, $params);
                if (is_wp_error($validation)) {
                    $validation->add_data(['status' => 400]);
                    return $validation;
                }

                $bounty_id = Repository::create_bounty($board_id, $params);
                if (!$bounty_id) {
                    return new \WP_Error('mds_bounty_engine_create_failed', __('Bounty could not be created.', 'mds-bounty-engine'), ['status' => 400]);
                }
                return rest_ensure_response(['bounty' => Repository::bounty($bounty_id)]);
            },
            'permission_callback' => [$this, 'permission_manage'],
        ]]);

        \MillionDollarScript\Extensions\Support::register_rest_route(self::REST_BASE . '/bounties/(?P<id>\d+)/moderate', [[
            'methods' => 'POST',
            'callback' => function ($request) {
                $bounty_id = absint($request['id'] ?? 0);
                $status = sanitize_key((string) ($request['status'] ?? ''));
                if (!Repository::update_bounty_status($bounty_id, $status)) {
                    return new \WP_Error('mds_bounty_engine_moderate_failed', __('Bounty could not be moderated.', 'mds-bounty-engine'), ['status' => 400]);
                }
                return rest_ensure_response(['bounty' => Repository::bounty($bounty_id)]);
            },
            'permission_callback' => [$this, 'permission_manage'],
        ]]);

        \MillionDollarScript\Extensions\Support::register_rest_route(self::REST_BASE . '/claims', [[
            'methods' => 'POST',
            'callback' => function ($request) {
                $params = (array) $request->get_json_params();
                $bounty_id = absint($params['bounty_id'] ?? 0);
                $validation = Repository::validate_claim_submission($bounty_id, $params);
                if (is_wp_error($validation)) {
                    $validation->add_data(['status' => 400]);
                    return $validation;
                }

                $claim_id = Repository::create_claim($bounty_id, $params);
                if (!$claim_id) {
                    return new \WP_Error('mds_bounty_engine_claim_failed', __('Claim could not be created.', 'mds-bounty-engine'), ['status' => 400]);
                }
                return rest_ensure_response(['claim' => Repository::claim($claim_id)]);
            },
            'permission_callback' => [$this, 'permission_manage'],
        ]]);

        \MillionDollarScript\Extensions\Support::register_rest_route(self::REST_BASE . '/claims/(?P<id>\d+)/moderate', [[
            'methods' => 'POST',
            'callback' => function ($request) {
                $claim_id = absint($request['id'] ?? 0);
                $status = sanitize_key((string) ($request['status'] ?? ''));
                if (!Repository::update_claim_status($claim_id, $status)) {
                    return new \WP_Error('mds_bounty_engine_claim_moderate_failed', __('Claim could not be moderated.', 'mds-bounty-engine'), ['status' => 400]);
                }
                return rest_ensure_response(['claim' => Repository::claim($claim_id)]);
            },
            'permission_callback' => [$this, 'permission_manage'],
        ]]);
    }

    public function permission_manage() {
        if (class_exists('\\MillionDollarScript\\Extensions\\Support')) {
            return \MillionDollarScript\Extensions\Support::can_manage_api();
        }

        return current_user_can('manage_options');
    }

    public function handle_payment_status($source, $source_id, $status, $context = []) {
        if ('bounty-engine' !== sanitize_key((string) $source)) {
            return;
        }

        $status = sanitize_key((string) $status);
        if (in_array($status, ['paid', 'completed', 'processing'], true)) {
            Repository::update_payment_status(absint($source_id), 'paid');
            return;
        }

        if (in_array($status, ['failed', 'cancelled', 'expired', 'refunded'], true)) {
            Repository::update_payment_status(absint($source_id), $status);
        }
    }

    public function process_privacy_cleanup() {
        return Retention::run();
    }

    public function api_endpoint_manifest($manifest) {
        $manifest = is_array($manifest) ? $manifest : [];
        $manifest[] = ['id' => 'bounty-engine-boards-read', 'route' => '/million-dollar-script/v1/bounty-engine/boards', 'methods' => ['GET'], 'scope' => 'bounty.read', 'minimum_security_level' => 'public_read', 'description' => __('Read public bounty boards without private contact or operational fields.', 'mds-bounty-engine')];
        $manifest[] = ['id' => 'bounty-engine-bounties-read', 'route' => '/million-dollar-script/v1/bounty-engine/boards/(?P<id>\d+)/bounties', 'methods' => ['GET'], 'scope' => 'bounty.read', 'minimum_security_level' => 'public_read', 'description' => __('Read approved public bounties without requester contact or payment records.', 'mds-bounty-engine')];
        $manifest[] = ['id' => 'bounty-engine-bounties-create', 'route' => '/million-dollar-script/v1/bounty-engine/bounties', 'methods' => ['POST'], 'scope' => 'bounty.write', 'minimum_security_level' => 'wp_capability', 'description' => __('Create bounty records as an administrator.', 'mds-bounty-engine')];
        $manifest[] = ['id' => 'bounty-engine-bounties-moderate', 'route' => '/million-dollar-script/v1/bounty-engine/bounties/(?P<id>\d+)/moderate', 'methods' => ['POST'], 'scope' => 'bounty.write', 'minimum_security_level' => 'wp_capability', 'description' => __('Moderate bounty records as an administrator.', 'mds-bounty-engine')];
        $manifest[] = ['id' => 'bounty-engine-claims-create', 'route' => '/million-dollar-script/v1/bounty-engine/claims', 'methods' => ['POST'], 'scope' => 'bounty.write', 'minimum_security_level' => 'wp_capability', 'description' => __('Create private bounty claims as an administrator.', 'mds-bounty-engine')];
        $manifest[] = ['id' => 'bounty-engine-claims-moderate', 'route' => '/million-dollar-script/v1/bounty-engine/claims/(?P<id>\d+)/moderate', 'methods' => ['POST'], 'scope' => 'bounty.write', 'minimum_security_level' => 'wp_capability', 'description' => __('Moderate private bounty claims as an administrator.', 'mds-bounty-engine')];

        return $manifest;
    }

    public function privacy_exporters($exporters) {
        $exporters['mds-bounty-engine'] = [
            'exporter_friendly_name' => __('Bounty Engine', 'mds-bounty-engine'),
            'callback' => [$this, 'privacy_exporter'],
        ];

        return $exporters;
    }

    public function privacy_exporter($email_address, $page = 1) {
        return Privacy::exporter($email_address, $page);
    }

    public function privacy_erasers($erasers) {
        $erasers['mds-bounty-engine'] = [
            'eraser_friendly_name' => __('Bounty Engine', 'mds-bounty-engine'),
            'callback' => [$this, 'privacy_eraser'],
        ];

        return $erasers;
    }

    public function privacy_eraser($email_address, $page = 1) {
        return Privacy::eraser($email_address, $page);
    }

    private function bounty_form(array $board) {
        ?>
        <details class="mds-bounty-engine__submit">
            <summary><?php esc_html_e('Post a bounty', 'mds-bounty-engine'); ?></summary>
            <p class="mds-bounty-engine__form-note"><?php esc_html_e('Submit a public bounty for moderation. This site does not hold reward funds in escrow, so include clear payment or fulfillment terms.', 'mds-bounty-engine'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('mds_bounty_engine_submit_bounty_' . absint($board['id'])); ?>
                <input type="hidden" name="action" value="mds_bounty_engine_submit_bounty">
                <input type="hidden" name="board_id" value="<?php echo esc_attr((string) $board['id']); ?>">
                <input type="text" name="company" value="" tabindex="-1" autocomplete="off" class="mds-bounty-engine__trap" aria-hidden="true">
                <label><span><?php esc_html_e('Bounty title', 'mds-bounty-engine'); ?></span><input type="text" name="title" required maxlength="120" autocomplete="off"></label>
                <label><span><?php esc_html_e('Your name', 'mds-bounty-engine'); ?></span><input type="text" name="requester_name" maxlength="80" autocomplete="name"></label>
                <label><span><?php esc_html_e('Email', 'mds-bounty-engine'); ?></span><input type="email" name="requester_email" required autocomplete="email"></label>
                <label><span><?php esc_html_e('Category', 'mds-bounty-engine'); ?></span><input type="text" name="category" maxlength="80" autocomplete="off"></label>
                <label><span><?php esc_html_e('Reward or recognition', 'mds-bounty-engine'); ?></span><input type="text" name="reward_label" maxlength="120" autocomplete="off"></label>
                <label><span><?php esc_html_e('Due date', 'mds-bounty-engine'); ?></span><input type="date" name="due_at" autocomplete="off"></label>
                <label class="mds-bounty-engine__wide"><span><?php esc_html_e('Details URL', 'mds-bounty-engine'); ?></span><input type="url" name="external_url" placeholder="https://" autocomplete="url"></label>
                <label class="mds-bounty-engine__wide"><span><?php esc_html_e('Bounty description', 'mds-bounty-engine'); ?></span><textarea name="description" rows="5" required></textarea></label>
                <label class="mds-bounty-engine__check"><input type="checkbox" name="featured" value="1"> <span><?php echo esc_html(sprintf(__('Request featured placement (+%s)', 'mds-bounty-engine'), $this->format_money($board['featured_fee'], $board['currency']))); ?></span></label>
                <?php if (!empty($board['terms_url'])) : ?>
                    <p class="mds-bounty-engine__terms"><a href="<?php echo esc_url($board['terms_url']); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Review posting terms', 'mds-bounty-engine'); ?></a></p>
                <?php endif; ?>
                <label class="mds-bounty-engine__check"><input type="checkbox" name="terms_accepted" value="1" required> <span><?php esc_html_e('I accept the posting terms and understand that this site does not hold bounty rewards in escrow.', 'mds-bounty-engine'); ?></span></label>
                <p class="mds-bounty-engine__price"><?php echo esc_html(sprintf(__('Posting fee: %s. This site does not hold reward funds in escrow.', 'mds-bounty-engine'), $this->format_money($board['posting_fee'], $board['currency']))); ?></p>
                <button type="submit"><?php esc_html_e('Submit bounty for review', 'mds-bounty-engine'); ?></button>
            </form>
        </details>
        <?php
    }

    private function claim_form(array $board, array $bounty) {
        ?>
        <details class="mds-bounty-engine__submit mds-bounty-engine__claim-form">
            <summary><?php esc_html_e('Submit a claim', 'mds-bounty-engine'); ?></summary>
            <p class="mds-bounty-engine__form-note"><?php esc_html_e('Claims are reviewed before they are shared or shown publicly. Include enough detail for the bounty owner to evaluate the work.', 'mds-bounty-engine'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('mds_bounty_engine_submit_claim_' . absint($bounty['id'])); ?>
                <input type="hidden" name="action" value="mds_bounty_engine_submit_claim">
                <input type="hidden" name="bounty_id" value="<?php echo esc_attr((string) $bounty['id']); ?>">
                <input type="text" name="company" value="" tabindex="-1" autocomplete="off" class="mds-bounty-engine__trap" aria-hidden="true">
                <label><span><?php esc_html_e('Name', 'mds-bounty-engine'); ?></span><input type="text" name="display_name" required maxlength="80" autocomplete="name"></label>
                <label><span><?php esc_html_e('Email', 'mds-bounty-engine'); ?></span><input type="email" name="email" required autocomplete="email"></label>
                <label class="mds-bounty-engine__wide"><span><?php esc_html_e('Work URL', 'mds-bounty-engine'); ?></span><input type="url" name="work_url" placeholder="https://" autocomplete="url"></label>
                <label class="mds-bounty-engine__wide"><span><?php esc_html_e('Proposal or submission note', 'mds-bounty-engine'); ?></span><textarea name="proposal" rows="4" required></textarea></label>
                <p class="mds-bounty-engine__terms"><?php esc_html_e('Claims are moderated before they are shared with the bounty owner.', 'mds-bounty-engine'); ?></p>
                <label class="mds-bounty-engine__check"><input type="checkbox" name="terms_accepted" value="1" required> <span><?php esc_html_e('I accept the claim terms and understand that this site does not guarantee payment or hold rewards in escrow.', 'mds-bounty-engine'); ?></span></label>
                <button type="submit"><?php esc_html_e('Submit claim', 'mds-bounty-engine'); ?></button>
            </form>
        </details>
        <?php
    }

    private function should_start_checkout($board, $bounty) {
        return $board && $bounty
            && 'provider' === (string) ($board['payment_mode'] ?? '')
            && (float) ($bounty['amount'] ?? 0) > 0;
    }

    private function start_checkout(array $board, array $bounty, $fallback_url) {
        if (
            class_exists('\MillionDollarScript\Extensions\Subscriptions\Entitlements')
            && \MillionDollarScript\Extensions\Subscriptions\Entitlements::consume(
                'bounty-engine',
                'bounties',
                'bounty:' . absint($bounty['id'] ?? 0),
                [
                    'user_id' => get_current_user_id(),
                    'email' => sanitize_email((string) ($bounty['requester_email'] ?? '')),
                ],
                (string) absint($board['id'] ?? 0)
            )
        ) {
            \MillionDollarScript\Commerce\Payments::mark_source_paid('bounty-engine', absint($bounty['id'] ?? 0), [
                'provider' => 'subscription_allowance',
            ]);
            return [
                'provider' => 'subscription_allowance',
                'status' => 'paid',
                'checkout_url' => add_query_arg('bounty_engine_status', 'submitted', (string) $fallback_url),
            ];
        }

        $amount = (float) ($bounty['amount'] ?? 0);
        $transaction = [
            'source' => 'bounty-engine',
            'source_id' => absint($bounty['id'] ?? 0),
            'source_key' => 'bounty-' . absint($bounty['id'] ?? 0),
            'user_id' => get_current_user_id(),
            'email' => sanitize_email($bounty['requester_email'] ?? ''),
            'currency' => Repository::effective_currency($bounty['currency'] ?? $board['currency'] ?? ''),
            'subtotal' => $amount,
            'total' => $amount,
            'items' => [[
                'name' => sprintf(__('Bounty posting: %s', 'mds-bounty-engine'), $bounty['title']),
                'quantity' => 1,
                'unit_price' => $amount,
                'total' => $amount,
            ]],
            'manage_url' => esc_url_raw((string) $fallback_url),
            'metadata' => [
                'board_id' => absint($board['id'] ?? 0),
                'bounty_id' => absint($bounty['id'] ?? 0),
            ],
        ];

        return \MillionDollarScript\Commerce\Payments::create_checkout($transaction);
    }

    private function field($name, $label, $value, $type = 'text', $required = false, $readonly = false, $help = '') {
        $wide = in_array($type, ['textarea'], true) ? ' mds-bounty-engine-field-wide' : '';
        $help = $help ?: $this->field_help($name);
        $help_id = 'mds-bounty-engine-help-' . sanitize_html_class((string) $name);
        ?>
        <label class="mds-bounty-engine-field<?php echo esc_attr($wide); ?>">
            <span><?php echo esc_html($label); ?></span>
            <?php if ('textarea' === $type) : ?>
                <textarea name="<?php echo esc_attr($name); ?>" rows="4" <?php echo $help ? 'aria-describedby="' . esc_attr($help_id) . '"' : ''; ?> <?php echo $required ? 'required' : ''; ?>><?php echo esc_textarea((string) $value); ?></textarea>
            <?php else : ?>
                <input type="<?php echo esc_attr($type); ?>" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr((string) $value); ?>" <?php echo $help ? 'aria-describedby="' . esc_attr($help_id) . '"' : ''; ?> <?php echo $required ? 'required' : ''; ?> <?php echo $readonly ? 'readonly' : ''; ?> <?php echo 'number' === $type ? 'min="0" step="0.01"' : ''; ?>>
            <?php endif; ?>
            <?php if ($help) : ?>
                <small id="<?php echo esc_attr($help_id); ?>" class="mds-bounty-engine-field-help"><?php echo esc_html($help); ?></small>
            <?php endif; ?>
        </label>
        <?php
    }

    private function select($name, $label, $value, array $options, $help = '') {
        $help = $help ?: $this->field_help($name);
        $help_id = 'mds-bounty-engine-help-' . sanitize_html_class((string) $name);
        ?>
        <label class="mds-bounty-engine-field">
            <span><?php echo esc_html($label); ?></span>
            <select name="<?php echo esc_attr($name); ?>" <?php echo $help ? 'aria-describedby="' . esc_attr($help_id) . '"' : ''; ?>>
                <?php foreach ($options as $option_value => $option_label) : ?>
                    <option value="<?php echo esc_attr((string) $option_value); ?>" <?php selected((string) $value, (string) $option_value); ?>><?php echo esc_html($option_label); ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($help) : ?>
                <small id="<?php echo esc_attr($help_id); ?>" class="mds-bounty-engine-field-help"><?php echo esc_html($help); ?></small>
            <?php endif; ?>
        </label>
        <?php
    }

    private function field_help($name) {
        $help = [
            'title' => __('The public board name shown above bounties.', 'mds-bounty-engine'),
            'slug' => __('Optional URL-safe identifier for shortcodes and integrations. Leave blank to generate one from the title.', 'mds-bounty-engine'),
            'description' => __('Short public summary explaining what people can post on this board.', 'mds-bounty-engine'),
            'status' => __('Only active boards accept public bounty posts.', 'mds-bounty-engine'),
            'currency' => __('Three-letter currency code. WooCommerce or the active payment provider may lock this value.', 'mds-bounty-engine'),
            'posting_fee' => __('Amount charged for each bounty post when paid posting is enabled.', 'mds-bounty-engine'),
            'featured_fee' => __('Extra amount added when a requester asks for featured placement.', 'mds-bounty-engine'),
            'bounty_limit' => __('Maximum number of bounty posts this board can hold.', 'mds-bounty-engine'),
            'payment_mode' => __('Choose manual review or send paid bounty posts through the active MDS payment provider.', 'mds-bounty-engine'),
            'contact_email' => __('Private admin contact used for bounty questions and moderation follow-up.', 'mds-bounty-engine'),
            'retention_days' => __('How long private requester and claimant contact data should be retained.', 'mds-bounty-engine'),
            'submission_limit_per_hour' => __('Maximum bounty and claim submissions accepted from one visitor in an hour. Limits are tracked with a one-way IP-derived key.', 'mds-bounty-engine'),
            'terms_url' => __('Optional public terms page for posting rules, rewards, refunds, and moderation.', 'mds-bounty-engine'),
            'disclosure' => __('Public disclosure explaining reward, payment, and no-escrow expectations.', 'mds-bounty-engine'),
        ];

        return (string) ($help[(string) $name] ?? '');
    }

    private function first_active_board() {
        $boards = Repository::boards_page(1, 1, true);

        return $boards['items'][0] ?? null;
    }

    private function bounty_button($bounty_id, $status, $label) {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('mds_bounty_engine_moderate_bounty_' . absint($bounty_id)); ?>
            <input type="hidden" name="action" value="mds_bounty_engine_moderate_bounty">
            <input type="hidden" name="bounty_id" value="<?php echo esc_attr((string) absint($bounty_id)); ?>">
            <input type="hidden" name="moderation" value="<?php echo esc_attr((string) $status); ?>">
            <button class="button" type="submit"><?php echo esc_html($label); ?></button>
        </form>
        <?php
    }

    private function claim_button($claim_id, $status, $label) {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('mds_bounty_engine_moderate_claim_' . absint($claim_id)); ?>
            <input type="hidden" name="action" value="mds_bounty_engine_moderate_claim">
            <input type="hidden" name="claim_id" value="<?php echo esc_attr((string) absint($claim_id)); ?>">
            <input type="hidden" name="moderation" value="<?php echo esc_attr((string) $status); ?>">
            <button class="button" type="submit"><?php echo esc_html($label); ?></button>
        </form>
        <?php
    }

    private function admin_notice() {
        $status = sanitize_key((string) ($_GET['mds_be_status'] ?? ''));
        $messages = [
            'saved' => __('Board saved.', 'mds-bounty-engine'),
            'moderated' => __('Bounty updated.', 'mds-bounty-engine'),
            'claim_moderated' => __('Claim updated.', 'mds-bounty-engine'),
            'error' => __('Bounty Engine could not complete that action.', 'mds-bounty-engine'),
        ];
        if (isset($messages[$status])) {
            echo '<div class="notice notice-' . ('error' === $status ? 'error' : 'success') . '"><p>' . esc_html($messages[$status]) . '</p></div>';
        }
    }

    private function public_notice() {
        $status = sanitize_key((string) ($_GET['bounty_engine_status'] ?? ''));
        $messages = [
            'submitted' => __('Bounty submitted for review.', 'mds-bounty-engine'),
            'claim_submitted' => __('Claim submitted for review.', 'mds-bounty-engine'),
            'closed' => __('This bounty board is not accepting new posts right now.', 'mds-bounty-engine'),
            'mds_bounty_engine_board_closed' => __('This bounty board is not accepting submissions.', 'mds-bounty-engine'),
            'mds_bounty_engine_board_full' => __('This bounty board has reached its capacity.', 'mds-bounty-engine'),
            'claims_closed' => __('Claims are not available for this bounty right now.', 'mds-bounty-engine'),
            'mds_bounty_engine_claims_closed' => __('Claims are not currently open for this bounty.', 'mds-bounty-engine'),
            'mds_bounty_engine_bounty_unavailable' => __('This bounty is not available for claims.', 'mds-bounty-engine'),
            'mds_bounty_engine_bounty_expired' => __('The bounty due date has passed.', 'mds-bounty-engine'),
            'invalid' => __('Please check the required fields and try again.', 'mds-bounty-engine'),
            'mds_bounty_engine_invalid_bounty' => __('Add a title, description, and valid requester email.', 'mds-bounty-engine'),
            'mds_bounty_engine_terms_required' => __('Accept the posting terms and no-escrow disclosure before submitting.', 'mds-bounty-engine'),
            'mds_bounty_engine_invalid_due_date' => __('Use a valid due date that is not in the past.', 'mds-bounty-engine'),
            'mds_bounty_engine_invalid_url' => __('Use a valid HTTP or HTTPS details URL.', 'mds-bounty-engine'),
            'mds_bounty_engine_invalid_claim' => __('Add a name, proposal, and valid email address.', 'mds-bounty-engine'),
            'mds_bounty_engine_claim_terms_required' => __('Accept the claim and no-escrow terms before submitting.', 'mds-bounty-engine'),
            'mds_bounty_engine_invalid_work_url' => __('Use a valid HTTP or HTTPS work URL.', 'mds-bounty-engine'),
            'rate_limited' => __('Please wait before submitting again.', 'mds-bounty-engine'),
            'failed' => __('Your submission could not be saved.', 'mds-bounty-engine'),
            'checkout_failed' => __('The bounty was saved, but payment checkout could not be started. No charge was made. Contact the site administrator before trying again.', 'mds-bounty-engine'),
        ];
        if (isset($messages[$status])) {
            $success = in_array($status, ['submitted', 'claim_submitted'], true);
            echo '<p class="mds-bounty-engine__notice mds-bounty-engine__notice--' . ($success ? 'success' : 'error') . '" role="status">' . esc_html($messages[$status]) . '</p>';
        }
    }

    private function rate_limited($key, $limit) {
        $limit = min(500, max(1, absint($limit)));
        $ip = sanitize_text_field((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
        $bucket = gmdate('YmdH');
        $hash = hash_hmac('sha256', $ip . '|' . sanitize_key((string) $key) . '|' . $bucket, wp_salt('nonce'));
        $transient = 'mds_be_rate_' . substr($hash, 0, 32);
        $count = absint(get_transient($transient));
        if ($count >= $limit) {
            return true;
        }

        set_transient($transient, $count + 1, HOUR_IN_SECONDS + 60);
        return false;
    }

    private function validation_status(\WP_Error $error) {
        $allowed = [
            'mds_bounty_engine_board_closed',
            'mds_bounty_engine_board_full',
            'mds_bounty_engine_invalid_bounty',
            'mds_bounty_engine_terms_required',
            'mds_bounty_engine_invalid_due_date',
            'mds_bounty_engine_invalid_url',
            'mds_bounty_engine_claims_closed',
            'mds_bounty_engine_bounty_unavailable',
            'mds_bounty_engine_bounty_expired',
            'mds_bounty_engine_invalid_claim',
            'mds_bounty_engine_claim_terms_required',
            'mds_bounty_engine_invalid_work_url',
        ];
        $code = sanitize_key((string) $error->get_error_code());

        return in_array($code, $allowed, true) ? $code : 'failed';
    }

    private function notify_bounty_submission(array $board, array $bounty) {
        $site_name = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        $admin_email = sanitize_email((string) ($board['contact_email'] ?? ''));
        $requester_email = sanitize_email((string) ($bounty['requester_email'] ?? ''));
        $subject = sprintf(__('[%1$s] New bounty awaiting review: %2$s', 'mds-bounty-engine'), $site_name, (string) ($bounty['title'] ?? ''));
        $message = sprintf(
            __("A new bounty was submitted to %1\$s.\n\nTitle: %2\$s\nStatus: Awaiting moderation\n\nReview it: %3\$s", 'mds-bounty-engine'),
            (string) ($board['title'] ?? ''),
            (string) ($bounty['title'] ?? ''),
            $this->admin_url(['board_id' => absint($board['id'] ?? 0)])
        );
        if (is_email($admin_email)) {
            wp_mail($admin_email, $subject, $message);
        }
        if (is_email($requester_email)) {
            wp_mail(
                $requester_email,
                sprintf(__('[%s] Bounty submission received', 'mds-bounty-engine'), $site_name),
                sprintf(__("We received your bounty \"%1\$s\" for moderation on %2\$s. It will not appear publicly until its moderation and payment requirements are complete.", 'mds-bounty-engine'), (string) ($bounty['title'] ?? ''), $site_name)
            );
        }
    }

    private function notify_claim_submission(array $board, array $bounty, array $claim) {
        $site_name = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        $recipients = array_unique(array_filter([
            sanitize_email((string) ($board['contact_email'] ?? '')),
            sanitize_email((string) ($bounty['requester_email'] ?? '')),
        ], 'is_email'));
        foreach ($recipients as $recipient) {
            wp_mail(
                $recipient,
                sprintf(__('[%1$s] New claim for %2$s', 'mds-bounty-engine'), $site_name, (string) ($bounty['title'] ?? '')),
                sprintf(__("A claim is awaiting moderation for \"%1\$s\".\n\nReview it: %2\$s", 'mds-bounty-engine'), (string) ($bounty['title'] ?? ''), $this->admin_url(['board_id' => absint($board['id'] ?? 0)]))
            );
        }
        $claimant_email = sanitize_email((string) ($claim['email'] ?? ''));
        if (is_email($claimant_email)) {
            wp_mail(
                $claimant_email,
                sprintf(__('[%s] Claim submission received', 'mds-bounty-engine'), $site_name),
                sprintf(__("We received your claim for \"%1\$s\" on %2\$s. It is awaiting moderation.", 'mds-bounty-engine'), (string) ($bounty['title'] ?? ''), $site_name)
            );
        }
    }

    private function pagination(array $collection, $query_key, array $context = []) {
        $current = max(1, absint($collection['page'] ?? 1));
        $pages = max(1, absint($collection['pages'] ?? 1));
        if ($pages <= 1) {
            return;
        }
        ?>
        <nav class="mds-bounty-engine-pagination" aria-label="<?php esc_attr_e('Bounty Engine pages', 'mds-bounty-engine'); ?>">
            <?php if ($current > 1) : ?><a class="button" href="<?php echo esc_url($this->admin_url(array_merge($context, [$query_key => $current - 1]))); ?>"><?php esc_html_e('Previous', 'mds-bounty-engine'); ?></a><?php endif; ?>
            <span><?php echo esc_html(sprintf(__('Page %1$d of %2$d', 'mds-bounty-engine'), $current, $pages)); ?></span>
            <?php if ($current < $pages) : ?><a class="button" href="<?php echo esc_url($this->admin_url(array_merge($context, [$query_key => $current + 1]))); ?>"><?php esc_html_e('Next', 'mds-bounty-engine'); ?></a><?php endif; ?>
        </nav>
        <?php
    }

    private function public_pagination(array $collection, $query_key) {
        $current = max(1, absint($collection['page'] ?? 1));
        $pages = max(1, absint($collection['pages'] ?? 1));
        if ($pages <= 1) {
            return;
        }
        $request_url = home_url(wp_unslash($_SERVER['REQUEST_URI'] ?? '/'));
        $base_url = remove_query_arg([$query_key, 'bounty_engine_status'], $request_url);
        ?>
        <nav class="mds-bounty-engine__pagination" aria-label="<?php esc_attr_e('Bounty pages', 'mds-bounty-engine'); ?>">
            <?php if ($current > 1) : ?><a href="<?php echo esc_url(add_query_arg($query_key, $current - 1, $base_url)); ?>"><?php esc_html_e('Previous', 'mds-bounty-engine'); ?></a><?php endif; ?>
            <span><?php echo esc_html(sprintf(__('Page %1$d of %2$d', 'mds-bounty-engine'), $current, $pages)); ?></span>
            <?php if ($current < $pages) : ?><a href="<?php echo esc_url(add_query_arg($query_key, $current + 1, $base_url)); ?>"><?php esc_html_e('Next', 'mds-bounty-engine'); ?></a><?php endif; ?>
        </nav>
        <?php
    }

    private function format_money($amount, $currency) {
        $currency = Repository::effective_currency($currency);
        if (class_exists('\\MillionDollarScript\\Commerce\\Currency')) {
            return \MillionDollarScript\Commerce\Currency::format((float) $amount, $currency);
        }

        return trim(sprintf('%s %s', $currency, number_format_i18n((float) $amount, 2)));
    }

    private function format_date($date) {
        $date = sanitize_text_field((string) $date);
        if (!$date) {
            return '';
        }

        return date_i18n(get_option('date_format'), strtotime($date . ' 00:00:00'));
    }

    private function admin_url(array $args = []) {
        if (class_exists('\\MillionDollarScript\\Extensions\\Support')) {
            return \MillionDollarScript\Extensions\Support::admin_url('mds-bounty-engine', $args);
        }

        return add_query_arg(array_merge(['page' => 'mds-bounty-engine'], $args), admin_url('admin.php'));
    }

    private function redirect(array $args = []) {
        if (class_exists('\\MillionDollarScript\\Extensions\\Support')) {
            \MillionDollarScript\Extensions\Support::redirect_admin('mds-bounty-engine', $args);
        }

        wp_safe_redirect($this->admin_url($args));
        exit;
    }

    private function checkout_redirect($url, $fallback_url = '') {
        if (class_exists('\\MillionDollarScript\\Extensions\\Support') && method_exists('\\MillionDollarScript\\Extensions\\Support', 'external_redirect')) {
            \MillionDollarScript\Extensions\Support::external_redirect($url, $fallback_url ?: home_url('/'));
        }

        $target = esc_url_raw((string) $url, ['http', 'https']);
        $parts = wp_parse_url($target);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!$target || empty($parts['host']) || !in_array($scheme, ['http', 'https'], true)) {
            $this->safe_redirect($fallback_url ?: home_url('/'));
        }

        wp_redirect($target, 302, 'Million Dollar Script - Bounty Engine');
        exit;
    }

    private function safe_redirect($url, array $args = []) {
        if (class_exists('\\MillionDollarScript\\Extensions\\Support')) {
            \MillionDollarScript\Extensions\Support::safe_redirect($url, $args);
        }

        $url = $args ? add_query_arg($args, $url) : $url;
        wp_safe_redirect(esc_url_raw($url));
        exit;
    }

    private function admin_parent_slug() {
        if (class_exists('\\MillionDollarScript\\Extensions\\Support')) {
            return \MillionDollarScript\Extensions\Support::admin_parent_slug();
        }

        global $admin_page_hooks;
        if (isset($admin_page_hooks['mds3'])) {
            return 'mds3';
        }
        if (isset($admin_page_hooks['million-dollar-script'])) {
            return 'million-dollar-script';
        }

        return 'options-general.php';
    }
}
