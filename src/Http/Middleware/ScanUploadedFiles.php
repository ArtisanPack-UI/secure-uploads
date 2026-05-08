<?php

declare( strict_types=1 );

namespace ArtisanPackUI\SecureUploads\Http\Middleware;

use ArtisanPackUI\SecureUploads\Contracts\MalwareScannerInterface;
use ArtisanPackUI\SecureUploads\Events\MalwareDetected;
use ArtisanPackUI\SecureUploads\FileUpload\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

class ScanUploadedFiles
{
    /**
     * Create a new middleware instance.
     */
    public function __construct(
        protected MalwareScannerInterface $scanner,
    ) {
    }

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle( Request $request, Closure $next ): Response
    {
        // Skip if malware scanning is disabled
        if ( ! config( 'artisanpack.secure-uploads.malwareScanning.enabled', false ) ) {
            return $next( $request );
        }

        // Skip if scanner is not available
        if ( ! $this->scanner->isAvailable() ) {
            // Check if we should fail on scan error
            if ( config( 'artisanpack.secure-uploads.malwareScanning.failOnScanError', true ) ) {
                return $this->scannerUnavailableResponse( $request );
            }

            return $next( $request );
        }

        // Get all uploaded files from the request
        $files = $this->getAllUploadedFiles( $request );

        if ( empty( $files ) ) {
            return $next( $request );
        }

        // Scan each file
        foreach ( $files as $key => $file ) {
            if ( $file instanceof UploadedFile ) {
                $result = $this->scanner->scan( $file->getPathname() );

                if ( $result->isInfected() ) {
                    // Dispatch malware detected event
                    event( new MalwareDetected(
                        $file->getClientOriginalName(),
                        $result,
                        $request->user(),
                        RequestContext::fromRequest( $request ),
                    ) );

                    return $this->malwareDetectedResponse( $request, $key, $result->threatName );
                }

                if ( $result->hasError() && config( 'artisanpack.secure-uploads.malwareScanning.failOnScanError', true ) ) {
                    return $this->scanErrorResponse( $request, $key );
                }
            }
        }

        // Attach scan results to request
        $request->attributes->set( 'malware_scan_passed', true );

        return $next( $request );
    }

    /**
     * Get all uploaded files from the request.
     *
     * @return array<string, UploadedFile|UploadedFile[]>
     */
    protected function getAllUploadedFiles( Request $request ): array
    {
        $files = [];

        foreach ( $request->allFiles() as $key => $file ) {
            if ( is_array( $file ) ) {
                foreach ( $file as $index => $f ) {
                    $files[ "{$key}.{$index}" ] = $f;
                }
            } else {
                $files[ $key ] = $file;
            }
        }

        return $files;
    }

    /**
     * Return scanner unavailable response.
     */
    protected function scannerUnavailableResponse( Request $request ): Response
    {
        if ( $request->expectsJson() ) {
            return response()->json( [
                'message' => 'File security scanning is temporarily unavailable. Please try again later.',
            ], 503 );
        }

        return response( 'File security scanning is temporarily unavailable. Please try again later.', 503 );
    }

    /**
     * Return malware detected response.
     */
    protected function malwareDetectedResponse( Request $request, string $field, ?string $threatName ): Response
    {
        $message = 'The uploaded file has been flagged as potentially dangerous and cannot be accepted.';

        if ( $request->expectsJson() ) {
            return response()->json( [
                'message' => $message,
                'errors'  => [
                    $field => [$message],
                ],
            ], 422 );
        }

        return redirect()
            ->back()
            ->withInput()
            ->withErrors( [$field => $message], 'file_upload' );
    }

    /**
     * Return scan error response.
     */
    protected function scanErrorResponse( Request $request, string $field ): Response
    {
        $message = 'Unable to verify file safety. Please try again.';

        if ( $request->expectsJson() ) {
            return response()->json( [
                'message' => $message,
                'errors'  => [
                    $field => [$message],
                ],
            ], 422 );
        }

        return redirect()
            ->back()
            ->withInput()
            ->withErrors( [$field => $message], 'file_upload' );
    }
}
