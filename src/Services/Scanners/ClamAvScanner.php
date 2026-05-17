<?php

/**
 * ClamAV malware scanner (Unix socket with binary fallback).
 *
 * @package    ArtisanPack_UI
 * @subpackage SecureUploads
 *
 * @author     Jacob Martella <support@artisanpackui.dev>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\SecureUploads\Services\Scanners;

use ArtisanPackUI\SecureUploads\Contracts\MalwareScannerInterface;
use ArtisanPackUI\SecureUploads\FileUpload\ScanResult;
use Illuminate\Support\Facades\Log;

/**
 * ClamAV antivirus scanner implementation.
 *
 * Supports scanning via Unix socket (clamd daemon) or command-line binary.
 */
class ClamAvScanner implements MalwareScannerInterface
{
    /**
     * Path to the ClamAV Unix socket.
     */
    protected string $socketPath;

    /**
     * Path to the clamscan binary.
     */
    protected ?string $binaryPath;

    /**
     * Scan timeout in seconds.
     */
    protected int $timeout;

    /**
     * Create a new ClamAV scanner instance.
     */
    public function __construct(
        ?string $socketPath = null,
        ?string $binaryPath = null,
        ?int $timeout = null,
    ) {
        $config = config( 'artisanpack.secure-uploads.malwareScanning.clamav', [] );

        $this->socketPath = $socketPath ?? $config['socketPath'] ?? '/var/run/clamav/clamd.sock';
        $this->binaryPath = $binaryPath ?? $config['binaryPath'] ?? '/usr/bin/clamscan';
        $this->timeout    = $timeout ?? $config['timeout'] ?? 30;
    }

    /**
     * Scan a file for malware.
     */
    public function scan( string $filePath ): ScanResult
    {
        if ( ! file_exists( $filePath ) ) {
            return ScanResult::error( 'File not found', $this->getName() );
        }

        // Try socket first (faster)
        if ( $this->isSocketAvailable() ) {
            return $this->scanViaSocket( $filePath );
        }

        // Fall back to binary
        if ( $this->isBinaryAvailable() ) {
            return $this->scanViaBinary( $filePath );
        }

        return ScanResult::error( 'ClamAV is not available', $this->getName() );
    }

    /**
     * Check if the scanner service is available.
     */
    public function isAvailable(): bool
    {
        return $this->isSocketAvailable() || $this->isBinaryAvailable();
    }

    /**
     * Get the scanner name/identifier.
     */
    public function getName(): string
    {
        return 'clamav';
    }

