<?php

/**
 * Main SecureUploads class.
 *
 * Resolved from the container as `secure-uploads` and via the
 * {@see secure_uploads()} helper. Most public functionality is exposed via the
 * `FileValidationService`, `SecureFileStorageService`, and pluggable malware
 * scanner contracts within this package.
 *
 * @package    ArtisanPack_UI
 * @subpackage SecureUploads
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\SecureUploads;

class SecureUploads
{
    public function version(): string
    {
        return '0.1.0';
    }
}
