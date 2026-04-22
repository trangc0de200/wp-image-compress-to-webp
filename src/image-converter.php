<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Convert supported image files to WebP.
 *
 * @param string $source_path Absolute path of the source image.
 * @param int    $quality     WebP quality.
 *
 * @return string|false Path to generated WebP file on success.
 */
function wicw_convert_file_to_webp($source_path, $quality = 80)
{
    if (! is_string($source_path) || ! file_exists($source_path)) {
        return false;
    }

    $extension = strtolower(pathinfo($source_path, PATHINFO_EXTENSION));
    if (! in_array($extension, array('jpg', 'jpeg', 'png'), true)) {
        return false;
    }

    $target_path = preg_replace('/\.(jpe?g|png)$/i', '.webp', $source_path);
    if (! is_string($target_path) || $target_path === $source_path) {
        return false;
    }

    $quality = max(0, min(100, (int) $quality));
    $created = false;

    if (class_exists('Imagick')) {
        try {
            $imagick = new Imagick($source_path);
            $imagick->setImageFormat('webp');
            $imagick->setImageCompressionQuality($quality);
            $created = $imagick->writeImage($target_path);
            $imagick->clear();
            $imagick->destroy();
        } catch (Exception $e) {
            $created = false;
        }
    }

    if (! $created && function_exists('imagewebp')) {
        $image_resource = false;

        if ($extension === 'png' && function_exists('imagecreatefrompng')) {
            $image_resource = imagecreatefrompng($source_path);
            if ($image_resource !== false && function_exists('imagepalettetotruecolor')) {
                imagepalettetotruecolor($image_resource);
            }
            if ($image_resource !== false) {
                imagealphablending($image_resource, false);
                imagesavealpha($image_resource, true);
            }
        } elseif (in_array($extension, array('jpg', 'jpeg'), true) && function_exists('imagecreatefromjpeg')) {
            $image_resource = imagecreatefromjpeg($source_path);
        }

        if ($image_resource !== false) {
            $created = imagewebp($image_resource, $target_path, $quality);
            imagedestroy($image_resource);
        }
    }

    if (! $created || ! file_exists($target_path) || ! is_readable($target_path)) {
        return false;
    }

    return $target_path;
}

/**
 * Convert newly uploaded image files to WebP and delete original.
 *
 * @param array $upload Upload data from wp_handle_upload.
 *
 * @return array
 */
function wicw_convert_uploaded_image_to_webp($upload)
{
    if (
        ! is_array($upload)
        || empty($upload['file'])
        || empty($upload['url'])
        || empty($upload['type'])
    ) {
        return $upload;
    }

    $source_path = $upload['file'];
    $webp_path   = wicw_convert_file_to_webp($source_path, 80);

    if ($webp_path === false) {
        return $upload;
    }

    wp_delete_file($source_path);

    $upload['file'] = $webp_path;
    $upload['type'] = 'image/webp';
    $webp_url = preg_replace('/\.(jpe?g|png)$/i', '.webp', $upload['url']);
    if (is_string($webp_url) && $webp_url !== '') {
        $upload['url'] = $webp_url;
    }

    return $upload;
}
add_filter('wp_handle_upload', 'wicw_convert_uploaded_image_to_webp');

/**
 * Convert generated intermediate image sizes to WebP and delete originals.
 *
 * @param array $metadata Generated attachment metadata.
 *
 * @return array
 */
function wicw_convert_attachment_sizes_to_webp($metadata)
{
    if (
        ! is_array($metadata)
        || empty($metadata['sizes'])
        || empty($metadata['file'])
        || ! is_string($metadata['file'])
    ) {
        return $metadata;
    }

    $upload_dir = wp_upload_dir();
    if (empty($upload_dir['basedir'])) {
        return $metadata;
    }

    $relative_dir = (dirname($metadata['file']) === '.') ? '' : dirname($metadata['file']);
    $base_dir     = trailingslashit($upload_dir['basedir']) . ($relative_dir !== '' ? trailingslashit($relative_dir) : '');

    foreach ($metadata['sizes'] as $size_key => $size_data) {
        if (empty($size_data['file']) || ! is_string($size_data['file'])) {
            continue;
        }

        $source_path = $base_dir . $size_data['file'];
        $webp_path   = wicw_convert_file_to_webp($source_path, 80);

        if ($webp_path === false) {
            continue;
        }

        if (! is_readable($webp_path)) {
            continue;
        }

        wp_delete_file($source_path);
        $metadata['sizes'][$size_key]['file']      = basename($webp_path);
        $metadata['sizes'][$size_key]['mime-type'] = 'image/webp';
    }

    return $metadata;
}
add_filter('wp_generate_attachment_metadata', 'wicw_convert_attachment_sizes_to_webp');

