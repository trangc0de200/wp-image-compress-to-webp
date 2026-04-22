# wp-image-compress-to-webp

WordPress plugin to automatically convert uploaded `png`, `jpg`, and `jpeg` images to `webp` with quality `80` and delete original files.

## What it does

- Converts newly uploaded PNG/JPG/JPEG files to WebP (quality 80)
- Deletes the original uploaded file after successful conversion
- Converts generated intermediate image sizes to WebP and removes original generated files
- Adds an admin dashboard page for plugin status and license management
- Supports saving and activating/deactivating a license key from the dashboard
- License activation currently validates key format locally (no external license server check)

## Installation

1. Copy the `wp-image-compress-to-webp` plugin folder (including `src/`) to your WordPress plugins directory.
2. Activate **WP Image Compress To WebP** from the WordPress admin Plugins page.
3. Open **WebP Compress** in WP Admin menu to manage your license status.
4. Upload images as usual in Media Library.

## Source architecture

- `wp-image-compress-to-webp.php`: plugin bootstrap and module loading
- `src/image-converter.php`: upload/intermediate image conversion flow
- `src/license.php`: license key state and validation logic
- `src/admin.php`: admin menu registration and dashboard rendering
