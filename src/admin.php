<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Collect server configuration data relevant to this plugin.
 *
 * @return list<array{label: string, value: string, status: string}> Rows with label, value, and status ('ok'|'warn'|'').
 */
function wicw_get_server_info()
{
    $imagick_available = class_exists('Imagick');
    $gd_webp_available = function_exists('imagewebp');

    if ($imagick_available) {
        $conversion_value  = __('Imagick (preferred)', 'wp-image-compress-to-webp');
        $conversion_status = 'ok';
    } elseif ($gd_webp_available) {
        $conversion_value  = __('GD (fallback)', 'wp-image-compress-to-webp');
        $conversion_status = 'ok';
    } else {
        $conversion_value  = __('Not available — conversion will fail', 'wp-image-compress-to-webp');
        $conversion_status = 'warn';
    }

    return array(
        array(
            'label'  => __('PHP Version', 'wp-image-compress-to-webp'),
            'value'  => phpversion(),
            'status' => '',
        ),
        array(
            'label'  => __('WordPress Version', 'wp-image-compress-to-webp'),
            'value'  => get_bloginfo('version'),
            'status' => '',
        ),
        array(
            'label'  => __('Imagick Extension', 'wp-image-compress-to-webp'),
            'value'  => $imagick_available ? __('Available', 'wp-image-compress-to-webp') : __('Not available', 'wp-image-compress-to-webp'),
            'status' => $imagick_available ? 'ok' : '',
        ),
        array(
            'label'  => __('GD WebP Support', 'wp-image-compress-to-webp'),
            'value'  => $gd_webp_available ? __('Available', 'wp-image-compress-to-webp') : __('Not available', 'wp-image-compress-to-webp'),
            'status' => $gd_webp_available ? 'ok' : '',
        ),
        array(
            'label'  => __('Conversion Library', 'wp-image-compress-to-webp'),
            'value'  => $conversion_value,
            'status' => $conversion_status,
        ),
    );
}

/**
 * Add plugin dashboard page in admin menu.
 *
 * @return void
 */
function wicw_register_admin_menu()
{
    add_menu_page(
        'WP Image Compress To WebP',
        'WebP Compress',
        'manage_options',
        'wicw-dashboard',
        'wicw_render_dashboard_page',
        'dashicons-format-image'
    );
}
add_action('admin_menu', 'wicw_register_admin_menu');

/**
 * Enqueue dashboard assets for plugin admin page.
 *
 * @param string $hook_suffix Current admin page hook.
 *
 * @return void
 */
function wicw_enqueue_admin_assets($hook_suffix)
{
    if ($hook_suffix !== 'toplevel_page_wicw-dashboard') {
        return;
    }

    wp_enqueue_style(
        'wicw-admin-dashboard',
        plugin_dir_url(__DIR__) . 'assets/admin-dashboard.css',
        array(),
        '1.1.0'
    );
}
add_action('admin_enqueue_scripts', 'wicw_enqueue_admin_assets');

/**
 * Start a bulk conversion session for existing attachments.
 *
 * @return void
 */
function wicw_handle_conversion_start_submission()
{
    if (! current_user_can('manage_options')) {
        return;
    }

    if ((string) filter_input(INPUT_SERVER, 'REQUEST_METHOD') !== 'POST') {
        return;
    }

    if (! isset($_POST['wicw_start_conversion'])) {
        return;
    }

    check_admin_referer('wicw_start_conversion', 'wicw_start_conversion_nonce');

    $attachment_ids = wicw_get_convertible_attachment_ids();
    $session_id     = wp_generate_uuid4();
    set_transient('wicw_conversion_queue_' . $session_id, $attachment_ids, HOUR_IN_SECONDS);

    $redirect_url = add_query_arg(
        array(
            'page'           => 'wicw-dashboard',
            'wicw_convert'   => '1',
            'wicw_session'   => $session_id,
            'wicw_offset'    => 0,
            'wicw_total'     => count($attachment_ids),
            'wicw_converted' => 0,
            'wicw_failed'    => 0,
            'wicw_nonce'     => wp_create_nonce('wicw_bulk_convert'),
        ),
        admin_url('admin.php')
    );

    wp_safe_redirect($redirect_url);
    exit;
}

/**
 * Process one conversion batch and return progress details.
 *
 * @return array<string,mixed>|null
 */
