# WP Image Compress To WebP

WordPress plugin that automatically converts newly uploaded `JPG`, `JPEG`, and `PNG` images into `WebP` format at quality `80`, then removes the original source files.

## Overview

This plugin hooks into the WordPress media upload pipeline and:

- Converts original uploaded images to WebP
- Converts generated intermediate sizes (thumbnails, medium, large, etc.) to WebP
- Deletes the original non-WebP files after successful conversion
- Updates attachment metadata so generated sizes point to WebP files

It also includes a lightweight admin dashboard for local license key management.

## Features

- Automatic conversion of newly uploaded `jpg`, `jpeg`, and `png` files
- WebP quality fixed at `80` (current implementation)
- Original and intermediate files are removed only after successful WebP generation
- Uses `Imagick` when available, with fallback to `GD` (`imagewebp`)
- Admin page under **WebP Compress** for license activation/deactivation
- Dashboard button to convert existing JPEG/PNG media to WebP with progress
- Nonce-protected license form handling and capability checks (`manage_options`)

## Requirements

- WordPress (plugin-based installation)
- PHP with at least one of:
  - `Imagick` extension, or
  - `GD` extension with WebP support (`imagewebp`)

Without these capabilities, image conversion will fail and original uploads will remain unchanged.

## Installation

1. Copy `wp-image-compress-to-webp` into `wp-content/plugins/`.
2. In WordPress admin, go to **Plugins** and activate **WP Image Compress To WebP**.
3. Open **WebP Compress** in the admin menu to review dashboard and license status.
4. Upload images through Media Library as usual.

## Usage

### Automatic conversion flow

After activation, no additional setup is required for conversion:

1. Upload a supported image (`jpg`, `jpeg`, `png`).
2. Plugin attempts to generate a `.webp` version at quality 80.
3. On success, the original uploaded file is deleted.
4. Generated image sizes are also converted to WebP and updated in metadata.

### License dashboard

Go to **WebP Compress** in WP Admin:

- Enter a license key and click **Save & Activate License**
- Click **Deactivate License** to clear stored key and status

> Note: Current license activation is **local format validation only** (no external API validation).

### Convert existing media

Go to **WebP Compress** in WP Admin and click **Convert Old Images to WebP**.

- The dashboard shows conversion progress for old JPEG/PNG attachments.
- Existing items are processed in batches to avoid request timeouts.

## Current behavior and limitations

- New uploads are converted automatically; existing media can be converted manually from the dashboard.
- Only `jpg`, `jpeg`, and `png` are handled.
- Conversion quality defaults to 80 and can be adjusted via the `wicw_webp_quality` filter.
- No remote license server check is implemented yet.

## Project structure

- `wp-image-compress-to-webp.php` — Plugin bootstrap and module loading
- `src/image-converter.php` — Upload and intermediate size conversion logic
- `src/license.php` — License key storage and local validation
- `src/admin.php` — Admin menu, dashboard rendering, and asset loading
- `assets/admin-dashboard.css` — Dashboard styling

## Security notes

- Direct file access is blocked with `ABSPATH` checks.
- License actions require admin capability checks.
- Form submissions are protected using WordPress nonces.
- User-provided license input is sanitized before storage.

## Version

Current plugin version: `1.0.0`
