<?php

declare( strict_types=1 );

namespace ArtisanPackUI\SecureUploads\FileUpload;
use InvalidArgumentException;

class StoredFile
{
    /**
     * Create a new stored file instance.
     *
     * @param  string  $identifier  Unique identifier for the file
     * @param  string  $originalName  Original filename as uploaded
     * @param  string  $storagePath  Path where the file is stored
     * @param  string  $mimeType  MIME type of the file
     * @param  int  $size  File size in bytes
     * @param  string  $hash  SHA-256 hash of the file
     * @param  string  $disk  Storage disk name
     * @param  array  $metadata  Additional metadata
     */
    public function __construct(
        public readonly string $identifier,
        public readonly string $originalName,
        public readonly string $storagePath,
        public readonly string $mimeType,
        public readonly int $size,
        public readonly string $hash,
        public readonly string $disk = 'local',
        public readonly array $metadata = [],
    ) {
    }

    /**
     * Get human-readable file size.
     */
    public function humanSize(): string
    {
        $bytes = $this->size;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ( $i = 0; $bytes > 1024 && $i < count( $units ) - 1; $i++ ) {
            $bytes /= 1024;
        }

        return round( $bytes, 2 ) . ' ' . $units[ $i ];
    }

    /**
     * Get the file extension from the original name.
     */
    public function getExtension(): string
    {
        return strtolower( pathinfo( $this->originalName, PATHINFO_EXTENSION ) );
    }

    /**
     * Check if the file is an image.
     */
    public function isImage(): bool
    {
        return str_starts_with( $this->mimeType, 'image/' );
    }

    /**
     * Check if the file is a video.
     */
    public function isVideo(): bool
    {
        return str_starts_with( $this->mimeType, 'video/' );
    }

    /**
     * Check if the file is audio.
     */
    public function isAudio(): bool
    {
        return str_starts_with( $this->mimeType, 'audio/' );
    }

    /**
     * Check if the file is a document (PDF, text, etc.).
     */
    public function isDocument(): bool
    {
        $documentTypes = [
            'application/pdf',
            'text/plain',
            'text/csv',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];

        return in_array( $this->mimeType, $documentTypes, true );
    }

    /**
     * Convert to array representation.
     */
    public function toArray(): array
    {
        return [
            'identifier'    => $this->identifier,
            'original_name' => $this->originalName,
            'storage_path'  => $this->storagePath,
            'mime_type'     => $this->mimeType,
            'size'          => $this->size,
            'hash'          => $this->hash,
            'disk'          => $this->disk,
            'metadata'      => $this->metadata,
        ];
    }

    /**
     * Create from array representation.
     *
     * @throws InvalidArgumentException if required keys are missing
     */
    public static function fromArray( array $data ): self
    {
        $requiredKeys = ['identifier', 'original_name', 'storage_path', 'mime_type', 'hash'];
        $missingKeys  = [];

        foreach ( $requiredKeys as $key ) {
            if ( ! isset( $data[ $key ] ) || ! is_string( $data[ $key ] ) || '' === $data[ $key ] ) {
                $missingKeys[] = $key;
            }
        }

        if ( ! empty( $missingKeys ) ) {
            throw new InvalidArgumentException(
                'Missing or invalid required keys in StoredFile::fromArray: ' . implode( ', ', $missingKeys ),
            );
        }

        return new self(
            identifier: $data['identifier'],
            originalName: $data['original_name'],
            storagePath: $data['storage_path'],
            mimeType: $data['mime_type'],
            size: (int) ( $data['size'] ?? 0 ),
            hash: $data['hash'],
            disk: $data['disk'] ?? 'local',
            metadata: $data['metadata'] ?? [],
        );
    }
}
