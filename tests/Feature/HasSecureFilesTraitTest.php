<?php

namespace Tests\Feature;

use ArtisanPackUI\SecureUploads\Models\SecureUploadedFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Models\TestModelWithSecureFiles;

class HasSecureFilesTraitTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->artisan( 'migrate', ['--database' => 'testbench'] )->run();

        Storage::fake( 'local' );
    }

    #[Test]
    public function model_can_attach_secure_file(): void
    {
        $model = TestModelWithSecureFiles::create( ['name' => 'Test Model'] );
        $file  = UploadedFile::fake()->image( 'test.jpg', 100, 100 );

        $storedFile = $model->attachSecureFile( $file );

        $this->assertNotNull( $storedFile );
        $this->assertEquals( 'test.jpg', $storedFile->originalName );

        // Check model association
        $this->assertEquals( 1, $model->secureFiles()->count() );

        $secureFile = $model->secureFiles()->first();
        $this->assertEquals( TestModelWithSecureFiles::class, $secureFile->fileable_type );
        $this->assertEquals( $model->id, $secureFile->fileable_id );
    }

    #[Test]
    public function model_can_attach_multiple_files(): void
    {
        $model = TestModelWithSecureFiles::create( ['name' => 'Test Model'] );
        $files = [
            UploadedFile::fake()->image( 'image1.jpg' ),
            UploadedFile::fake()->image( 'image2.jpg' ),
            UploadedFile::fake()->image( 'image3.jpg' ),
        ];

        $storedFiles = $model->attachSecureFiles( $files );

        $this->assertCount( 3, $storedFiles );
        $this->assertEquals( 3, $model->secureFiles()->count() );
    }

    #[Test]
    public function model_can_detach_secure_file(): void
    {
        $model = TestModelWithSecureFiles::create( ['name' => 'Test Model'] );
        $file  = UploadedFile::fake()->image( 'test.jpg' );

        $storedFile = $model->attachSecureFile( $file );

        $this->assertEquals( 1, $model->secureFiles()->count() );

        $result = $model->detachSecureFile( $storedFile->identifier );

        $this->assertTrue( $result );
        $this->assertEquals( 0, $model->secureFiles()->count() );
    }

    #[Test]
    public function model_can_detach_file_without_deleting(): void
    {
        $model = TestModelWithSecureFiles::create( ['name' => 'Test Model'] );
        $file  = UploadedFile::fake()->image( 'test.jpg' );

        $storedFile = $model->attachSecureFile( $file );

        $result = $model->detachSecureFile( $storedFile->identifier, deleteFile: false );

        $this->assertTrue( $result );

        // File should still exist in database but not associated
        $secureFile = SecureUploadedFile::where( 'identifier', $storedFile->identifier )->first();
        $this->assertNotNull( $secureFile );
        $this->assertNull( $secureFile->fileable_type );
        $this->assertNull( $secureFile->fileable_id );
    }

    #[Test]
    public function model_can_detach_all_files(): void
    {
        $model = TestModelWithSecureFiles::create( ['name' => 'Test Model'] );

        $model->attachSecureFile( UploadedFile::fake()->image( 'image1.jpg' ) );
        $model->attachSecureFile( UploadedFile::fake()->image( 'image2.jpg' ) );
        $model->attachSecureFile( UploadedFile::fake()->image( 'image3.jpg' ) );

        $this->assertEquals( 3, $model->secureFiles()->count() );

        $count = $model->detachAllSecureFiles();

        $this->assertEquals( 3, $count );
        $this->assertEquals( 0, $model->secureFiles()->count() );
    }

    #[Test]
    public function model_can_get_files_by_type(): void
    {
        $model = TestModelWithSecureFiles::create( ['name' => 'Test Model'] );

        $model->attachSecureFile( UploadedFile::fake()->image( 'image1.jpg' ) );
        $model->attachSecureFile( UploadedFile::fake()->image( 'image2.jpg' ) );

        $images    = $model->secureImages();
        $documents = $model->secureDocuments();

        $this->assertEquals( 2, $images->count() );
        $this->assertEquals( 0, $documents->count() );
    }

    #[Test]
    public function model_can_check_if_has_secure_files(): void
    {
        $model = TestModelWithSecureFiles::create( ['name' => 'Test Model'] );

        $this->assertFalse( $model->hasSecureFiles() );

        $model->attachSecureFile( UploadedFile::fake()->image( 'test.jpg' ) );

        $this->assertTrue( $model->hasSecureFiles() );
    }

    #[Test]
    public function model_can_get_primary_secure_file(): void
    {
        $model = TestModelWithSecureFiles::create( ['name' => 'Test Model'] );

        $this->assertNull( $model->primarySecureFile() );

        $model->attachSecureFile( UploadedFile::fake()->image( 'first.jpg' ) );
        $model->attachSecureFile( UploadedFile::fake()->image( 'second.jpg' ) );

        $primary = $model->primarySecureFile();

        $this->assertNotNull( $primary );
        $this->assertEquals( 'first.jpg', $primary->original_name );
    }

    #[Test]
    public function model_can_calculate_total_file_size(): void
    {
        $model = TestModelWithSecureFiles::create( ['name' => 'Test Model'] );

        $model->attachSecureFile( UploadedFile::fake()->image( 'image1.jpg' ) );
        $model->attachSecureFile( UploadedFile::fake()->image( 'image2.jpg' ) );

        $totalSize = $model->secureFilesTotalSize();

        $this->assertGreaterThan( 0, $totalSize );
    }

    #[Test]
    public function model_deletes_files_when_force_deleted(): void
    {
        $model      = TestModelWithSecureFiles::create( ['name' => 'Test Model'] );
        $storedFile = $model->attachSecureFile( UploadedFile::fake()->image( 'test.jpg' ) );

        $this->assertEquals( 1, $model->secureFiles()->count() );
        $this->assertDatabaseHas( 'secure_files', ['identifier' => $storedFile->identifier] );

        $model->forceDelete();

        $this->assertDatabaseMissing( 'secure_files', ['identifier' => $storedFile->identifier] );
    }

    #[Test]
    public function secure_file_morph_relationship_works(): void
    {
        $model      = TestModelWithSecureFiles::create( ['name' => 'Test Model'] );
        $storedFile = $model->attachSecureFile( UploadedFile::fake()->image( 'test.jpg' ) );

        $secureFile = SecureUploadedFile::where( 'identifier', $storedFile->identifier )->first();

        $this->assertNotNull( $secureFile->fileable );
        $this->assertInstanceOf( TestModelWithSecureFiles::class, $secureFile->fileable );
        $this->assertEquals( $model->id, $secureFile->fileable->id );
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
            'application/pdf',
        ] );
        Config::set( 'artisanpack.secure-uploads.allowedExtensions', [
            'jpg', 'jpeg', 'png', 'pdf',
        ] );
        Config::set( 'artisanpack.secure-uploads.blockedExtensions', [
            'php', 'exe', 'sh', 'bat',
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

        // Create test_models table
        $app['db']->connection()->getSchemaBuilder()->create( 'test_models', function ( $table ): void {
            $table->id();
            $table->string( 'name' );
            $table->timestamps();
        } );
    }
}
