<?php

declare( strict_types=1 );

namespace ArtisanPackUI\SecureUploads\Services\Scanners;

use ArtisanPackUI\SecureUploads\Contracts\MalwareScannerInterface;
use ArtisanPackUI\SecureUploads\FileUpload\ScanResult;

/**
 * Null scanner - no-op implementation for when malware scanning is disabled.
 *
 * This scanner always returns a clean result and is used as the default
 * when no other scanner is configured.
 */
class NullScanner implements MalwareScannerInterface
{
    /**
     * Scan a file for malware.
     *
     * Always returns a clean result.
     */
    public function scan( string $filePath ): ScanResult
    {
        return ScanResult::clean( $this->getName() );
    }

    /**
     * Check if the scanner service is available.
     *
     * Always returns true for the null scanner.
     */
    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * Get the scanner name/identifier.
     */
    public function getName(): string
    {
        return 'null';
    }
}
