<?php

declare( strict_types=1 );

namespace ArtisanPackUI\SecureUploads\Http\Middleware;

use ArtisanPackUI\SecureUploads\Contracts\MalwareScannerInterface;
use ArtisanPackUI\SecureUploads\Events\MalwareDetected;
use ArtisanPackUI\SecureUploads\FileUpload\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

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
            if ( ! ( $file instanceof UploadedFile ) ) {
                continue;
            }

            try {
                $result = $this->scanner->scan( $file->getPathname() );
            } catch ( Throwable $e ) {
                // A scanner that throws shouldn't 500 the whole request.
                // Mirror the existing failOnScanError policy: reject when
                // strict, otherwise log and let the upload through.
                Log::warning( 'ScanUploadedFiles: scanner threw an exception', [
                    'field'   => $key,
                    'message' => $e->getMessage(),
                ] );

                if ( config( 'artisanpack.secure-uploads.malwareScanning.failOnScanError', true ) ) {
                    return $this->scanErrorResponse( $request, $key );
                }

                continue;
            }

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

        // Attach scan results to request
        $request->attributes->set( 'malware_scan_passed', true );

        return $next( $request );
    }

    /**
     * Get all uploaded files from the request, flattened to dot-notated keys.
     *
     * Recurses into arbitrarily-nested arrays (e.g. `files[images][avatars][0]`)
     * so deeply nested upload fields can't bypass the scanner.
     *
     * @return array<string, UploadedFile>
     */
    protected function getAllUploadedFiles( Request $request ): array
    {
        $files = [];
        $this->flattenUploadedFiles( $request->allFiles(), '', $files );

        return $files;
    }

    /**
     * Recursively walk a (potentially nested) array of uploaded files,
     * collecting every UploadedFile instance keyed by its dot-notated path.
     *
     * @param  array<int|string, mixed>     $input
     * @param  array<string, UploadedFile>  $out
     */
    protected function flattenUploadedFiles( array $input, string $prefix, array &$out ): void
    {
        foreach ( $input as $key => $value ) {
            $path = '' === $prefix ? (string) $key : "{$prefix}.{$key}";

            if ( $value instanceof UploadedFile ) {
                $out[ $path ] = $value;

                continue;
            }

            if ( is_array( $value ) ) {
                $this->flattenUploadedFiles( $value, $path, $out );
            }
        }
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
