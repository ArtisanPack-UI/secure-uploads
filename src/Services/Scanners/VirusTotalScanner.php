<?php

declare( strict_types=1 );

namespace ArtisanPackUI\SecureUploads\Services\Scanners;

use ArtisanPackUI\SecureUploads\Contracts\MalwareScannerInterface;
use ArtisanPackUI\SecureUploads\FileUpload\ScanResult;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * VirusTotal API scanner implementation.
 *
 * Uses the VirusTotal v3 API to check files against multiple antivirus engines.
 * Implements hash-based lookups first to avoid unnecessary uploads.
 */
class VirusTotalScanner implements MalwareScannerInterface
{
    /**
     * VirusTotal API base URL.
     */
    protected const API_BASE = 'https://www.virustotal.com/api/v3';

    /**
     * API key for VirusTotal.
     */
    protected string $apiKey;

    /**
     * Request timeout in seconds.
     */
    protected int $timeout;

    /**
     * Minimum number of detections to consider infected.
     */
    protected int $detectionThreshold;

    /**
     * Create a new VirusTotal scanner instance.
     */
    public function __construct(
        ?string $apiKey = null,
        int $timeout = 60,
        int $detectionThreshold = 1,
    ) {
        $config = config( 'artisanpack.secure-uploads.malwareScanning.virustotal', [] );

        $this->apiKey             = $apiKey ?? $config['apiKey'] ?? '';
        $this->timeout            = $timeout ?? $config['timeout'] ?? 60;
        $this->detectionThreshold = $detectionThreshold ?? $config['detectionThreshold'] ?? 1;
    }

    /**
     * Scan a file for malware.
     */
    public function scan( string $filePath ): ScanResult
    {
        if ( ! $this->isAvailable() ) {
            return ScanResult::error( 'VirusTotal API key not configured', $this->getName() );
        }

        if ( ! file_exists( $filePath ) ) {
            return ScanResult::error( 'File not found', $this->getName() );
        }

        // Calculate file hash
        $hash = hash_file( 'sha256', $filePath );

        // Check cache first
        $cacheKey = 'virustotal_scan_' . $hash;
        if ( $cached = Cache::get( $cacheKey ) ) {
            return ScanResult::fromArray( $cached );
        }

        // Try hash lookup first (faster, no upload needed)
        $hashResult = $this->scanByHash( $hash );

        if ( null !== $hashResult ) {
            // Cache the result
            Cache::put( $cacheKey, $hashResult->toArray(), now()->addHours( 24 ) );

            return $hashResult;
        }

        // Hash not found, need to upload the file
        $uploadResult = $this->uploadAndScan( $filePath );

        if ( null !== $uploadResult ) {
            Cache::put( $cacheKey, $uploadResult->toArray(), now()->addHours( 24 ) );
        }

        return $uploadResult ?? ScanResult::error( 'Failed to scan file', $this->getName() );
    }

