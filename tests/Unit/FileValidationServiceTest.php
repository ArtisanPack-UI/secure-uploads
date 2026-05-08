<?php

namespace Tests\Unit;

use ArtisanPackUI\SecureUploads\Contracts\FileValidatorInterface;
use ArtisanPackUI\SecureUploads\Services\FileValidationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

class FileValidationServiceTest extends TestCase
{
    protected FileValidationService $service;

    public function setUp(): void
    {
        parent::setUp();
        $this->service = new FileValidationService();
    }

    #[Test]
    public function it_validates_allowed_image_file(): void
    {
        $file = UploadedFile::fake()->image( 'test.jpg', 100, 100 );

        $result = $this->service->validate( $file );

        $this->assertTrue( $result->passed );
        $this->assertEmpty( $result->errors );
    }

    #[Test]
    public function it_rejects_blocked_extension(): void
    {
        $file = UploadedFile::fake()->create( 'malware.exe', 100, 'application/x-msdownload' );

        $result = $this->service->validate( $file );

        $this->assertFalse( $result->passed );
        $this->assertTrue(
            collect( $result->errors )->contains( fn ( $e ) => str_contains( $e, 'not allowed for security reasons' ) ),
        );
    }

    #[Test]
    public function it_rejects_files_exceeding_max_size(): void
    {
        Config::set( 'artisanpack.secure-uploads.maxFileSize', 1024 ); // 1KB
        $this->service = new FileValidationService();

        $file = UploadedFile::fake()->create( 'large.pdf', 2048, 'application/pdf' ); // 2KB

        $result = $this->service->validate( $file );

        $this->assertFalse( $result->passed );
        $this->assertTrue(
            collect( $result->errors )->contains( fn ( $e ) => str_contains( $e, 'exceeds the maximum' ) ),
        );
    }

    #[Test]
    public function it_detects_double_extension_attack(): void
    {
        $file = UploadedFile::fake()->create( 'image.php.jpg', 100, 'image/jpeg' );

        $result = $this->service->validate( $file );

        $this->assertFalse( $result->passed );
        $this->assertTrue(
            collect( $result->errors )->contains( fn ( $e ) => str_contains( $e, 'blocked extension' ) ),
        );
    }

    #[Test]
    public function it_detects_null_byte_in_filename(): void
    {
        $filename = "test\x00.jpg";

        $hasNullByte = $this->service->hasUnsafeFilename( $filename );

        $this->assertTrue( $hasNullByte );
    }

    #[Test]
    public function it_detects_path_traversal_in_filename(): void
    {
        $unsafeName = '../../../etc/passwd';

        $hasUnsafe = $this->service->hasUnsafeFilename( $unsafeName );

        $this->assertTrue( $hasUnsafe );
    }

    #[Test]
    public function it_sanitizes_filename(): void
    {
        $unsafeName = 'test<script>alert("xss")</script>.jpg';
        $sanitized  = $this->service->sanitizeFilename( $unsafeName );

        $this->assertStringNotContainsString( '<', $sanitized );
        $this->assertStringNotContainsString( '>', $sanitized );
    }

    #[Test]
    public function it_sanitizes_filename_with_double_dots(): void
    {
        $unsafeName = 'file/../../../etc/passwd.txt';
        $sanitized  = $this->service->sanitizeFilename( $unsafeName );

        $this->assertStringNotContainsString( '../', $sanitized );
    }

    #[Test]
    public function it_checks_extension_allowlist(): void
    {
        $this->assertTrue( $this->service->isExtensionAllowed( 'jpg' ) );
        $this->assertTrue( $this->service->isExtensionAllowed( 'png' ) );
        $this->assertFalse( $this->service->isExtensionAllowed( 'exe' ) );
        $this->assertFalse( $this->service->isExtensionAllowed( 'php' ) );
    }

    #[Test]
    public function it_checks_mime_type_allowlist(): void
    {
        $this->assertTrue( $this->service->isMimeTypeAllowed( 'image/jpeg' ) );
        $this->assertTrue( $this->service->isMimeTypeAllowed( 'image/png' ) );
        $this->assertFalse( $this->service->isMimeTypeAllowed( 'application/x-php' ) );
    }

    #[Test]
    public function it_detects_mime_type_from_content(): void
    {
        $file = UploadedFile::fake()->image( 'test.jpg', 100, 100 );

        $detected = $this->service->detectMimeType( $file );

        $this->assertStringContainsString( 'image/', $detected );
    }

    #[Test]
    public function it_validates_with_custom_max_size_option(): void
    {
        // Using a jpg which is in the allowlist
        $file = UploadedFile::fake()->image( 'test.jpg', 100, 100 );

        $result = $this->service->validate( $file, [
            'maxFileSize' => 10 * 1024 * 1024, // 10MB
        ] );

        $this->assertTrue( $result->passed );
    }

    #[Test]
    public function it_rejects_when_custom_max_size_exceeded(): void
    {
        $file = UploadedFile::fake()->create( 'document.pdf', 2048, 'application/pdf' );

        $result = $this->service->validate( $file, [
            'maxFileSize' => 1024, // 1KB
        ] );

        $this->assertFalse( $result->passed );
    }

    #[Test]
    public function it_returns_sanitized_filename_in_result(): void
    {
        $file = UploadedFile::fake()->image( 'Test File (1).jpg', 100, 100 );

        $result = $this->service->validate( $file );

        $this->assertNotNull( $result->sanitizedFilename );
    }

    #[Test]
    public function it_resolves_from_container(): void
    {
        $service = app( FileValidatorInterface::class );

        $this->assertInstanceOf( FileValidationService::class, $service );
    }

    #[Test]
    public function it_rejects_extension_not_in_allowlist(): void
    {
        $file = UploadedFile::fake()->create( 'document.doc', 100, 'application/msword' );

        $result = $this->service->validate( $file );

        $this->assertFalse( $result->passed );
        $this->assertTrue(
            collect( $result->errors )->contains( fn ( $e ) => str_contains( $e, 'extension is not allowed' ) ),
        );
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
        ] );
        Config::set( 'artisanpack.secure-uploads.maxFileSize', 10 * 1024 * 1024 );
        Config::set( 'artisanpack.secure-uploads.checkForDoubleExtensions', true );
        Config::set( 'artisanpack.secure-uploads.checkForNullBytes', true );
        Config::set( 'artisanpack.secure-uploads.validateMimeByContent', false );
    }
}
