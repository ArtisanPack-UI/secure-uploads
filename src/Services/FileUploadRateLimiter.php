<?php

declare( strict_types=1 );

namespace ArtisanPackUI\SecureUploads\Services;

use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class FileUploadRateLimiter
{
    /**
     * Create a new file upload rate limiter instance.
     */
    public function __construct(
        protected RateLimiter $limiter,
    ) {
    }

    /**
     * Attempt to increment the rate limit counters.
     *
     * @return bool True if within limits, false if rate limited
     */
    public function attempt( Request $request, int $fileSize = 0 ): bool
    {
        $config = config( 'artisanpack.secure-uploads.rateLimiting', [] );

        if ( ! ( $config['enabled'] ?? true ) ) {
            return true;
        }

        $key = $this->getKey( $request );

        // Check per-minute limit
        $perMinuteKey = $key . ':minute';
        $maxPerMinute = $config['maxUploadsPerMinute'] ?? 10;

        if ( $this->limiter->tooManyAttempts( $perMinuteKey, $maxPerMinute ) ) {
            return false;
        }

        // Check per-hour limit
        $perHourKey = $key . ':hour';
        $maxPerHour = $config['maxUploadsPerHour'] ?? 100;

        if ( $this->limiter->tooManyAttempts( $perHourKey, $maxPerHour ) ) {
            return false;
        }

        // Check total size limit per hour
        if ( ! $this->checkSizeLimit( $request, $fileSize ) ) {
            return false;
        }

        // Increment counters
        $this->limiter->hit( $perMinuteKey, 60 );
        $this->limiter->hit( $perHourKey, 3600 );

        // Track size
        if ( $fileSize > 0 ) {
            $this->incrementSizeTracking( $request, $fileSize );
        }

        return true;
    }

    /**
     * Check if the request has exceeded rate limits.
     */
    public function tooManyAttempts( Request $request ): bool
    {
        $config = config( 'artisanpack.secure-uploads.rateLimiting', [] );

        if ( ! ( $config['enabled'] ?? true ) ) {
            return false;
        }

        $key = $this->getKey( $request );

        $perMinuteKey = $key . ':minute';
        $maxPerMinute = $config['maxUploadsPerMinute'] ?? 10;

        if ( $this->limiter->tooManyAttempts( $perMinuteKey, $maxPerMinute ) ) {
            return true;
        }

        $perHourKey = $key . ':hour';
        $maxPerHour = $config['maxUploadsPerHour'] ?? 100;

        return $this->limiter->tooManyAttempts( $perHourKey, $maxPerHour );
    }

    /**
     * Get the number of seconds until rate limit resets.
     */
    public function availableIn( Request $request ): int
    {
        $key = $this->getKey( $request );

        $minuteAvailable = $this->limiter->availableIn( $key . ':minute' );
        $hourAvailable   = $this->limiter->availableIn( $key . ':hour' );

        return min( $minuteAvailable, $hourAvailable );
    }

    /**
     * Get the remaining number of attempts for per-minute limit.
     */
    public function remainingAttempts( Request $request ): int
    {
        $config       = config( 'artisanpack.secure-uploads.rateLimiting', [] );
        $key          = $this->getKey( $request );
        $maxPerMinute = $config['maxUploadsPerMinute'] ?? 10;

        return $this->limiter->remaining( $key . ':minute', $maxPerMinute );
    }

    /**
     * Clear all rate limits for a request/user.
     */
    public function clear( Request $request ): void
    {
        $key = $this->getKey( $request );

        $this->limiter->clear( $key . ':minute' );
        $this->limiter->clear( $key . ':hour' );
        Cache::forget( $key . ':size' );
    }

    /**
     * Generate the rate limit key for a request.
     */
    protected function getKey( Request $request ): string
    {
        $identifier = $request->user()?->id ?? $request->ip();

        return 'upload_limit:' . $identifier;
    }

    /**
     * Check if the upload size is within hourly limits.
     */
    protected function checkSizeLimit( Request $request, int $fileSize ): bool
    {
        $config         = config( 'artisanpack.secure-uploads.rateLimiting', [] );
        $maxSizePerHour = $config['maxTotalSizePerHour'] ?? ( 100 * 1024 * 1024 );

        $key         = $this->getKey( $request ) . ':size';
        $currentSize = (int) Cache::get( $key, 0 );

        return ( $currentSize + $fileSize ) <= $maxSizePerHour;
    }

    /**
     * Increment the size tracking for a request/user.
     */
    protected function incrementSizeTracking( Request $request, int $fileSize ): void
    {
        $key         = $this->getKey( $request ) . ':size';
        $currentSize = (int) Cache::get( $key, 0 );

        Cache::put( $key, $currentSize + $fileSize, now()->addHour());
    }
}
