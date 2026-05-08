<?php

declare( strict_types=1 );

namespace ArtisanPackUI\SecureUploads\FileUpload;
use InvalidArgumentException;

class ScanResult
{
    public const STATUS_CLEAN = 'clean';

    public const STATUS_INFECTED = 'infected';

    public const STATUS_ERROR = 'error';

    public const STATUS_PENDING = 'pending';

    /**
     * Create a new scan result instance.
     *
     * @param  string  $status  The scan status (clean, infected, error, pending)
     * @param  string|null  $threatName  The name of detected threat (if any)
     * @param  string|null  $scannerName  The scanner that performed the scan
     * @param  array  $metadata  Additional scan metadata
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $threatName = null,
        public readonly ?string $scannerName = null,
        public readonly array $metadata = [],
    ) {
    }

    /**
     * Check if the file is clean (no threats detected).
     */
    public function isClean(): bool
    {
        return self::STATUS_CLEAN === $this->status;
    }

    /**
     * Check if the file is infected.
     */
    public function isInfected(): bool
    {
        return self::STATUS_INFECTED === $this->status;
    }

    /**
     * Check if there was an error during scanning.
     */
    public function hasError(): bool
    {
        return self::STATUS_ERROR === $this->status;
    }

    /**
     * Check if the scan is still pending.
     */
    public function isPending(): bool
    {
        return self::STATUS_PENDING === $this->status;
    }

    /**
     * Create a clean scan result.
     */
    public static function clean( ?string $scannerName = null, array $metadata = [] ): self
    {
        return new self(
            status: self::STATUS_CLEAN,
            threatName: null,
            scannerName: $scannerName,
            metadata: $metadata,
        );
    }

    /**
     * Create an infected scan result.
     */
    public static function infected( string $threatName, ?string $scannerName = null, array $metadata = [] ): self
    {
        return new self(
            status: self::STATUS_INFECTED,
            threatName: $threatName,
            scannerName: $scannerName,
            metadata: $metadata,
        );
    }

    /**
     * Create an error scan result.
     */
    public static function error( string $errorMessage, ?string $scannerName = null ): self
    {
        return new self(
            status: self::STATUS_ERROR,
            threatName: null,
            scannerName: $scannerName,
            metadata: ['error' => $errorMessage],
        );
    }

    /**
     * Create a pending scan result.
     */
    public static function pending( ?string $scannerName = null, array $metadata = [] ): self
    {
        return new self(
            status: self::STATUS_PENDING,
            threatName: null,
            scannerName: $scannerName,
            metadata: $metadata,
        );
    }

    /**
     * Convert to array representation.
     */
    public function toArray(): array
    {
        return [
            'status'       => $this->status,
            'threat_name'  => $this->threatName,
            'scanner_name' => $this->scannerName,
            'metadata'     => $this->metadata,
        ];
    }

    /**
     * Create from array representation.
     *
     * @throws InvalidArgumentException if required 'status' key is missing
     */
    public static function fromArray( array $data ): self
    {
        if ( ! isset( $data['status'] ) || ! is_string( $data['status'] ) || '' === $data['status'] ) {
            throw new InvalidArgumentException(
                'Missing or invalid required "status" key in ScanResult::fromArray',
            );
        }

        return new self(
            status: $data['status'],
            threatName: $data['threat_name'] ?? null,
            scannerName: $data['scanner_name'] ?? null,
            metadata: $data['metadata'] ?? [],
        );
    }
}