function wicw_get_conversion_progress_state()
{
    if (! current_user_can('manage_options')) {
        return null;
    }

    if (! isset($_GET['wicw_convert']) || (string) $_GET['wicw_convert'] !== '1') {
        return null;
    }

    $nonce = isset($_GET['wicw_nonce']) ? sanitize_text_field(wp_unslash($_GET['wicw_nonce'])) : '';
    if (! wp_verify_nonce($nonce, 'wicw_bulk_convert')) {
        return array(
            'is_error' => true,
            'message'  => __('Invalid conversion request. Please start conversion again.', 'wp-image-compress-to-webp'),
        );
    }

    $session_id = isset($_GET['wicw_session']) ? sanitize_key(wp_unslash($_GET['wicw_session'])) : '';
    if ($session_id === '') {
        return array(
            'is_error' => true,
            'message'  => __('Conversion session not found. Please start again.', 'wp-image-compress-to-webp'),
        );
    }

    $queue = get_transient('wicw_conversion_queue_' . $session_id);
    if (! is_array($queue)) {
        return array(
            'is_error' => true,
            'message'  => __('Conversion session expired. Please start again.', 'wp-image-compress-to-webp'),
        );
    }

    $offset    = isset($_GET['wicw_offset']) ? max(0, absint($_GET['wicw_offset'])) : 0;
    $total     = isset($_GET['wicw_total']) ? max(0, absint($_GET['wicw_total'])) : count($queue);
    $converted = isset($_GET['wicw_converted']) ? max(0, absint($_GET['wicw_converted'])) : 0;
    $failed    = isset($_GET['wicw_failed']) ? max(0, absint($_GET['wicw_failed'])) : 0;
    $batch_size = 20;

    $batch_ids = array_slice($queue, $offset, $batch_size);
    foreach ($batch_ids as $attachment_id) {
        $result = wicw_convert_existing_attachment_to_webp((int) $attachment_id);
        if (! empty($result['converted'])) {
            $converted++;
            continue;
        }

        $failed++;
    }

    $processed = min($total, $offset + count($batch_ids));
    $running   = ($processed < $total);
    $percent   = ($total > 0) ? (int) floor(($processed / $total) * 100) : 100;
    $percent   = max(0, min(100, $percent));

    $next_url = '';
    if ($running) {
        $next_url = add_query_arg(
            array(
                'page'           => 'wicw-dashboard',
                'wicw_convert'   => '1',
                'wicw_session'   => $session_id,
                'wicw_offset'    => $processed,
                'wicw_total'     => $total,
                'wicw_converted' => $converted,
                'wicw_failed'    => $failed,
                'wicw_nonce'     => $nonce,
            ),
            admin_url('admin.php')
        );
    } else {
        delete_transient('wicw_conversion_queue_' . $session_id);
    }

    return array(
        'is_error'   => false,
        'running'    => $running,
        'processed'  => $processed,
        'total'      => $total,
        'converted'  => $converted,
        'failed'     => $failed,
        'percent'    => $percent,
        'next_url'   => $next_url,
    );
}

/**
 * Render plugin dashboard page.
 *
 * @return void
 */
