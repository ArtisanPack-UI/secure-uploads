<?php

declare( strict_types=1 );

namespace ArtisanPackUI\SecureUploads\Http\Controllers;

use ArtisanPackUI\SecureUploads\Contracts\SecureFileStorageInterface;
use ArtisanPackUI\SecureUploads\Events\FileServed;
use ArtisanPackUI\SecureUploads\FileUpload\RequestContext;
use ArtisanPackUI\SecureUploads\Models\SecureUploadedFile;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SecureFileController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct(
        protected SecureFileStorageInterface $storage,
    ) {
    }

    /**
     * Serve a file via signed URL.
     */
    public function show( Request $request, string $identifier ): Response|BinaryFileResponse|StreamedResponse
    {
        // Validate signed URL
        if ( ! $request->hasValidSignature() ) {
            abort( 403, 'Invalid or expired signature.' );
        }

        // Check referrer if configured
        if ( ! $this->isReferrerAllowed( $request ) ) {
            abort( 403, 'Access denied.' );
        }

        // Retrieve file
        $file = SecureUploadedFile::where( 'identifier', $identifier )->first();

        if ( ! $file ) {
            abort( 404, 'File not found.' );
        }

        // Check if file exists in storage
        if ( ! $file->existsInStorage() ) {
            abort( 404, 'File not found.' );
        }

        // Dispatch served event
        if ( config( 'artisanpack.secure-uploads.logging.downloads', true ) ) {
            event( new FileServed( $file->toStoredFile(), $request->user(), RequestContext::fromRequest( $request ) ) );
        }

        // Determine response type
        $forceDownload = config( 'artisanpack.secure-uploads.serving.forceDownload', false );

        return $this->createFileResponse( $file, $forceDownload );
    }

    /**
     * Download a file via signed URL.
     */
    public function download( Request $request, string $identifier ): Response|BinaryFileResponse|StreamedResponse
    {
        // Validate signed URL
        if ( ! $request->hasValidSignature() ) {
            abort( 403, 'Invalid or expired signature.' );
        }

        // Check referrer if configured
        if ( ! $this->isReferrerAllowed( $request ) ) {
            abort( 403, 'Access denied.' );
        }

        // Retrieve file
        $file = SecureUploadedFile::where( 'identifier', $identifier )->first();

        if ( ! $file ) {
            abort( 404, 'File not found.' );
        }

        // Check if file exists in storage
        if ( ! $file->existsInStorage() ) {
            abort( 404, 'File not found.' );
        }

        // Dispatch served event
        if ( config( 'artisanpack.secure-uploads.logging.downloads', true ) ) {
            event( new FileServed( $file->toStoredFile(), $request->user(), RequestContext::fromRequest( $request ) ) );
        }

        // Force download
        return $this->createFileResponse( $file, forceDownload: true );
    }

    /**
     * Check if the referrer is allowed.
     */
    protected function isReferrerAllowed( Request $request ): bool
    {
        $allowedReferrers = config( 'artisanpack.secure-uploads.serving.allowedReferrers', [] );

        // If no referrers configured, allow all
        if ( empty( $allowedReferrers ) ) {
            return true;
        }

        $referrer = $request->header( 'referer' );

        // No referrer header
        if ( ! $referrer ) {
            return false;
        }

        $referrerHost = parse_url( $referrer, PHP_URL_HOST );

        // Handle malformed referrer URLs (parse_url returns null/false for invalid URLs)
        if ( ! is_string( $referrerHost ) || '' === $referrerHost ) {
            return false;
        }

        foreach ( $allowedReferrers as $allowed ) {
            if ( $referrerHost === $allowed || str_ends_with( $referrerHost, '.' . $allowed ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create file response with appropriate headers.
     */
    protected function createFileResponse( SecureUploadedFile $file, bool $forceDownload = false ): BinaryFileResponse|StreamedResponse
    {
        $disk = Storage::disk( $file->disk );
        $path = $file->storage_path;

        $headers = [
            'Content-Type'   => $file->mime_type,
            'Content-Length' => $file->size,
            'Cache-Control'  => 'private, no-cache',
        ];

        $disposition                    = $forceDownload ? 'attachment' : 'inline';
        $headers['Content-Disposition'] = $this->buildContentDisposition( $disposition, $file->original_name );

        // For local disk, use BinaryFileResponse for efficiency
        if ( 'local' === $file->disk ) {
            $fullPath = $disk->path( $path );

            return response()->file( $fullPath, $headers );
        }

        // For cloud storage, use streamed response
        return response()->stream(
            function () use ( $disk, $path ): void {
                $stream = $disk->readStream( $path );
                if ( null === $stream || false === $stream ) {
                    abort( 500, 'Unable to read file.' );
                }
                fpassthru( $stream );
                if ( is_resource( $stream ) ) {
                    fclose( $stream );
                }
            },
            200,
            $headers,
        );
    }

    /**
     * Build RFC 5987/6266 compliant Content-Disposition header.
     *
     * Creates both an ASCII-safe filename parameter and a UTF-8 encoded
     * filename* parameter for proper handling of non-ASCII characters.
     */
    protected function buildContentDisposition( string $disposition, string $filename ): string
    {
        // Create ASCII-safe filename: replace non-ASCII and unsafe chars
        $asciiFilename = preg_replace( '/[^\x20-\x7E]/', '_', $filename );
        $asciiFilename = str_replace( ['\\', '"'], ['_', "'"], $asciiFilename );

        // Escape quotes and backslashes for the quoted filename
        $escapedFilename = addcslashes( $asciiFilename, '"\\' );

        // Build the header with both filename (ASCII) and filename* (UTF-8)
        $header = sprintf( '%s; filename="%s"', $disposition, $escapedFilename);

        // Add RFC 5987 encoded filename* for UTF-8 support if original differs from ASCII version
        if ( $filename !== $asciiFilename) {
            $header .= sprintf( "; filename*=UTF-8''%s", rawurlencode( $filename));
        }

        return $header;
    }
}
