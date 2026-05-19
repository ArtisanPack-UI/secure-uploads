<?php

/**
 * HasSecureFiles Eloquent concern.
 *
 * @package    ArtisanPack_UI
 * @subpackage SecureUploads
 *
 * @author     Jacob Martella <support@artisanpackui.dev>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\SecureUploads\Concerns;

use ArtisanPackUI\SecureUploads\Contracts\SecureFileStorageInterface;
use ArtisanPackUI\SecureUploads\FileUpload\StoredFile;
use ArtisanPackUI\SecureUploads\Models\SecureUploadedFile;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Trait for models that have secure file attachments.
 *
 * Add this trait to any model that needs to have files attached to it.
 * Files are stored securely and can be retrieved via signed URLs.
 *
 * @property-read Collection|SecureUploadedFile[] $secureFiles
 */
trait HasSecureFiles
{
    /**
     * Boot the trait.
     */
    public static function bootHasSecureFiles(): void
    {
        // Delete associated files when the model is deleted
        static::deleting( function ( $model ): void {
            if ( method_exists( $model, 'isForceDeleting' ) && ! $model->isForceDeleting() ) {
                return;
            }

            $model->secureFiles()->each( function ( $file ): void {
                $file->deleteFromStorage();
                $file->forceDelete();
            } );
        } );
    }

    /**
     * Get all secure files attached to this model.
     */
    public function secureFiles(): MorphMany
    {
        return $this->morphMany( SecureUploadedFile::class, 'fileable' );
    }

    /**
     * Attach a secure file to this model.
     */
    public function attachSecureFile( UploadedFile $file, array $options = [] ): StoredFile
    {
        if ( ! $this->exists || null === $this->getKey() ) {
            throw new LogicException(
                'Cannot attach a secure file to an unsaved model. '
                . 'Save the parent model before calling attachSecureFile() so the morph relation can be persisted.',
            );
        }

        $storage = app( SecureFileStorageInterface::class );

        $storedFile = $storage->store( $file, array_merge( $options, [
            'metadata' => array_merge( $options['metadata'] ?? [], [
                'fileable_type' => static::class,
                'fileable_id'   => $this->getKey(),
            ] ),
        ] ) );

        // Update the database record to associate with this model
        SecureUploadedFile::where( 'identifier', $storedFile->identifier )
            ->update( [
                'fileable_type' => static::class,
                'fileable_id'   => $this->getKey(),
            ] );

        return $storedFile;
    }

    /**
     * Attach multiple secure files to this model.
     *
     * @param  array<UploadedFile>  $files
     *
     * @return array<StoredFile>
     */
    public function attachSecureFiles( array $files, array $options = [] ): array
    {
        $storedFiles = [];

        foreach ( $files as $file ) {
            if ( $file instanceof UploadedFile ) {
                $storedFiles[] = $this->attachSecureFile( $file, $options );
            }
        }

        return $storedFiles;
    }

    /**
     * Detach a secure file from this model.
     */
    public function detachSecureFile( string $identifier, bool $deleteFile = true ): bool
    {
        $file = $this->secureFiles()->where( 'identifier', $identifier )->first();

        if ( ! $file ) {
            return false;
        }

        if ( $deleteFile ) {
            $file->deleteFromStorage();
            $file->forceDelete();
        } else {
            $file->update( [
                'fileable_type' => null,
                'fileable_id'   => null,
            ] );
        }

        return true;
    }

    /**
     * Detach all secure files from this model.
     */
    public function detachAllSecureFiles( bool $deleteFiles = true ): int
    {
        $count = 0;

        $this->secureFiles()->each( function ( $file ) use ( $deleteFiles, &$count ): void {
            if ( $deleteFiles ) {
                $file->deleteFromStorage();
                $file->forceDelete();
            } else {
                $file->update( [
                    'fileable_type' => null,
                    'fileable_id'   => null,
                ] );
            }
            $count++;
        } );

        return $count;
    }

    /**
     * Get secure files of a specific type.
     */
    public function secureFilesOfType( string $mimeTypePrefix ): Collection
    {
        return $this->secureFiles()
            ->where( 'mime_type', 'like', $mimeTypePrefix . '%' )
            ->get();
    }

    /**
     * Get secure images attached to this model.
     */
    public function secureImages(): Collection
    {
        return $this->secureFilesOfType( 'image/' );
    }

    /**
     * Get secure documents attached to this model.
     */
    public function secureDocuments(): Collection
    {
        return $this->secureFiles()
            ->whereIn( 'mime_type', [
                'application/pdf',
                'text/plain',
                'text/csv',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ] )
            ->get();
    }

    /**
     * Get the first secure file attached to this model.
     */
    public function primarySecureFile(): ?SecureUploadedFile
    {
        return $this->secureFiles()->first();
    }

    /**
     * Check if this model has any secure files attached.
     */
    public function hasSecureFiles(): bool
    {
        return $this->secureFiles()->exists();
    }

    /**
     * Get the total size of all secure files in bytes.
     */
    public function secureFilesTotalSize(): int
    {
        return (int) $this->secureFiles()->sum( 'size');
    }
}