function wicw_render_dashboard_page()
{
    if (! current_user_can('manage_options')) {
        return;
    }

    wicw_handle_conversion_start_submission();
    $conversion_state = wicw_get_conversion_progress_state();
    $notice         = wicw_handle_license_form_submission();
    $message        = isset($notice['message']) ? (string) $notice['message'] : '';
    $notice_type    = '';
    if ($message !== '') {
        $notice_type = isset($notice['type']) && $notice['type'] === 'error' ? 'notice-error' : 'notice-success';
    }
    $license_key    = (string) get_option(wicw_license_key_option_name(), '');
    if ($license_key !== '' && ! preg_match(wicw_license_key_pattern(), $license_key)) {
        wicw_clear_license();
        $license_key = '';
    }

    $license_status = (string) get_option(wicw_license_status_option_name(), 'inactive');
    if (! in_array($license_status, array('active', 'inactive'), true)) {
        $license_status = 'inactive';
    }
    $is_active      = ($license_status === 'active');
    $convertible_count = wicw_count_convertible_attachments();
    ?>
    <div class="wrap wicw-dashboard">
        <h1 class="wicw-dashboard__title"><?php echo esc_html__('WP Image Compress To WebP Dashboard', 'wp-image-compress-to-webp'); ?></h1>
        <p class="wicw-dashboard__subtitle"><?php echo esc_html__('This plugin automatically converts uploaded JPG/JPEG/PNG images to WebP.', 'wp-image-compress-to-webp'); ?></p>
        <p class="wicw-dashboard__meta"><?php echo esc_html(wicw_license_local_validation_description()); ?></p>

        <?php if ($message !== '') : ?>
            <div class="notice <?php echo esc_attr($notice_type); ?> is-dismissible wicw-dashboard__notice">
                <p><?php echo esc_html($message); ?></p>
            </div>
        <?php endif; ?>

        <div class="wicw-dashboard__card">
            <h2 class="wicw-dashboard__section-title"><?php echo esc_html__('Server Configuration', 'wp-image-compress-to-webp'); ?></h2>
            <dl class="wicw-dashboard__info-table">
                <?php foreach (wicw_get_server_info() as $row) : ?>
                    <div class="wicw-dashboard__info-row">
                        <dt class="wicw-dashboard__info-label"><?php echo esc_html($row['label']); ?></dt>
                        <dd class="wicw-dashboard__info-value">
                            <?php if (in_array($row['status'], array('ok', 'warn'), true)) : ?>
                                <span class="wicw-dashboard__info-status wicw-dashboard__info-status--<?php echo esc_attr($row['status']); ?>"></span>
                            <?php endif; ?>
                            <?php echo esc_html($row['value']); ?>
                        </dd>
                    </div>
                <?php endforeach; ?>
            </dl>
        </div>

        <div class="wicw-dashboard__card">
            <h2 class="wicw-dashboard__section-title"><?php echo esc_html__('License', 'wp-image-compress-to-webp'); ?></h2>
            <p class="wicw-dashboard__status">
                <strong><?php echo esc_html__('Status:', 'wp-image-compress-to-webp'); ?></strong>
                <span class="wicw-dashboard__badge <?php echo $is_active ? 'is-active' : 'is-inactive'; ?>">
                    <?php echo $is_active ? esc_html__('Active', 'wp-image-compress-to-webp') : esc_html__('Inactive', 'wp-image-compress-to-webp'); ?>
                </span>
            </p>

            <form method="post">
                <?php wp_nonce_field('wicw_save_license', 'wicw_license_nonce'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="wicw_license_key"><?php echo esc_html__('License Key', 'wp-image-compress-to-webp'); ?></label>
                        </th>
                        <td>
                            <input
                                name="wicw_license_key"
                                type="text"
                                id="wicw_license_key"
                                value="<?php echo esc_attr($license_key); ?>"
                                class="regular-text wicw-dashboard__input"
                            />
                        </td>
                    </tr>
                </table>

                <p class="submit wicw-dashboard__actions">
                    <button type="submit" name="wicw_activate_license" class="button button-primary">
                        <?php echo esc_html__('Save & Activate License', 'wp-image-compress-to-webp'); ?>
                    </button>
                    <button type="submit" name="wicw_deactivate_license" class="button">
                        <?php echo esc_html__('Deactivate License', 'wp-image-compress-to-webp'); ?>
                    </button>
                </p>
            </form>
        </div>

        <div class="wicw-dashboard__card">
            <h2 class="wicw-dashboard__section-title"><?php echo esc_html__('Convert Existing Images', 'wp-image-compress-to-webp'); ?></h2>
            <p class="wicw-dashboard__meta">
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: %d: Number of existing JPEG/PNG media items */
                        __('Found %d existing JPEG/PNG media item(s).', 'wp-image-compress-to-webp'),
                        (int) $convertible_count
                    )
                );
                ?>
            </p>

            <?php if (is_array($conversion_state)) : ?>
                <?php if (! empty($conversion_state['is_error'])) : ?>
                    <div class="notice notice-error inline wicw-dashboard__notice">
                        <p><?php echo esc_html((string) $conversion_state['message']); ?></p>
                    </div>
                <?php else : ?>
                    <div class="wicw-dashboard__progress">
                        <p class="wicw-dashboard__status">
                            <strong><?php echo esc_html__('Progress:', 'wp-image-compress-to-webp'); ?></strong>
                            <span>
                                <?php
                                echo esc_html(
                                    sprintf(
                                        /* translators: 1: processed count, 2: total count */
                                        __('%1$d / %2$d processed', 'wp-image-compress-to-webp'),
                                        (int) $conversion_state['processed'],
                                        (int) $conversion_state['total']
                                    )
                                );
                                ?>
                            </span>
                        </p>
                        <div class="wicw-dashboard__progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr((string) $conversion_state['percent']); ?>">
                            <span class="wicw-dashboard__progress-value" style="width: <?php echo esc_attr((string) $conversion_state['percent']); ?>%;"></span>
                        </div>
                        <p class="wicw-dashboard__meta">
                            <?php
                            echo esc_html(
                                sprintf(
                                    /* translators: 1: successful count, 2: failed count */
                                    __('Converted: %1$d · Failed: %2$d', 'wp-image-compress-to-webp'),
                                    (int) $conversion_state['converted'],
                                    (int) $conversion_state['failed']
                                )
                            );
                            ?>
                        </p>

                        <?php if (! empty($conversion_state['running']) && ! empty($conversion_state['next_url'])) : ?>
                            <p class="wicw-dashboard__meta"><?php echo esc_html__('Continuing conversion...', 'wp-image-compress-to-webp'); ?></p>
                            <script>
                                window.setTimeout(function () {
                                    window.location.href = <?php echo wp_json_encode((string) $conversion_state['next_url']); ?>;
                                }, 700);
                            </script>
                        <?php else : ?>
                            <p class="wicw-dashboard__meta"><?php echo esc_html__('Conversion finished.', 'wp-image-compress-to-webp'); ?></p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field('wicw_start_conversion', 'wicw_start_conversion_nonce'); ?>
                <p class="submit wicw-dashboard__actions">
                    <button type="submit" name="wicw_start_conversion" class="button button-primary">
                        <?php echo esc_html__('Convert Old Images to WebP', 'wp-image-compress-to-webp'); ?>
                    </button>
                </p>
            </form>
        </div>
    </div>
    <?php
}
