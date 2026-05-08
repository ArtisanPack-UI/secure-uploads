<?php

declare( strict_types=1 );

namespace ArtisanPackUI\SecureUploads;

use ArtisanPackUI\SecureUploads\Console\Commands\CleanupExpiredFiles;
use ArtisanPackUI\SecureUploads\Console\Commands\ScanQuarantinedFiles;
use ArtisanPackUI\SecureUploads\Contracts\FileValidatorInterface;
use ArtisanPackUI\SecureUploads\Contracts\MalwareScannerInterface;
use ArtisanPackUI\SecureUploads\Contracts\SecureFileStorageInterface;
use ArtisanPackUI\SecureUploads\Http\Middleware\ScanUploadedFiles;
use ArtisanPackUI\SecureUploads\Http\Middleware\ValidateFileUpload;
use ArtisanPackUI\SecureUploads\Services\FileUploadRateLimiter;
use ArtisanPackUI\SecureUploads\Services\FileValidationService;
use ArtisanPackUI\SecureUploads\Services\Scanners\ClamAvScanner;
use ArtisanPackUI\SecureUploads\Services\Scanners\NullScanner;
use ArtisanPackUI\SecureUploads\Services\Scanners\VirusTotalScanner;
use ArtisanPackUI\SecureUploads\Services\SecureFileStorageService;
use Illuminate\Cache\RateLimiter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the Secure Uploads package.
 *
 * Registers file-validation, malware-scanning, secure-storage, and
 * upload-rate-limiting services; loads the config, migrations, routes,
 * middleware aliases, and console commands.
 */
class SecureUploadsServiceProvider extends ServiceProvider
{
    /**
     * Register container bindings.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/artisanpack/secure-uploads.php',
            'artisanpack.secure-uploads',
        );

        $this->app->singleton( 'secure-uploads', fn () => new SecureUploads() );

        $this->app->singleton( FileValidatorInterface::class, FileValidationService::class );

        $this->app->singleton( MalwareScannerInterface::class, function (): MalwareScannerInterface {
            $driver = config( 'artisanpack.secure-uploads.malwareScanning.driver', 'null' );

            return match ( $driver ) {
                'clamav'     => new ClamAvScanner(),
                'virustotal' => new VirusTotalScanner(),
                default      => new NullScanner(),
            };
        } );

        $this->app->singleton( FileUploadRateLimiter::class, function ( $app ): FileUploadRateLimiter {
            return new FileUploadRateLimiter( $app->make( RateLimiter::class ) );
        } );

        $this->app->singleton( SecureFileStorageInterface::class, function ( $app ): SecureFileStorageInterface {
            return new SecureFileStorageService(
                $app->make( FilesystemManager::class ),
                $app->make( FileValidatorInterface::class ),
                $app->make( MalwareScannerInterface::class ),
            );
        } );
    }

    /**
     * Bootstrap package services.
     */
    public function boot(): void
    {
        $this->publishes( [
            __DIR__ . '/../config/artisanpack/secure-uploads.php'
                => config_path( 'artisanpack/secure-uploads.php' ),
        ], 'secure-uploads-config' );

        $this->loadMigrationsFrom( __DIR__ . '/../database/migrations' );

        $this->loadRoutesFrom( __DIR__ . '/../routes/secure-files.php' );

        $router = $this->app['router'];
        $router->aliasMiddleware( 'validate.upload', ValidateFileUpload::class );
        $router->aliasMiddleware( 'scan.upload', ScanUploadedFiles::class );

        if ( $this->app->runningInConsole() ) {
            $this->commands( [
                CleanupExpiredFiles::class,
                ScanQuarantinedFiles::class,
            ]);
        }
    }
}