    /**
     * Scan a file via the ClamAV Unix socket.
     */
    protected function scanViaSocket( string $filePath ): ScanResult
    {
        $socket = @socket_create( AF_UNIX, SOCK_STREAM, 0 );

        if ( false === $socket ) {
            Log::warning( 'ClamAV: Failed to create socket' );

            return ScanResult::error( 'Failed to create socket', $this->getName() );
        }

        // Set timeout
        socket_set_option( $socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => $this->timeout, 'usec' => 0] );
        socket_set_option( $socket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => $this->timeout, 'usec' => 0] );

        if ( false === @socket_connect( $socket, $this->socketPath ) ) {
            socket_close( $socket );
            Log::warning( 'ClamAV: Failed to connect to socket', ['path' => $this->socketPath] );

            return ScanResult::error( 'Failed to connect to ClamAV daemon', $this->getName() );
        }

        // Send SCAN command
        $command = 'SCAN ' . $filePath . "\n";
        $written = @socket_write( $socket, $command, strlen( $command ) );
        if ( false === $written ) {
            socket_close( $socket );
            Log::warning( 'ClamAV: Failed to write to socket' );
            return ScanResult::error( 'Failed to send scan command', $this->getName() );
        }

        // Read response
        $response = '';
        while ( ( $buffer = @socket_read( $socket, 8192 ) ) !== false && '' !== $buffer ) {
            $response .= $buffer;
        }
        
        if ( false === $buffer ) {
            socket_close( $socket );
            Log::warning( 'ClamAV: Socket read error' );
            return ScanResult::error( 'Failed to read scan response', $this->getName() );
        }

        socket_close( $socket );

        return $this->parseResponse( $response );
    }

    /**
     * Scan a file via the clamscan binary.
     *
     * Uses proc_open + non-blocking stream reads + stream_select so the
     * configured timeout is enforced. exec() would block indefinitely on
     * a hung clamscan, defeating the timeout entirely.
     */
    protected function scanViaBinary( string $filePath ): ScanResult
    {
        $escapedPath   = escapeshellarg( $filePath );
        $escapedBinary = escapeshellarg( $this->binaryPath );

        $command = sprintf( '%s --no-summary %s', $escapedBinary, $escapedPath );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open( $command, $descriptors, $pipes );

        if ( ! is_resource( $process ) ) {
            return ScanResult::error( 'Failed to launch clamscan', $this->getName() );
        }

        fclose( $pipes[0] );
        stream_set_blocking( $pipes[1], false );
        stream_set_blocking( $pipes[2], false );

        $deadline = microtime( true ) + $this->timeout;
        $stdout   = '';
        $stderr   = '';

        while ( true ) {
            $remaining = $deadline - microtime( true );

            if ( $remaining <= 0 ) {
                proc_terminate( $process, 9 );
                fclose( $pipes[1] );
                fclose( $pipes[2] );
                proc_close( $process );

                Log::warning( 'ClamAV: scan timed out', [
                    'file'    => $filePath,
                    'timeout' => $this->timeout,
                ] );

                return ScanResult::error( 'ClamAV scan timed out', $this->getName() );
            }

            $read   = [ $pipes[1], $pipes[2] ];
            $write  = null;
            $except = null;

            $secs  = (int) $remaining;
            $usecs = (int) ( ( $remaining - $secs ) * 1_000_000 );

            $ready = stream_select( $read, $write, $except, $secs, $usecs );

            if ( false === $ready ) {
                // Interrupted; loop and re-check the deadline.
                continue;
            }

            foreach ( $read as $stream ) {
                $chunk = stream_get_contents( $stream );
                if ( '' !== $chunk && false !== $chunk ) {
                    if ( $stream === $pipes[1] ) {
                        $stdout .= $chunk;
                    } else {
                        $stderr .= $chunk;
                    }
                }
            }

            $status = proc_get_status( $process );
            if ( ! $status['running'] ) {
                // Drain any final bytes after the process exits.
                $stdout .= (string) stream_get_contents( $pipes[1] );
                $stderr .= (string) stream_get_contents( $pipes[2] );
                break;
            }
        }

        fclose( $pipes[1] );
        fclose( $pipes[2] );

        $returnCode = proc_close( $process );
        $response   = trim( $stdout . ( '' !== $stderr ? "\n" . $stderr : '' ) );

        // Return codes: 0 = clean, 1 = virus found, 2 = error
        if ( 2 === $returnCode ) {
            return ScanResult::error( 'ClamAV scan error: ' . $response, $this->getName() );
        }

        return $this->parseResponse( $response );
    }

    /**
     * Parse ClamAV response to determine scan result.
     */
    protected function parseResponse( string $response ): ScanResult
    {
        $response = trim( $response );

        // Check for OK result
        if ( str_contains( $response, ': OK' ) ) {
            return ScanResult::clean( $this->getName() );
        }

        // Check for FOUND result (virus detected)
        if ( preg_match( '/: (.+) FOUND$/', $response, $matches ) ) {
            $threatName = trim( $matches[1] );

            return ScanResult::infected( $threatName, $this->getName() );
        }

        // Check for ERROR
        if ( str_contains( $response, 'ERROR' ) ) {
            return ScanResult::error( 'ClamAV error: ' . $response, $this->getName() );
        }

        // Unknown response
        Log::warning( 'ClamAV: Unknown response', ['response' => $response] );

        return ScanResult::error( 'Unknown ClamAV response', $this->getName() );
    }

    /**
     * Check if the ClamAV socket is available.
     */
    protected function isSocketAvailable(): bool
    {
        return file_exists( $this->socketPath ) && is_readable( $this->socketPath );
    }

    /**
     * Check if the clamscan binary is available.
     */
    protected function isBinaryAvailable(): bool
    {
        return $this->binaryPath && file_exists( $this->binaryPath ) && is_executable( $this->binaryPath );
    }
}
