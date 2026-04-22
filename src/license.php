<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Get plugin license key option name.
 *
 * @return string
 */
function wicw_license_key_option_name()
{
    return 'wicw_license_key';
}

/**
 * Get plugin license status option name.
 *
 * @return string
 */
function wicw_license_status_option_name()
{
    return 'wicw_license_status';
}

/**
 * Get accepted license key pattern.
 *
 * @return string
 */
function wicw_license_key_pattern()
{
    return '/^[A-Z0-9-]{10,}$/i';
}

/**
 * Get short local validation note.
 *
 * @return string
 */
function wicw_license_local_validation_short_note()
{
    return 'local format check only';
}

/**
 * Get dashboard text for local-only validation.
 *
 * @return string
 */
function wicw_license_local_validation_description()
{
    return 'License activation currently performs local key format validation only.';
}

/**
 * Clear persisted license state.
 *
 * @return void
 */
function wicw_clear_license()
{
    delete_option(wicw_license_key_option_name());
    delete_option(wicw_license_status_option_name());
}

/**
 * Handle license form submission.
 *
 * @return array{message:string,type:string} Message payload for admin UI.
 */
function wicw_handle_license_form_submission()
{
    if (! current_user_can('manage_options')) {
        return array('message' => '', 'type' => '');
    }

    if ((string) filter_input(INPUT_SERVER, 'REQUEST_METHOD') !== 'POST') {
        return array('message' => '', 'type' => '');
    }

    if (empty($_POST)) {
        return array('message' => '', 'type' => '');
    }

    if (! isset($_POST['wicw_license_nonce'])) {
        return array('message' => '', 'type' => '');
    }

    check_admin_referer('wicw_save_license', 'wicw_license_nonce');

    if (isset($_POST['wicw_deactivate_license'])) {
        wicw_clear_license();
        return array('message' => 'License deactivated.', 'type' => 'success');
    }

    if (! isset($_POST['wicw_activate_license'])) {
        return array('message' => '', 'type' => '');
    }

    $license_key = isset($_POST['wicw_license_key'])
        ? sanitize_text_field(wp_unslash($_POST['wicw_license_key']))
        : '';

    if ($license_key === '') {
        wicw_clear_license();
        return array('message' => 'Please enter a license key.', 'type' => 'error');
    }

    if (! preg_match(wicw_license_key_pattern(), $license_key)) {
        wicw_clear_license();
        return array('message' => 'Invalid license key format. Use at least 10 alphanumeric characters or hyphens.', 'type' => 'error');
    }

    update_option(wicw_license_key_option_name(), $license_key);
    update_option(wicw_license_status_option_name(), 'active');
    return array(
        'message' => sprintf('License activated (%s).', wicw_license_local_validation_short_note()),
        'type'    => 'success',
    );
}
