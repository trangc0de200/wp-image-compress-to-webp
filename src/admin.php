<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Collect server configuration data relevant to this plugin.
 *
 * @return array<int, array{label: string, value: string, status: string}> Rows with label, value, and status ('ok'|'warn'|'').
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
 * Render plugin dashboard page.
 *
 * @return void
 */
function wicw_render_dashboard_page()
{
    if (! current_user_can('manage_options')) {
        return;
    }

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
                            <?php if ($row['status'] !== '') : ?>
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
    </div>
    <?php
}