    /**
     * Scan by file hash (SHA-256).
     */
    public function scanByHash( string $hash ): ?ScanResult
    {
        try {
            $response = Http::timeout( $this->timeout )
                ->withHeaders( [
                    'x-apikey' => $this->apiKey,
                ] )
                ->get( self::API_BASE . '/files/' . $hash );

            if ( 404 === $response->status() ) {
                // File not found in VirusTotal database
                return null;
            }

            if ( ! $response->successful() ) {
                Log::warning( 'VirusTotal: API error', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ] );

                return ScanResult::error( 'VirusTotal API error', $this->getName() );
            }

            return $this->parseAnalysisResponse( $response->json() );

        } catch ( Exception $e ) {
            Log::error( 'VirusTotal: Exception during hash lookup', [
                'message' => $e->getMessage(),
            ] );

            return ScanResult::error( 'VirusTotal lookup failed: ' . $e->getMessage(), $this->getName() );
        }
    }

    /**
     * Check if the scanner service is available.
     */
    public function isAvailable(): bool
    {
        return ! empty( $this->apiKey );
    }

    /**
     * Get the scanner name/identifier.
     */
    public function getName(): string
    {
        return 'virustotal';
    }

    /**
     * Upload and scan a file.
     */
    protected function uploadAndScan( string $filePath ): ?ScanResult
    {
        try {
            // Get upload URL
            $uploadUrl = $this->getUploadUrl( $filePath );

            if ( ! $uploadUrl ) {
                return ScanResult::error( 'Failed to get upload URL', $this->getName() );
            }

            // Upload file — open the stream up-front so we can detect fopen
            // failures and guarantee fclose runs even if Http::post throws.
            $stream = fopen( $filePath, 'r' );

            if ( false === $stream ) {
                Log::error( 'VirusTotal: unable to open file for upload', [ 'path' => $filePath ] );

                return ScanResult::error( 'Failed to open file for upload', $this->getName() );
            }

            try {
                $response = Http::timeout( $this->timeout )
                    ->withHeaders( [
                        'x-apikey' => $this->apiKey,
                    ] )
                    ->attach( 'file', $stream, basename( $filePath ) )
                    ->post( $uploadUrl );
            } finally {
                if ( is_resource( $stream ) ) {
                    fclose( $stream );
                }
            }

            if ( ! $response->successful() ) {
                return ScanResult::error( 'Failed to upload file', $this->getName() );
            }

            $data       = $response->json();
            $analysisId = $data['data']['id'] ?? null;

            if ( ! $analysisId ) {
                return ScanResult::error( 'No analysis ID returned', $this->getName() );
            }

            // Wait for analysis to complete
            return $this->waitForAnalysis( $analysisId );

        } catch ( Exception $e ) {
            Log::error( 'VirusTotal: Exception during upload', [
                'message' => $e->getMessage(),
            ] );

            return ScanResult::error( 'VirusTotal upload failed: ' . $e->getMessage(), $this->getName() );
        }
    }

    /**
     * Get the appropriate upload URL based on file size.
     */
    protected function getUploadUrl( string $filePath ): ?string
    {
        $fileSize = filesize( $filePath );

        // Files under 32MB can use the standard endpoint
        if ( $fileSize < 32 * 1024 * 1024 ) {
            return self::API_BASE . '/files';
        }

        // Larger files need a special upload URL
        try {
            $response = Http::timeout( $this->timeout )
                ->withHeaders( [
                    'x-apikey' => $this->apiKey,
                ] )
                ->get( self::API_BASE . '/files/upload_url' );

            if ( $response->successful() ) {
                return $response->json()['data'] ?? null;
            }
        } catch ( Exception $e ) {
            Log::error( 'VirusTotal: Failed to get upload URL', [
                'message' => $e->getMessage(),
            ] );
        }

        return null;
    }

    /**
     * Wait for analysis to complete and return result.
     */
    protected function waitForAnalysis( string $analysisId, int $maxAttempts = 30 ): ScanResult
    {
        // For async contexts, return pending immediately
        // The caller should queue a job to poll for completion
        if ( config( 'artisanpack.secure-uploads.malwareScanning.async', false ) ) {
            return ScanResult::pending( $this->getName(), ['analysis_id' => $analysisId] );
        }

        $attempts = 0;

        while ( $attempts < $maxAttempts ) {
            sleep( 2 ); // Wait 2 seconds between checks

            try {
                $response = Http::timeout( $this->timeout )
                    ->withHeaders( [
                        'x-apikey' => $this->apiKey,
                    ] )
                    ->get( self::API_BASE . '/analyses/' . $analysisId );

                if ( ! $response->successful() ) {
                    $attempts++;

                    continue;
                }

                $data   = $response->json();
                $status = $data['data']['attributes']['status'] ?? 'queued';

                if ( 'completed' === $status ) {
                    return $this->parseAnalysisStats( $data['data']['attributes']['stats'] ?? [] );
                }

            } catch ( Exception $e ) {
                Log::warning( 'VirusTotal: Error checking analysis status', [
                    'message' => $e->getMessage(),
                ] );
            }

            $attempts++;
        }

        return ScanResult::pending( $this->getName(), ['analysis_id' => $analysisId] );
    }

    /**
     * Parse analysis response from file endpoint.
     */
    protected function parseAnalysisResponse( array $data ): ScanResult
    {
        $stats = $data['data']['attributes']['last_analysis_stats'] ?? [];

        return $this->parseAnalysisStats( $stats );
    }

    /**
     * Parse analysis statistics to determine result.
     */
    protected function parseAnalysisStats( array $stats ): ScanResult
    {
        $malicious  = $stats['malicious'] ?? 0;
        $suspicious = $stats['suspicious'] ?? 0;

        $totalDetections = $malicious + $suspicious;

        if ( $totalDetections >= $this->detectionThreshold ) {
            $threatName = "Detected by {$totalDetections} engine(s)";

            return ScanResult::infected( $threatName, $this->getName(), [
                'malicious'  => $malicious,
                'suspicious' => $suspicious,
                'undetected' => $stats['undetected'] ?? 0,
            ] );
        }

        return ScanResult::clean( $this->getName(), [
            'malicious'  => $malicious,
            'suspicious' => $suspicious,
            'undetected' => $stats['undetected'] ?? 0,
        ]);
    }
}
