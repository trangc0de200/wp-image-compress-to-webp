<?php
/**
 * Plugin Name: WP Image Compress To WebP
 * Description: Converts uploaded JPG/JPEG/PNG images to WebP at 80% quality and removes original files.
 * Version: 1.0.0
 * Author: ksnfjsbd-a11y
 */

if (! defined('ABSPATH')) {
    exit;
}
require_once __DIR__ . '/src/image-converter.php';
require_once __DIR__ . '/src/license.php';
require_once __DIR__ . '/src/admin.php';
