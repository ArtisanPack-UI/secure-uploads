<?php

declare( strict_types=1 );

namespace ArtisanPackUI\SecureUploads\Services;

use ArtisanPackUI\SecureUploads\Contracts\FileValidatorInterface;
use ArtisanPackUI\SecureUploads\FileUpload\ValidationResult;
use finfo;
use Illuminate\Http\UploadedFile;

class FileValidationService implements FileValidatorInterface
{
    /**
     * Allowed MIME types.
     */
    protected array $allowedMimeTypes;

    /**
     * Allowed extensions.
     */
    protected array $allowedExtensions;

    /**
     * Blocked extensions (always rejected).
     */
    protected array $blockedExtensions;

    /**
     * Blocked MIME types (always rejected).
     */
    protected array $blockedMimeTypes;

    /**
     * Maximum file size in bytes.
     */
    protected int $maxFileSize;

    /**
     * Maximum file size per MIME type pattern.
     */
    protected array $maxFileSizePerType;

    /**
     * Create a new file validation service instance.
     */
    public function __construct()
    {
        $config = config( 'artisanpack.secure-uploads', [] );

        // Use security config, fallback to media-library config if available
        $this->allowedMimeTypes = $config['allowedMimeTypes']
            ?? config( 'artisanpack.media.allowed_mime_types', [] );

        $this->allowedExtensions = $config['allowedExtensions'] ?? [];
        $this->blockedExtensions = $config['blockedExtensions'] ?? [];
        $this->blockedMimeTypes  = $config['blockedMimeTypes'] ?? [];

        // Max file size: security config (bytes) or media config (KB) converted to bytes
        $this->maxFileSize = $config['maxFileSize']
            ?? ( config( 'artisanpack.media.max_file_size', 10240 ) * 1024 );

        $this->maxFileSizePerType = $config['maxFileSizePerType'] ?? [];
    }

    /**
     * Validate an uploaded file against security rules.
     */
    public function validate( UploadedFile $file, array $options = [] ): ValidationResult
    {
        $errors = [];

        // Merge options with defaults
        $allowedMimeTypes = $options['allowedMimeTypes'] ?? $this->allowedMimeTypes;
        $maxFileSize      = $options['maxFileSize'] ?? $this->maxFileSize;

        $filename  = $file->getClientOriginalName();
        $extension = strtolower( $file->getClientOriginalExtension() );

        // Check for unsafe filename patterns
        if ( $this->hasUnsafeFilename( $filename ) ) {
            $errors[] = 'The filename contains unsafe characters or patterns.';
        }

        // Check blocked extensions (always rejected, regardless of allowlist)
        if ( $this->isExtensionBlocked( $extension ) ) {
            $errors[] = "The file extension '{$extension}' is not allowed for security reasons.";
        }

        // Check if extension is in blocklist even if hidden (double extension check)
        if ( config( 'artisanpack.secure-uploads.checkForDoubleExtensions', true ) ) {
            $doubleExtension = $this->detectDoubleExtension( $filename );
            if ( null !== $doubleExtension && $this->isExtensionBlocked( $doubleExtension ) ) {
                $errors[] = "The file contains a blocked extension '{$doubleExtension}' hidden in the filename.";
            }
        }

        // Detect actual MIME type from content
        $detectedMimeType = $this->detectMimeType( $file );

        // Check blocked MIME types
        if ( $this->isMimeTypeBlocked( $detectedMimeType ) ) {
            $errors[] = "The detected file type '{$detectedMimeType}' is not allowed for security reasons.";
        }

        // Check if MIME type is allowed (if allowlist is specified)
        if ( ! empty( $allowedMimeTypes ) && ! $this->isMimeTypeInList( $detectedMimeType, $allowedMimeTypes ) ) {
            $errors[] = 'The file type is not allowed. Allowed types: ' . implode( ', ', $allowedMimeTypes );
        }

        // Check if extension is allowed (if allowlist is specified)
        if ( ! empty( $this->allowedExtensions ) && ! $this->isExtensionAllowed( $extension ) ) {
            $errors[] = 'The file extension is not allowed. Allowed extensions: ' . implode( ', ', $this->allowedExtensions );
        }

        // Validate MIME type matches extension (if configured)
        if ( config( 'artisanpack.secure-uploads.validateMimeByContent', true ) ) {
            $claimedMimeType = $file->getMimeType();
            if ( $detectedMimeType !== $claimedMimeType && ! $this->mimeTypesAreEquivalent( $detectedMimeType, $claimedMimeType ) ) {
                $errors[] = "The file content does not match the declared type. Detected: {$detectedMimeType}, Claimed: {$claimedMimeType}";
            }
        }

        // Check file size
        $sizeLimit = $this->getFileSizeLimit( $detectedMimeType, $maxFileSize );
        if ( $file->getSize() > $sizeLimit ) {
            $humanSize = $this->formatBytes( $sizeLimit );
            $errors[]  = "The file size exceeds the maximum allowed size of {$humanSize}.";
        }

        if ( ! empty( $errors ) ) {
            return ValidationResult::failure( $errors, $detectedMimeType );
        }

        $sanitizedFilename = $this->sanitizeFilename( $filename );

        return ValidationResult::success( $detectedMimeType, $sanitizedFilename );
    }

