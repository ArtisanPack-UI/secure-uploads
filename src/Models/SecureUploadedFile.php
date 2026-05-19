<?php

/**
 * Secure uploaded file Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage SecureUploads
 *
 * @author     Jacob Martella <support@artisanpackui.dev>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\SecureUploads\Models;

use ArtisanPackUI\SecureUploads\FileUpload\ScanResult;
use ArtisanPackUI\SecureUploads\FileUpload\StoredFile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class SecureUploadedFile extends Model
{
    use HasFactory;
use HasUuids;
use SoftDeletes;

    /**
     * The table associated with the model.
     */
    protected $table = 'secure_files';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'identifier',
        'original_name',
        'storage_path',
        'disk',
        'mime_type',
        'size',
        'hash',
        'uploaded_by',
        'scan_status',
        'threat_name',
        'scanned_at',
        'fileable_type',
        'fileable_id',
        'metadata',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'size'       => 'integer',
        'metadata'   => 'array',
        'scanned_at' => 'datetime',
    ];

    /**
     * The column used for UUID generation.
     */
    public function uniqueIds(): array
    {
        return ['identifier'];
    }

    /**
     * Get the user who uploaded the file.
     */
    public function uploadedBy(): BelongsTo
    {
        $userModel = config( 'artisanpack.secure-uploads.user_model' )
            ?? config( 'artisanpack.security.user_model' )
            ?? config( 'auth.providers.users.model', 'App\\Models\\User' );

        return $this->belongsTo( $userModel, 'uploaded_by' );
    }

    /**
     * Get the parent model that this file is attached to.
     */
    public function fileable(): \Illuminate\Database\Eloquent\Relations\MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get a secure (signed) URL for this file.
     */
    public function getSecureUrl( ?int $expirationMinutes = null ): string
    {
        $expiration = $expirationMinutes
            ?? config( 'artisanpack.secure-uploads.serving.signedUrlExpiration', 60 );

        return URL::temporarySignedRoute(
            'secure-file.show',
            now()->addMinutes( $expiration ),
            ['identifier' => $this->identifier],
        );
    }

    /**
     * Get a secure download URL for this file.
     */
    public function getDownloadUrl( ?int $expirationMinutes = null ): string
    {
        $expiration = $expirationMinutes
            ?? config( 'artisanpack.secure-uploads.serving.signedUrlExpiration', 60 );

        return URL::temporarySignedRoute(
            'secure-file.download',
            now()->addMinutes( $expiration ),
            ['identifier' => $this->identifier],
        );
    }

    /**
     * Get the full storage path.
     */
    public function getFullPath(): string
    {
        return Storage::disk( $this->disk )->path( $this->storage_path );
    }

    /**
     * Check if the file exists in storage.
     */
    public function existsInStorage(): bool
    {
        return Storage::disk( $this->disk )->exists( $this->storage_path );
    }

    /**
     * Get the file contents.
     */
    public function getContents(): ?string
    {
        if ( ! $this->existsInStorage() ) {
            return null;
        }

        return Storage::disk( $this->disk )->get( $this->storage_path );
    }

    /**
     * Delete the file from storage.
     */
    public function deleteFromStorage(): bool
    {
        return Storage::disk( $this->disk )->delete( $this->storage_path );
    }

    /**
     * Mark the file as clean (no threats detected).
     */
    public function markAsClean(): void
    {
        $this->update( [
            'scan_status' => ScanResult::STATUS_CLEAN,
            'threat_name' => null,
            'scanned_at'  => now(),
        ] );
    }

    /**
     * Mark the file as infected.
     */
    public function markAsInfected( string $threatName ): void
    {
        $this->update( [
            'scan_status' => ScanResult::STATUS_INFECTED,
            'threat_name' => $threatName,
            'scanned_at'  => now(),
        ] );
    }

    /**
     * Mark scan as errored.
     */
    public function markScanError(): void
    {
        $this->update( [
            'scan_status' => ScanResult::STATUS_ERROR,
            'scanned_at'  => now(),
        ] );
    }

    /**
     * Get human-readable file size.
     */
    public function humanFileSize(): string
    {
        $bytes = $this->size;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ( $i = 0; $bytes > 1024 && $i < count( $units ) - 1; $i++ ) {
            $bytes /= 1024;
        }

        return round( $bytes, 2 ) . ' ' . $units[ $i ];
    }

    /**
     * Check if the file is an image.
     */
    public function isImage(): bool
    {
        return str_starts_with( $this->mime_type, 'image/' );
    }

    /**
     * Check if the file is a video.
     */
    public function isVideo(): bool
    {
        return str_starts_with( $this->mime_type, 'video/' );
    }

    /**
     * Check if the file is audio.
     */
    public function isAudio(): bool
    {
        return str_starts_with( $this->mime_type, 'audio/' );
    }

    /**
     * Check if the file is a document.
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

        return in_array( $this->mime_type, $documentTypes, true );
    }

    /**
     * Convert to StoredFile value object.
     */
    public function toStoredFile(): StoredFile
    {
        return new StoredFile(
            identifier: $this->identifier,
            originalName: $this->original_name,
            storagePath: $this->storage_path,
            mimeType: $this->mime_type,
            size: $this->size,
            hash: $this->hash,
            disk: $this->disk,
            metadata: $this->metadata ?? [],
        );
    }

    /**
     * Scope: Only clean files.
     */
    public function scopeClean( Builder $query ): Builder
    {
        return $query->where( 'scan_status', ScanResult::STATUS_CLEAN );
    }

    /**
     * Scope: Only infected files.
     */
    public function scopeInfected( Builder $query ): Builder
    {
        return $query->where( 'scan_status', ScanResult::STATUS_INFECTED );
    }

    /**
     * Scope: Files pending scan.
     */
    public function scopePendingScan( Builder $query ): Builder
    {
        return $query->where( 'scan_status', ScanResult::STATUS_PENDING );
    }

    /**
     * Scope: Files with scan errors.
     */
    public function scopeScanError( Builder $query ): Builder
    {
        return $query->where( 'scan_status', ScanResult::STATUS_ERROR );
    }

    /**
     * Scope: Only images.
     */
    public function scopeImages( Builder $query ): Builder
    {
        return $query->where( 'mime_type', 'like', 'image/%' );
    }

    /**
     * Scope: Only videos.
     */
    public function scopeVideos( Builder $query ): Builder
    {
        return $query->where( 'mime_type', 'like', 'video/%' );
    }

    /**
     * Scope: Only audio files.
     */
    public function scopeAudio( Builder $query ): Builder
    {
        return $query->where( 'mime_type', 'like', 'audio/%' );
    }

    /**
     * Scope: By uploader.
     */
    public function scopeByUploader( Builder $query, int $userId ): Builder
    {
        return $query->where( 'uploaded_by', $userId);
    }
}
