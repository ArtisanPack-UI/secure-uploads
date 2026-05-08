<?php

declare( strict_types=1 );

namespace ArtisanPackUI\SecureUploads\Console\Commands;

use ArtisanPackUI\SecureUploads\Models\SecureUploadedFile;
use Illuminate\Console\Command;

class CleanupExpiredFiles extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'security:cleanup-files
                            {--days=30 : Delete files older than this many days}
                            {--only-infected : Only delete infected files}
                            {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     */
    protected $description = 'Remove expired or old uploaded files';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days         = (int) $this->option( 'days' );
        $onlyInfected = $this->option( 'only-infected' );
        $dryRun       = $this->option( 'dry-run' );

        $cutoffDate = now()->subDays( $days );

        $this->info( "Finding files older than {$days} days (before {$cutoffDate->toDateString()})..." );

        $query = SecureUploadedFile::where( 'created_at', '<', $cutoffDate );

        if ( $onlyInfected ) {
            $query->infected();
        }

        $files = $query->get();

        if ( $files->isEmpty() ) {
            $this->info( 'No files to clean up.' );

            return self::SUCCESS;
        }

        $this->info( "Found {$files->count()} file(s) to process." );

        if ( $dryRun ) {
            $this->warn( 'DRY RUN - No files will be deleted.' );
            $this->newLine();

            $this->table(
                ['ID', 'Original Name', 'Size', 'Created At', 'Scan Status'],
                $files->map( fn ( $file ) => [
                    $file->id,
                    $file->original_name,
                    $file->humanFileSize(),
                    $file->created_at->toDateTimeString(),
                    $file->scan_status,
                ] )->toArray(),
            );

            return self::SUCCESS;
        }

        if ( ! $this->confirm( "Are you sure you want to delete {$files->count()} file(s)?" ) ) {
            $this->info( 'Operation cancelled.' );

            return self::SUCCESS;
        }

        $this->output->progressStart( $files->count() );

        $deleted   = 0;
        $totalSize = 0;

        foreach ( $files as $file ) {
            $totalSize += $file->size;

            // Delete from storage
            $file->deleteFromStorage();

            // Force delete from database (bypass soft deletes)
            $file->forceDelete();

            $deleted++;
            $this->output->progressAdvance();
        }

        $this->output->progressFinish();

        $this->newLine();
        $this->info( "Deleted {$deleted} file(s), freed " . $this->formatBytes( $totalSize ) . ' of storage.' );

        return self::SUCCESS;
    }

    /**
     * Format bytes to human-readable string.
     */
    protected function formatBytes( int $bytes ): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ( $i = 0; $bytes > 1024 && $i < count( $units ) - 1; $i++ ) {
            $bytes /= 1024;
        }

        return round( $bytes, 2) . ' ' . $units[ $i ];
    }
}
