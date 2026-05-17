<?php

/**
 * Secure file storage contract.
 *
 * @package    ArtisanPack_UI
 * @subpackage SecureUploads
 *
 * @author     Jacob Martella <support@artisanpackui.dev>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\SecureUploads\Contracts;

use ArtisanPackUI\SecureUploads\FileUpload\StoredFile;
use Illuminate\Http\UploadedFile;

interface SecureFileStorageInterface
{
    /**
     * Store a validated file securely.
     *
     * @param  UploadedFile  $file  The file to store
     * @param  array  $options  Storage options (disk, path, metadata, etc.)
     *
     * @return StoredFile The stored file information
     */
    public function store( UploadedFile $file, array $options = [] ): StoredFile;

    /**
     * Retrieve a stored file by its identifier.
     *
     * @param  string  $identifier  The unique file identifier
     *
     * @return StoredFile|null The stored file or null if not found
     */
    public function retrieve( string $identifier ): ?StoredFile;

    /**
     * Delete a stored file.
     *
     * @param  string  $identifier  The unique file identifier
     *
     * @return bool True if deleted successfully
     */
    public function delete( string $identifier ): bool;

    /**
     * Generate a secure URL for file access.
     *
     * @param  string  $identifier  The unique file identifier
     * @param  int|null  $expirationMinutes  URL expiration time in minutes
     *
     * @return string The secure URL
     */
    public function generateSecureUrl( string $identifier, ?int $expirationMinutes = null ): string;

    /**
     * Check if a file exists in storage.
     *
     * @param  string  $identifier  The unique file identifier
     *
     * @return bool True if the file exists
     */
    public function exists( string $identifier ): bool;

    /**
     * Get the file contents.
     *
     * @param  string  $identifier  The unique file identifier
     *
     * @return string|null The file contents or null if not found
     */
    public function getContents( string $identifier ): ?string;
}
