<?php

declare( strict_types=1 );

namespace ArtisanPackUI\SecureUploads\Console\Commands;

use ArtisanPackUI\SecureUploads\Contracts\MalwareScannerInterface;
use ArtisanPackUI\SecureUploads\Events\MalwareDetected;
use ArtisanPackUI\SecureUploads\FileUpload\RequestContext;
use ArtisanPackUI\SecureUploads\FileUpload\ScanResult;
use ArtisanPackUI\SecureUploads\Models\SecureUploadedFile;
use Illuminate\Console\Command;

class ScanQuarantinedFiles extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'security:scan-quarantine
                            {--limit=100 : Maximum number of files to scan}
                            {--delete-infected : Automatically delete infected files}';

    /**
     * The console command description.
     */
    protected $description = 'Scan quarantined/pending files for malware';

    /**
     * Execute the console command.
     */
    public function handle( MalwareScannerInterface $scanner ): int
    {
        if ( ! $scanner->isAvailable() ) {
            $this->error( 'Malware scanner is not available.' );

            return self::FAILURE;
        }

        $limit          = (int) $this->option( 'limit' );
        $deleteInfected = $this->option( 'delete-infected' );

        $this->info( "Scanning up to {$limit} pending files using {$scanner->getName()}..." );

        $files = SecureUploadedFile::where( function ( $query ): void {
            $query->pendingScan()
                ->orWhere( 'scan_status', ScanResult::STATUS_ERROR );
        } )
            ->orderBy( 'created_at', 'asc' )
            ->limit( $limit )
            ->get();

        if ( $files->isEmpty() ) {
            $this->info( 'No files pending scan.' );

            return self::SUCCESS;
        }

        $this->output->progressStart( $files->count() );

        $stats = [
            'clean'    => 0,
            'infected' => 0,
            'error'    => 0,
        ];

        foreach ( $files as $file ) {
            if ( ! $file->existsInStorage() ) {
                $file->forceDelete();
                $this->output->progressAdvance();

                continue;
            }

            $result = $scanner->scan( $file->getFullPath() );

            if ( $result->isClean() ) {
                $file->markAsClean();
                $stats['clean']++;
            } elseif ( $result->isInfected() ) {
                $file->markAsInfected( $result->threatName ?? 'Unknown' );
                $stats['infected']++;

                // Dispatch event (use empty context for CLI)
                event( new MalwareDetected(
                    $file->original_name,
                    $result,
                    null,
                    RequestContext::empty(),
                ) );

                if ( $deleteInfected ) {
                    $file->deleteFromStorage();
                    $file->forceDelete();
                }
            } else {
                $file->markScanError();
                $stats['error']++;
            }

            $this->output->progressAdvance();
        }

        $this->output->progressFinish();

        $this->newLine();
        $this->table(
            ['Status', 'Count'],
            [
                ['Clean', $stats['clean']],
                ['Infected', $stats['infected']],
                ['Error', $stats['error']],
            ],
        );

        if ( $stats['infected'] > 0 ) {
            $this->warn( "{$stats['infected']} infected file(s) detected!" );

            if ( $deleteInfected ) {
                $this->info( 'Infected files have been deleted.' );
            } else {
                $this->info( 'Run with --delete-infected to automatically remove infected files.' );
            }
        }

        return self::SUCCESS;
    }
}