    /**
     * Check if a file extension is allowed.
     */
    public function isExtensionAllowed( string $extension ): bool
    {
        $extension = strtolower( $extension );

        // First check if it's blocked
        if ( $this->isExtensionBlocked( $extension ) ) {
            return false;
        }

        // If no allowlist, allow all (except blocked)
        if ( empty( $this->allowedExtensions ) ) {
            return true;
        }

        return in_array( $extension, $this->allowedExtensions, true );
    }

    /**
     * Check if a file extension is blocked.
     */
    public function isExtensionBlocked( string $extension ): bool
    {
        return in_array( strtolower( $extension ), $this->blockedExtensions, true );
    }

    /**
     * Check if a MIME type is allowed.
     */
    public function isMimeTypeAllowed( string $mimeType ): bool
    {
        // First check if it's blocked
        if ( $this->isMimeTypeBlocked( $mimeType ) ) {
            return false;
        }

        // If no allowlist, allow all (except blocked)
        if ( empty( $this->allowedMimeTypes ) ) {
            return true;
        }

        return $this->isMimeTypeInList( $mimeType, $this->allowedMimeTypes );
    }

    /**
     * Check if a MIME type is blocked.
     * Supports wildcards like application/* or video/*.
     */
    public function isMimeTypeBlocked( string $mimeType ): bool
    {
        return $this->isMimeTypeInList( $mimeType, $this->blockedMimeTypes );
    }

    /**
     * Detect the actual MIME type from file content.
     */
    public function detectMimeType( UploadedFile $file ): string
    {
        $finfo    = new finfo( FILEINFO_MIME_TYPE );
        $detected = $finfo->file( $file->getPathname() );

        return $detected ?: 'application/octet-stream';
    }

    /**
     * Check if a filename contains dangerous patterns.
     */
    public function hasUnsafeFilename( string $filename ): bool
    {
        // Check for null bytes
        if ( config( 'artisanpack.secure-uploads.checkForNullBytes', true ) ) {
            if ( str_contains( $filename, "\0" ) || str_contains( $filename, '%00' ) ) {
                return true;
            }
        }

        // Check for path traversal attempts
        if ( preg_match( '/\.\.[\/\\\\]/', $filename ) ) {
            return true;
        }

        // Check for suspicious characters
        if ( preg_match( '/[<>:"|?*]/', $filename ) ) {
            return true;
        }

        // Check for hidden files (Unix-style)
        if ( str_starts_with( $filename, '.' ) ) {
            return true;
        }

        // Check for reserved Windows names
        $reservedNames  = ['CON', 'PRN', 'AUX', 'NUL', 'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9', 'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9'];
        $nameWithoutExt = strtoupper( pathinfo( $filename, PATHINFO_FILENAME ) );
        if ( in_array( $nameWithoutExt, $reservedNames, true ) ) {
            return true;
        }

        return false;
    }

    /**
     * Sanitize a filename for safe storage.
     */
    public function sanitizeFilename( string $filename ): string
    {
        // Remove null bytes
        $filename = str_replace( ["\0", '%00'], '', $filename );

        // Remove path traversal attempts
        $filename = str_replace( ['../', '..\\'], '', $filename );

        // Remove dangerous characters
        $filename = preg_replace( '/[<>:"|?*]/', '', $filename );

        // Replace spaces with dashes
        $filename = preg_replace( '/\s+/', '-', $filename );

        // Remove multiple consecutive dashes
        $filename = preg_replace( '/-+/', '-', $filename );

        // Remove leading/trailing dots and dashes
        $filename = trim( $filename, '.-' );

        // Ensure the filename is not empty
        if ( empty( $filename ) ) {
            $filename = 'file';
        }

        return $filename;
    }

