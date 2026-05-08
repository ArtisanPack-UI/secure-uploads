<?php

declare( strict_types=1 );

namespace ArtisanPackUI\SecureUploads\Rules;

use ArtisanPackUI\SecureUploads\Contracts\FileValidatorInterface;
use ArtisanPackUI\SecureUploads\Contracts\MalwareScannerInterface;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Http\UploadedFile;

class SecureFile implements Rule
{
    /**
     * Allowed MIME types.
     */
    protected array $allowedMimeTypes = [];

    /**
     * Maximum file size in bytes.
     */
    protected ?int $maxSize = null;

    /**
     * Whether to scan for malware.
     */
    protected bool $scanForMalware = false;

    /**
     * Validation errors.
     */
    protected array $errors = [];

    /**
     * Create a new rule instance.
     */
    public function __construct( array $allowedMimeTypes = [], ?int $maxSize = null )
    {
        $this->allowedMimeTypes = $allowedMimeTypes;
        $this->maxSize          = $maxSize;
    }

    /**
     * Determine if the validation rule passes.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     */
    public function passes( $attribute, $value ): bool
    {
        $this->errors = [];

        if ( ! $value instanceof UploadedFile ) {
            $this->errors[] = 'The :attribute must be a file.';

            return false;
        }

        // Use the file validation service for comprehensive checks
        $validator = app( FileValidatorInterface::class );

        $options = [];
        if ( ! empty( $this->allowedMimeTypes ) ) {
            $options['allowedMimeTypes'] = $this->allowedMimeTypes;
        }
        if ( null !== $this->maxSize ) {
            $options['maxFileSize'] = $this->maxSize;
        }

        $result = $validator->validate( $value, $options );

        if ( $result->failed() ) {
            $this->errors = $result->getErrors();

            return false;
        }

        // Optionally scan for malware
        if ( $this->scanForMalware && config( 'artisanpack.secure-uploads.malwareScanning.enabled', false ) ) {
            $scanner = app( MalwareScannerInterface::class );

            // Fail closed: if scanning is required but the scanner is unavailable,
            // reject the file rather than silently letting it through.
            if ( ! $scanner->isAvailable() ) {
                $this->errors[] = 'Malware scanner unavailable; cannot verify file safety.';

                return false;
            }

            $scanResult = $scanner->scan( $value->getPathname() );

            if ( $scanResult->isInfected() ) {
                $this->errors[] = 'The file has been flagged as potentially dangerous.';

                return false;
            }

            if ( $scanResult->hasError() && config( 'artisanpack.secure-uploads.malwareScanning.failOnScanError', true ) ) {
                $this->errors[] = 'Unable to verify file safety. Please try again.';

                return false;
            }
        }

        return true;
    }

    /**
     * Get the validation error message.
     *
     * @return array|string
     */
    public function message(): array|string
    {
        return $this->errors;
    }

    /**
     * Set allowed MIME types (fluent).
     */
    public function types( array $mimeTypes ): self
    {
        $this->allowedMimeTypes = $mimeTypes;

        return $this;
    }

    /**
     * Set maximum file size in bytes (fluent).
     */
    public function maxSize( int $bytes ): self
    {
        $this->maxSize = $bytes;

        return $this;
    }

    /**
     * Set maximum file size in kilobytes (fluent).
     */
    public function maxKilobytes( int $kilobytes ): self
    {
        $this->maxSize = $kilobytes * 1024;

        return $this;
    }

    /**
     * Set maximum file size in megabytes (fluent).
     */
    public function maxMegabytes( int $megabytes ): self
    {
        $this->maxSize = $megabytes * 1024 * 1024;

        return $this;
    }

    /**
     * Enable malware scanning (fluent).
     */
    public function scanMalware( bool $scan = true ): self
    {
        $this->scanForMalware = $scan;

        return $this;
    }

    /**
     * Allow only image files (fluent).
     */
    public function images(): self
    {
        $this->allowedMimeTypes = [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
        ];

        return $this;
    }

    /**
     * Allow only document files (fluent).
     */
    public function documents(): self
    {
        $this->allowedMimeTypes = [
            'application/pdf',
            'text/plain',
            'text/csv',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];

        return $this;
    }

    /**
     * Allow only video files (fluent).
     */
    public function videos(): self
    {
        $this->allowedMimeTypes = [
            'video/mp4',
            'video/webm',
            'video/quicktime',
            'video/x-msvideo',
        ];

        return $this;
    }

    /**
     * Allow only audio files (fluent).
     */
    public function audio(): self
    {
        $this->allowedMimeTypes = [
            'audio/mpeg',
            'audio/wav',
            'audio/ogg',
            'audio/mp4',
        ];

        return $this;
    }
}