/**
 * Get attachment IDs that are candidates for existing-image conversion.
 *
 * @param int $offset Offset in the result set.
 * @param int $limit  Maximum number of IDs to return. Set to 0 for all.
 *
 * @return int[]
 */
function wicw_get_convertible_attachment_ids($offset = 0, $limit = 0)
{
    global $wpdb;

    $offset = max(0, (int) $offset);
    $limit  = max(0, (int) $limit);

    $sql = "SELECT ID
        FROM {$wpdb->posts}
        WHERE post_type = 'attachment'
            AND post_status <> 'trash'
            AND post_mime_type IN ('image/jpeg', 'image/png')
        ORDER BY ID ASC";

    if ($limit > 0) {
        $sql = $wpdb->prepare($sql . ' LIMIT %d OFFSET %d', $limit, $offset);
    }

    $ids = $wpdb->get_col($sql);
    if (! is_array($ids)) {
        return array();
    }

    return array_map('intval', $ids);
}

/**
 * Count attachments that can be converted from JPEG/PNG to WebP.
 *
 * @return int
 */
function wicw_count_convertible_attachments()
{
    global $wpdb;

    $sql = "SELECT COUNT(ID)
        FROM {$wpdb->posts}
        WHERE post_type = 'attachment'
            AND post_status <> 'trash'
            AND post_mime_type IN ('image/jpeg', 'image/png')";

    return (int) $wpdb->get_var($sql);
}

/**
 * Convert one existing attachment (original + generated sizes) to WebP.
 *
 * @param int $attachment_id Attachment post ID.
 *
 * @return array{converted:bool,failed:bool}
 */
function wicw_convert_existing_attachment_to_webp($attachment_id)
{
    $attachment_id = (int) $attachment_id;
    if ($attachment_id <= 0) {
        return array('converted' => false, 'failed' => true);
    }

    $source_path = get_attached_file($attachment_id);
    if (! is_string($source_path) || $source_path === '' || ! file_exists($source_path)) {
        return array('converted' => false, 'failed' => true);
    }

    $metadata = wp_get_attachment_metadata($attachment_id);
    if (! is_array($metadata)) {
        $metadata = array();
    }

    $converted_any = false;
    $original_webp = wicw_convert_file_to_webp($source_path, 80);

    if (is_string($original_webp) && $original_webp !== '') {
        wp_delete_file($source_path);
        update_attached_file($attachment_id, $original_webp);
        $converted_any = true;

        if (isset($metadata['file']) && is_string($metadata['file']) && $metadata['file'] !== '') {
            $metadata['file'] = (string) preg_replace('/\.(jpe?g|png)$/i', '.webp', $metadata['file']);
        }
    }

    if (
        isset($metadata['sizes'])
        && is_array($metadata['sizes'])
        && isset($metadata['file'])
        && is_string($metadata['file'])
        && $metadata['file'] !== ''
    ) {
        $upload_dir = wp_upload_dir();
        if (! empty($upload_dir['basedir']) && is_string($upload_dir['basedir'])) {
            $relative_dir = dirname($metadata['file']) === '.' ? '' : dirname($metadata['file']);
            $base_dir     = trailingslashit($upload_dir['basedir']) . ($relative_dir !== '' ? trailingslashit($relative_dir) : '');

            foreach ($metadata['sizes'] as $size_key => $size_data) {
                if (
                    empty($size_data['file'])
                    || ! is_string($size_data['file'])
                    || ! preg_match('/\.(jpe?g|png)$/i', $size_data['file'])
                ) {
                    continue;
                }

                $size_source_path = $base_dir . $size_data['file'];
                $size_webp_path   = wicw_convert_file_to_webp($size_source_path, 80);
                if (! is_string($size_webp_path) || $size_webp_path === '') {
                    continue;
                }

                wp_delete_file($size_source_path);
                $metadata['sizes'][$size_key]['file']      = basename($size_webp_path);
                $metadata['sizes'][$size_key]['mime-type'] = 'image/webp';
                $converted_any = true;
            }
        }
    }

    if (! $converted_any) {
        return array('converted' => false, 'failed' => true);
    }

    wp_update_post(
        array(
            'ID'             => $attachment_id,
            'post_mime_type' => 'image/webp',
        )
    );

    if (! empty($metadata)) {
        wp_update_attachment_metadata($attachment_id, $metadata);
    }

    return array('converted' => true, 'failed' => false);
}
