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
