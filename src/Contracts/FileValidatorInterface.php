<?php

declare( strict_types=1 );

namespace ArtisanPackUI\SecureUploads\Contracts;

use ArtisanPackUI\SecureUploads\FileUpload\ValidationResult;
use Illuminate\Http\UploadedFile;

interface FileValidatorInterface
{
    /**
     * Validate an uploaded file against security rules.
     *
     * @param  UploadedFile  $file  The file to validate
     * @param  array  $options  Override default configuration options
     *
     * @return ValidationResult The validation result with any errors
     */
    public function validate( UploadedFile $file, array $options = [] ): ValidationResult;

    /**
     * Check if a file extension is allowed.
     *
     * @param  string  $extension  The extension to check (without dot)
     *
     * @return bool True if allowed, false if blocked
     */
    public function isExtensionAllowed( string $extension ): bool;

    /**
     * Check if a MIME type is allowed.
     *
     * @param  string  $mimeType  The MIME type to check
     *
     * @return bool True if allowed, false if blocked
     */
    public function isMimeTypeAllowed( string $mimeType ): bool;

    /**
     * Detect the actual MIME type from file content.
     *
     * @param  UploadedFile  $file  The file to inspect
     *
     * @return string The detected MIME type
     */
    public function detectMimeType( UploadedFile $file ): string;

    /**
     * Check if a filename contains dangerous patterns.
     *
     * @param  string  $filename  The filename to check
     *
     * @return bool True if the filename is unsafe
     */
    public function hasUnsafeFilename( string $filename ): bool;

    /**
     * Sanitize a filename for safe storage.
     *
     * @param  string  $filename  The filename to sanitize
     *
     * @return string The sanitized filename
     */
    public function sanitizeFilename( string $filename ): string;

    /**
     * Strip EXIF data from an image file.
     *
     * @param  string  $filePath  The path to the image file
     *
     * @return bool True if EXIF data was stripped successfully
     */
    public function stripExifData( string $filePath ): bool;
}