    /**
     * Strip EXIF data from an image file.
     */
    public function stripExifData( string $filePath ): bool
    {
        if ( ! file_exists( $filePath ) ) {
            return false;
        }

        $mimeType = mime_content_type( $filePath );

        // Only process JPEG images (EXIF is primarily in JPEG)
        if ( 'image/jpeg' !== $mimeType ) {
            return true; // Not an error, just not applicable
        }

        // Check if GD library is available
        if ( ! function_exists( 'imagecreatefromjpeg' ) ) {
            return false;
        }

        $image = @imagecreatefromjpeg( $filePath );
        if ( false === $image ) {
            return false;
        }

        // Re-save the image without EXIF data
        $result = imagejpeg( $image, $filePath, 100 );
        imagedestroy( $image );

        return $result;
    }

    /**
     * Set allowed MIME types at runtime.
     */
    public function setAllowedMimeTypes( array $mimeTypes ): self
    {
        $this->allowedMimeTypes = $mimeTypes;

        return $this;
    }

    /**
     * Set allowed extensions at runtime.
     */
    public function setAllowedExtensions( array $extensions ): self
    {
        $this->allowedExtensions = array_map( 'strtolower', $extensions );

        return $this;
    }

    /**
     * Set maximum file size at runtime.
     */
    public function setMaxFileSize( int $bytes ): self
    {
        $this->maxFileSize = $bytes;

        return $this;
    }

    /**
     * Apply media-library defaults if available.
     */
    public function withMediaLibraryDefaults(): self
    {
        if ( $mediaConfig = config( 'artisanpack.media' ) ) {
            if ( isset( $mediaConfig['allowed_mime_types'] ) ) {
                $this->allowedMimeTypes = array_unique(
                    array_merge( $this->allowedMimeTypes, $mediaConfig['allowed_mime_types'] ),
                );
            }

            if ( isset( $mediaConfig['max_file_size'] ) ) {
                // Media library uses KB, convert to bytes
                $this->maxFileSize = max( $this->maxFileSize, $mediaConfig['max_file_size'] * 1024 );
            }
        }

        return $this;
    }

    /**
     * Check if a MIME type is in a list (supports wildcards like image/*).
     */
    protected function isMimeTypeInList( string $mimeType, array $list ): bool
    {
        foreach ( $list as $allowed ) {
            // Exact match
            if ( $allowed === $mimeType ) {
                return true;
            }

            // Wildcard match (e.g., image/*)
            if ( str_ends_with( $allowed, '/*' ) ) {
                $prefix = substr( $allowed, 0, -1 );
                if ( str_starts_with( $mimeType, $prefix ) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Detect double extension attacks (e.g., file.php.jpg).
     */
    protected function detectDoubleExtension( string $filename ): ?string
    {
        // Remove the final extension
        $withoutLastExt = pathinfo( $filename, PATHINFO_FILENAME );

        // Check if there's another extension
        if ( str_contains( $withoutLastExt, '.' ) ) {
            $hiddenExt = strtolower( pathinfo( $withoutLastExt, PATHINFO_EXTENSION ) );

            return $hiddenExt ?: null;
        }

        return null;
    }

    /**
     * Check if two MIME types are equivalent (handles browser inconsistencies).
     */
    protected function mimeTypesAreEquivalent( string $detected, string $claimed ): bool
    {
        $equivalents = [
            'image/jpeg'      => ['image/pjpeg'],
            'image/png'       => ['image/x-png'],
            'audio/mpeg'      => ['audio/mp3'],
            'video/quicktime' => ['video/mp4'],
            'text/plain'      => ['text/x-csv', 'application/csv'],
            'text/csv'        => ['text/x-csv', 'application/csv'],
        ];

        if ( isset( $equivalents[ $detected ] ) && in_array( $claimed, $equivalents[ $detected ], true ) ) {
            return true;
        }

        if ( isset( $equivalents[ $claimed ] ) && in_array( $detected, $equivalents[ $claimed ], true ) ) {
            return true;
        }

        return false;
    }

    /**
     * Get the file size limit for a given MIME type.
     */
    protected function getFileSizeLimit( string $mimeType, int $defaultLimit ): int
    {
        foreach ( $this->maxFileSizePerType as $pattern => $limit ) {
            if ( $this->isMimeTypeInList( $mimeType, [$pattern] ) ) {
                return $limit;
            }
        }

        return $defaultLimit;
    }

    /**
     * Format bytes to human-readable string.
     */
    protected function formatBytes( int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ( $i = 0; $bytes > 1024 && $i < count( $units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round( $bytes, 2) . ' ' . $units[ $i ];
    }
}
