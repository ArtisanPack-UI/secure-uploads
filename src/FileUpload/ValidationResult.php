<?php

/**
 * Validation pipeline result value object.
 *
 * @package    ArtisanPack_UI
 * @subpackage SecureUploads
 *
 * @author     Jacob Martella <support@artisanpackui.dev>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\SecureUploads\FileUpload;

class ValidationResult
{
    /**
     * Create a new validation result instance.
     *
     * @param  bool  $passed  Whether validation passed
     * @param  array  $errors  Array of error messages
     * @param  string|null  $detectedMimeType  The detected MIME type from content
     * @param  string|null  $sanitizedFilename  The sanitized filename
     */
    public function __construct(
        public readonly bool $passed,
        public readonly array $errors = [],
        public readonly ?string $detectedMimeType = null,
        public readonly ?string $sanitizedFilename = null,
    ) {
    }

    /**
     * Check if validation failed.
     */
    public function failed(): bool
    {
        return ! $this->passed;
    }

    /**
     * Get all validation errors.
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get the first error message.
     */
    public function getFirstError(): ?string
    {
        return $this->errors[0] ?? null;
    }

    /**
     * Check if there are any errors.
     */
    public function hasErrors(): bool
    {
        return ! empty( $this->errors );
    }

    /**
     * Create a successful validation result.
     */
    public static function success( ?string $detectedMimeType = null, ?string $sanitizedFilename = null ): self
    {
        return new self(
            passed: true,
            errors: [],
            detectedMimeType: $detectedMimeType,
            sanitizedFilename: $sanitizedFilename,
        );
    }

    /**
     * Create a failed validation result.
     */
    public static function failure( array $errors, ?string $detectedMimeType = null ): self
    {
        return new self(
            passed: false,
            errors: $errors,
            detectedMimeType: $detectedMimeType,
            sanitizedFilename: null,
        );
    }

    /**
     * Convert to array representation.
     */
    public function toArray(): array
    {
        return [
            'passed'             => $this->passed,
            'errors'             => $this->errors,
            'detected_mime_type' => $this->detectedMimeType,
            'sanitized_filename' => $this->sanitizedFilename,
        ];
    }
}
