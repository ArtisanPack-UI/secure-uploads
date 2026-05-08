<?php

namespace Tests\Unit;

use ArtisanPackUI\SecureUploads\Contracts\MalwareScannerInterface;
use ArtisanPackUI\SecureUploads\FileUpload\ScanResult;
use ArtisanPackUI\SecureUploads\Services\Scanners\ClamAvScanner;
use ArtisanPackUI\SecureUploads\Services\Scanners\NullScanner;
use ArtisanPackUI\SecureUploads\Services\Scanners\VirusTotalScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ScannerTest extends TestCase
{
    #[Test]
    public function null_scanner_returns_clean_result(): void
    {
        $scanner = new NullScanner();
        $file    = UploadedFile::fake()->image( 'test.jpg' );

        $result = $scanner->scan( $file->getPathname() );

        $this->assertTrue( $result->isClean() );
        $this->assertEquals( ScanResult::STATUS_CLEAN, $result->status );
    }

    #[Test]
    public function null_scanner_is_always_available(): void
    {
        $scanner = new NullScanner();

        $this->assertTrue( $scanner->isAvailable() );
        $this->assertEquals( 'null', $scanner->getName() );
    }

    #[Test]
    public function clamav_scanner_returns_error_when_unavailable(): void
    {
        Config::set( 'artisanpack.secure-uploads.malwareScanning.clamav.socketPath', '/nonexistent/socket' );
        Config::set( 'artisanpack.secure-uploads.malwareScanning.clamav.binaryPath', '/nonexistent/clamscan' );

        $scanner = new ClamAvScanner();

        $this->assertFalse( $scanner->isAvailable() );
        $this->assertEquals( 'clamav', $scanner->getName() );
    }

    #[Test]
    public function virustotal_scanner_requires_api_key(): void
    {
        Config::set( 'artisanpack.secure-uploads.malwareScanning.virustotal.apiKey', '' );

        $scanner = new VirusTotalScanner();

        $this->assertFalse( $scanner->isAvailable() );
        $this->assertEquals( 'virustotal', $scanner->getName() );
    }

    #[Test]
    public function virustotal_scanner_is_available_with_api_key(): void
    {
        Config::set( 'artisanpack.secure-uploads.malwareScanning.virustotal.apiKey', 'test-api-key' );

        $scanner = new VirusTotalScanner();

        $this->assertTrue( $scanner->isAvailable() );
    }

    #[Test]
    public function virustotal_scanner_returns_null_for_hash_not_found(): void
    {
        Config::set( 'artisanpack.secure-uploads.malwareScanning.virustotal.apiKey', 'test-api-key' );

        Http::fake( [
            'https://www.virustotal.com/api/v3/files/*' => Http::response( [
                'error' => ['code' => 'NotFoundError'],
            ], 404 ),
        ] );

        $scanner = new VirusTotalScanner();

        // Test the hash lookup directly (returns null when file not in VT database)
        $result = $scanner->scanByHash( 'abc123fake' );

        $this->assertNull( $result ); // null means file not found, need to upload
    }

    #[Test]
    public function virustotal_scanner_returns_infected_when_malware_detected(): void
    {
        Config::set( 'artisanpack.secure-uploads.malwareScanning.virustotal.apiKey', 'test-api-key' );

        Http::fake( [
            'https://www.virustotal.com/api/v3/files/*' => Http::response( [
                'data' => [
                    'attributes' => [
                        'last_analysis_stats' => [
                            'malicious'  => 5,
                            'suspicious' => 2,
                        ],
                        'last_analysis_results' => [
                            'engine1' => ['result' => 'Trojan.Generic'],
                        ],
                    ],
                ],
            ], 200 ),
        ] );

        $scanner = new VirusTotalScanner();
        $file    = UploadedFile::fake()->image( 'test.jpg' );

        $result = $scanner->scan( $file->getPathname() );

        $this->assertTrue( $result->isInfected() );
        $this->assertNotNull( $result->threatName );
    }

    #[Test]
    public function virustotal_scanner_returns_clean_when_no_threats(): void
    {
        Config::set( 'artisanpack.secure-uploads.malwareScanning.virustotal.apiKey', 'test-api-key' );

        Http::fake( [
            'https://www.virustotal.com/api/v3/files/*' => Http::response( [
                'data' => [
                    'attributes' => [
                        'last_analysis_stats' => [
                            'malicious'  => 0,
                            'suspicious' => 0,
                        ],
                    ],
                ],
            ], 200 ),
        ] );

        $scanner = new VirusTotalScanner();
        $file    = UploadedFile::fake()->image( 'test.jpg' );

        $result = $scanner->scan( $file->getPathname() );

        $this->assertTrue( $result->isClean() );
    }

    #[Test]
    public function scanner_resolves_based_on_config(): void
    {
        Config::set( 'artisanpack.secure-uploads.malwareScanning.driver', 'null' );

        $scanner = app( MalwareScannerInterface::class );

        $this->assertInstanceOf( NullScanner::class, $scanner );
    }

    #[Test]
    public function scan_result_factory_methods_work(): void
    {
        $clean = ScanResult::clean( 'test-scanner' );
        $this->assertTrue( $clean->isClean() );
        $this->assertEquals( 'test-scanner', $clean->scannerName );

        $infected = ScanResult::infected( 'Trojan.Generic', 'test-scanner' );
        $this->assertTrue( $infected->isInfected() );
        $this->assertEquals( 'Trojan.Generic', $infected->threatName );

        $error = ScanResult::error( 'Connection failed', 'test-scanner' );
        $this->assertTrue( $error->hasError() );
        $this->assertEquals( 'Connection failed', $error->metadata['error'] );

        $pending = ScanResult::pending();
        $this->assertTrue( $pending->isPending() );
    }

    #[Test]
    public function scan_result_can_convert_to_and_from_array(): void
    {
        $original = ScanResult::infected( 'Trojan.Test', 'test-scanner', ['key' => 'value'] );

        $array    = $original->toArray();
        $restored = ScanResult::fromArray( $array );

        $this->assertEquals( $original->status, $restored->status );
        $this->assertEquals( $original->threatName, $restored->threatName );
        $this->assertEquals( $original->scannerName, $restored->scannerName );
        $this->assertEquals( $original->metadata, $restored->metadata );
    }

    protected function getPackageProviders( $app )
    {
        return [
            \ArtisanPackUI\SecureUploads\SecureUploadsServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp( $app ): void
    {
        Config::set( 'artisanpack.secure-uploads.enabled', true );
        Config::set( 'artisanpack.secure-uploads.malwareScanning.enabled', true );
        Config::set( 'artisanpack.secure-uploads.malwareScanning.driver', 'null' );
    }
}
