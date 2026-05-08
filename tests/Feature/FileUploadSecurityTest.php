<?php

namespace Tests\Feature;

use ArtisanPackUI\SecureUploads\Contracts\MalwareScannerInterface;
use ArtisanPackUI\SecureUploads\Contracts\SecureFileStorageInterface;
use ArtisanPackUI\SecureUploads\Events\FileUploaded;
use ArtisanPackUI\SecureUploads\Events\FileUploadRejected;
use ArtisanPackUI\SecureUploads\Events\MalwareDetected;
use ArtisanPackUI\SecureUploads\FileUpload\ScanResult;
use ArtisanPackUI\SecureUploads\Models\SecureUploadedFile;
use ArtisanPackUI\SecureUploads\Services\FileUploadRateLimiter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

class FileUploadSecurityTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->artisan( 'migrate', ['--database' => 'testbench'] )->run();

        Storage::fake( 'local' );
    }

    #[Test]
    public function validate_upload_middleware_allows_valid_files(): void
    {
        Route::post( '/upload', function () {
            return response()->json( ['success' => true] );
        } )->middleware( 'validate.upload' );

        $file = UploadedFile::fake()->image( 'test.jpg', 100, 100 );

        $response = $this->postJson( '/upload', [
            'file' => $file,
        ] );

        $response->assertStatus( 200 );
    }

    #[Test]
    public function validate_upload_middleware_rejects_invalid_extension(): void
    {
        Event::fake();

        Route::post( '/upload', function () {
            return response()->json( ['success' => true] );
        } )->middleware( 'validate.upload' );

        $file = UploadedFile::fake()->create( 'malware.exe', 100, 'application/x-msdownload' );

        $response = $this->postJson( '/upload', [
            'file' => $file,
        ] );

        $response->assertStatus( 422 );
        Event::assertDispatched( FileUploadRejected::class );
    }

    #[Test]
    public function validate_upload_middleware_rejects_oversized_files(): void
    {
        Config::set( 'artisanpack.secure-uploads.maxFileSize', 1024 ); // 1KB

        Route::post( '/upload', function () {
            return response()->json( ['success' => true] );
        } )->middleware( 'validate.upload' );

        $file = UploadedFile::fake()->create( 'large.pdf', 2048, 'application/pdf' );

        $response = $this->postJson( '/upload', [
            'file' => $file,
        ] );

        $response->assertStatus( 422 );
    }

    #[Test]
    public function scan_upload_middleware_dispatches_malware_event_on_detection(): void
    {
        Event::fake();

        // Mock scanner to return infected result
        $mockScanner = $this->createMock( MalwareScannerInterface::class );
        $mockScanner->method( 'scan' )->willReturn(
            ScanResult::infected( 'Trojan.Generic', 'mock-scanner' ),
        );
        $mockScanner->method( 'isAvailable' )->willReturn( true );
        $this->app->instance( MalwareScannerInterface::class, $mockScanner );

        Route::post( '/upload', function () {
            return response()->json( ['success' => true] );
        } )->middleware( 'scan.upload' );

        $file = UploadedFile::fake()->image( 'infected.jpg' );

        $response = $this->postJson( '/upload', [
            'file' => $file,
        ] );

        $response->assertStatus( 422 );
        Event::assertDispatched( MalwareDetected::class );
    }

    #[Test]
    public function scan_upload_middleware_allows_clean_files(): void
    {
        Route::post( '/upload', function () {
            return response()->json( ['success' => true] );
        } )->middleware( 'scan.upload' );

        $file = UploadedFile::fake()->image( 'clean.jpg' );

        $response = $this->postJson( '/upload', [
            'file' => $file,
        ] );

        $response->assertStatus( 200 );
    }

    #[Test]
    public function storage_service_stores_file_and_creates_record(): void
    {
        Event::fake();

        $storage = app( SecureFileStorageInterface::class );
        $file    = UploadedFile::fake()->image( 'test.jpg', 100, 100 );

        $storedFile = $storage->store( $file );

        $this->assertNotNull( $storedFile );
        $this->assertNotNull( $storedFile->identifier );
        $this->assertEquals( 'test.jpg', $storedFile->originalName );
        $this->assertStringContainsString( 'image/', $storedFile->mimeType );

        // Check database record exists
        $this->assertDatabaseHas( 'secure_files', [
            'identifier'    => $storedFile->identifier,
            'original_name' => 'test.jpg',
        ] );

        Event::assertDispatched( FileUploaded::class );
    }

    #[Test]
    public function storage_service_generates_unique_path(): void
    {
        $storage = app( SecureFileStorageInterface::class );

        $file1 = UploadedFile::fake()->image( 'test.jpg' );
        $file2 = UploadedFile::fake()->image( 'test.jpg' );

        $stored1 = $storage->store( $file1 );
        $stored2 = $storage->store( $file2 );

        $this->assertNotEquals( $stored1->storagePath, $stored2->storagePath );
    }

    #[Test]
    public function storage_service_retrieves_stored_file(): void
    {
        $storage = app( SecureFileStorageInterface::class );
        $file    = UploadedFile::fake()->image( 'test.jpg' );

        $stored    = $storage->store( $file );
        $retrieved = $storage->retrieve( $stored->identifier );

        $this->assertNotNull( $retrieved );
        $this->assertEquals( $stored->identifier, $retrieved->identifier );
    }

    #[Test]
    public function storage_service_deletes_file(): void
    {
        $storage = app( SecureFileStorageInterface::class );
        $file    = UploadedFile::fake()->image( 'test.jpg' );

        $stored = $storage->store( $file );

        $this->assertTrue( $storage->exists( $stored->identifier ) );

        $result = $storage->delete( $stored->identifier );

        $this->assertTrue( $result );
        $this->assertFalse( $storage->exists( $stored->identifier ) );
    }

    #[Test]
    public function secure_uploaded_file_model_has_uuid_identifier(): void
    {
        $storage = app( SecureFileStorageInterface::class );
        $file    = UploadedFile::fake()->image( 'test.jpg' );

        $stored = $storage->store( $file );

        $model = SecureUploadedFile::where( 'identifier', $stored->identifier )->first();

        $this->assertNotNull( $model );
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $model->identifier,
        );
    }

    #[Test]
    public function secure_uploaded_file_model_scopes_work(): void
    {
        // Create files with different scan statuses
        SecureUploadedFile::create( [
            'identifier'    => 'clean-file',
            'original_name' => 'clean.jpg',
            'storage_path'  => 'secure-uploads/clean.jpg',
            'disk'          => 'local',
            'mime_type'     => 'image/jpeg',
            'size'          => 1000,
            'hash'          => hash( 'sha256', 'clean' ),
            'scan_status'   => ScanResult::STATUS_CLEAN,
        ] );

        SecureUploadedFile::create( [
            'identifier'    => 'infected-file',
            'original_name' => 'infected.jpg',
            'storage_path'  => 'secure-uploads/infected.jpg',
            'disk'          => 'local',
            'mime_type'     => 'image/jpeg',
            'size'          => 1000,
            'hash'          => hash( 'sha256', 'infected' ),
            'scan_status'   => ScanResult::STATUS_INFECTED,
            'threat_name'   => 'Trojan.Generic',
        ] );

        SecureUploadedFile::create( [
            'identifier'    => 'pending-file',
            'original_name' => 'pending.jpg',
            'storage_path'  => 'secure-uploads/pending.jpg',
            'disk'          => 'local',
            'mime_type'     => 'image/jpeg',
            'size'          => 1000,
            'hash'          => hash( 'sha256', 'pending' ),
            'scan_status'   => ScanResult::STATUS_PENDING,
        ] );

        $this->assertEquals( 1, SecureUploadedFile::clean()->count() );
        $this->assertEquals( 1, SecureUploadedFile::infected()->count() );
        $this->assertEquals( 1, SecureUploadedFile::pendingScan()->count() );
    }

    #[Test]
    public function secure_uploaded_file_model_detects_file_types(): void
    {
        $image = SecureUploadedFile::create( [
            'identifier'    => 'image-file',
            'original_name' => 'image.jpg',
            'storage_path'  => 'secure-uploads/image.jpg',
            'disk'          => 'local',
            'mime_type'     => 'image/jpeg',
            'size'          => 1000,
            'hash'          => hash( 'sha256', 'image' ),
            'scan_status'   => ScanResult::STATUS_CLEAN,
        ] );

        $video = SecureUploadedFile::create( [
            'identifier'    => 'video-file',
            'original_name' => 'video.mp4',
            'storage_path'  => 'secure-uploads/video.mp4',
            'disk'          => 'local',
            'mime_type'     => 'video/mp4',
            'size'          => 1000,
            'hash'          => hash( 'sha256', 'video' ),
            'scan_status'   => ScanResult::STATUS_CLEAN,
        ] );

        $audio = SecureUploadedFile::create( [
            'identifier'    => 'audio-file',
            'original_name' => 'audio.mp3',
            'storage_path'  => 'secure-uploads/audio.mp3',
            'disk'          => 'local',
            'mime_type'     => 'audio/mpeg',
            'size'          => 1000,
            'hash'          => hash( 'sha256', 'audio' ),
            'scan_status'   => ScanResult::STATUS_CLEAN,
        ] );

        $document = SecureUploadedFile::create( [
            'identifier'    => 'doc-file',
            'original_name' => 'document.pdf',
            'storage_path'  => 'secure-uploads/document.pdf',
            'disk'          => 'local',
            'mime_type'     => 'application/pdf',
            'size'          => 1000,
            'hash'          => hash( 'sha256', 'document' ),
            'scan_status'   => ScanResult::STATUS_CLEAN,
        ] );

        $this->assertTrue( $image->isImage() );
        $this->assertFalse( $image->isVideo() );

        $this->assertTrue( $video->isVideo() );
        $this->assertFalse( $video->isImage() );

        $this->assertTrue( $audio->isAudio() );
        $this->assertFalse( $audio->isImage() );

        $this->assertTrue( $document->isDocument() );
        $this->assertFalse( $document->isImage() );
    }

    #[Test]
    public function secure_uploaded_file_returns_human_readable_size(): void
    {
        $file = SecureUploadedFile::create( [
            'identifier'    => 'test-file',
            'original_name' => 'test.jpg',
            'storage_path'  => 'secure-uploads/test.jpg',
            'disk'          => 'local',
            'mime_type'     => 'image/jpeg',
            'size'          => 1536000, // ~1.5 MB
            'hash'          => hash( 'sha256', 'test' ),
            'scan_status'   => ScanResult::STATUS_CLEAN,
        ] );

        $humanSize = $file->humanFileSize();

        $this->assertStringContainsString( 'MB', $humanSize );
    }

    #[Test]
    public function rate_limiter_limits_uploads_per_minute(): void
    {
        Config::set( 'artisanpack.secure-uploads.rateLimiting.enabled', true );
        Config::set( 'artisanpack.secure-uploads.rateLimiting.maxUploadsPerMinute', 2 );

        $limiter = app( FileUploadRateLimiter::class );

        $request = \Illuminate\Http\Request::create( '/upload', 'POST' );
        $request->setLaravelSession( app( 'session.store' ) );

        $this->assertTrue( $limiter->attempt( $request, 1024 ) );
        $this->assertTrue( $limiter->attempt( $request, 1024 ) );
        $this->assertFalse( $limiter->attempt( $request, 1024 ) );
    }

    #[Test]
    public function rate_limiter_limits_total_size(): void
    {
        Config::set( 'artisanpack.secure-uploads.rateLimiting.enabled', true );
        Config::set( 'artisanpack.secure-uploads.rateLimiting.maxUploadsPerMinute', 100 );
        Config::set( 'artisanpack.secure-uploads.rateLimiting.maxTotalSizePerHour', 5 * 1024 * 1024 ); // 5MB

        $limiter = app( FileUploadRateLimiter::class );

        $request = \Illuminate\Http\Request::create( '/upload', 'POST', [], [], [], ['REMOTE_ADDR' => '192.168.1.1'] );
        $request->setLaravelSession( app( 'session.store' ) );

        $this->assertTrue( $limiter->attempt( $request, 2 * 1024 * 1024 ) ); // 2MB
        $this->assertTrue( $limiter->attempt( $request, 2 * 1024 * 1024 ) ); // 4MB total
        $this->assertFalse( $limiter->attempt( $request, 2 * 1024 * 1024 ) ); // Would exceed 5MB
    }

    #[Test]
    public function cleanup_command_removes_old_files(): void
    {
        // Create an old file
        $oldFile = SecureUploadedFile::create( [
            'identifier'    => 'old-file',
            'original_name' => 'old.jpg',
            'storage_path'  => 'secure-uploads/old.jpg',
            'disk'          => 'local',
            'mime_type'     => 'image/jpeg',
            'size'          => 1000,
            'hash'          => hash( 'sha256', 'old' ),
            'scan_status'   => ScanResult::STATUS_CLEAN,
            'created_at'    => now()->subDays( 60 ),
        ] );

        // Create a recent file
        $recentFile = SecureUploadedFile::create( [
            'identifier'    => 'recent-file',
            'original_name' => 'recent.jpg',
            'storage_path'  => 'secure-uploads/recent.jpg',
            'disk'          => 'local',
            'mime_type'     => 'image/jpeg',
            'size'          => 1000,
            'hash'          => hash( 'sha256', 'recent' ),
            'scan_status'   => ScanResult::STATUS_CLEAN,
        ] );

        $this->artisan( 'security:cleanup-files', ['--days' => 30, '--dry-run' => true] )
            ->assertExitCode( 0 );

        // With dry-run, files should still exist
        $this->assertDatabaseHas( 'secure_files', ['identifier' => 'old-file'] );
        $this->assertDatabaseHas( 'secure_files', ['identifier' => 'recent-file'] );
    }

    #[Test]
    public function secure_file_validation_rule_works(): void
    {
        $file = UploadedFile::fake()->image( 'test.jpg' );

        $validator = \Illuminate\Support\Facades\Validator::make(
            ['file' => $file],
            ['file' => ['required', new \ArtisanPackUI\SecureUploads\Rules\SecureFile()]],
        );

        $this->assertTrue( $validator->passes() );
    }

    #[Test]
    public function secure_file_validation_rule_rejects_invalid_types(): void
    {
        $file = UploadedFile::fake()->create( 'script.php', 100, 'text/x-php' );

        $validator = \Illuminate\Support\Facades\Validator::make(
            ['file' => $file],
            ['file' => ['required', new \ArtisanPackUI\SecureUploads\Rules\SecureFile()]],
        );

        $this->assertFalse( $validator->passes() );
    }

    #[Test]
    public function safe_filename_validation_rule_works(): void
    {
        $safeValidator = \Illuminate\Support\Facades\Validator::make(
            ['filename' => 'document.pdf'],
            ['filename' => ['required', new \ArtisanPackUI\SecureUploads\Rules\SafeFilename()]],
        );

        $this->assertTrue( $safeValidator->passes() );

        $unsafeValidator = \Illuminate\Support\Facades\Validator::make(
            ['filename' => '../../../etc/passwd'],
            ['filename' => ['required', new \ArtisanPackUI\SecureUploads\Rules\SafeFilename()]],
        );

        $this->assertFalse( $unsafeValidator->passes() );
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
        Config::set( 'artisanpack.secure-uploads.allowedMimeTypes', [
            'image/jpeg',
            'image/png',
            'image/gif',
            'application/pdf',
        ] );
        Config::set( 'artisanpack.secure-uploads.allowedExtensions', [
            'jpg', 'jpeg', 'png', 'gif', 'pdf',
        ] );
        Config::set( 'artisanpack.secure-uploads.blockedExtensions', [
            'php', 'exe', 'sh', 'bat',
        ] );
        Config::set( 'artisanpack.secure-uploads.blockedMimeTypes', [
            'application/x-msdownload',
            'application/x-php',
            'text/x-php',
        ] );
        Config::set( 'artisanpack.secure-uploads.maxFileSize', 10 * 1024 * 1024 );
        Config::set( 'artisanpack.secure-uploads.validateMimeByContent', false );
        Config::set( 'artisanpack.secure-uploads.malwareScanning.enabled', true );
        Config::set( 'artisanpack.secure-uploads.malwareScanning.driver', 'null' );
        Config::set( 'artisanpack.secure-uploads.storage.disk', 'local' );
        Config::set( 'artisanpack.secure-uploads.storage.path', 'secure-uploads' );
        Config::set( 'artisanpack.secure-uploads.rateLimiting.enabled', false );

        Config::set( 'database.default', 'testbench' );
        Config::set( 'database.connections.testbench', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ] );

        Config::set( 'filesystems.disks.local', [
            'driver' => 'local',
            'root'   => storage_path( 'app' ),
        ] );

        // Create users table for 2FA migration
        $app['db']->connection()->getSchemaBuilder()->create( 'users', function ( $table ): void {
            $table->id();
            $table->string( 'name' );
            $table->string( 'email' );
            $table->string( 'password' );
            $table->timestamps();
        } );
    }
}
