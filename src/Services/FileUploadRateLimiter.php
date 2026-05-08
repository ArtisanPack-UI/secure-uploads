<?php

declare( strict_types=1 );

namespace ArtisanPackUI\SecureUploads\Services;

use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Auth\Authenticatable;
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

        // Atomically reserve hourly size budget. Done before hitting the
        // per-minute/per-hour counters so a size-rejected upload doesn't
        // burn the request quota.
        if ( $fileSize > 0 && ! $this->reserveHourlySize( $request, $fileSize ) ) {
            return false;
        }

        // Increment counters
        $this->limiter->hit( $perMinuteKey, 60 );
        $this->limiter->hit( $perHourKey, 3600 );

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

        // Return the longest active wait — `min()` would underreport when
        // only the per-minute window has reset but the hourly cap is still
        // blocking.
        return max( $minuteAvailable, $hourAvailable );
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
        Cache::forget( $key . ':size:reset' );
    }

    /**
     * Generate the rate limit key for a request.
     *
     * Uses the Authenticatable contract's getAuthIdentifier() rather than
     * a raw ->id property so apps with non-standard primary keys (UUIDs,
     * username-as-id, etc.) still produce a stable cache key.
     */
    protected function getKey( Request $request ): string
    {
        $user       = $request->user();
        $identifier = $user instanceof Authenticatable
            ? $user->getAuthIdentifier()
            : $request->ip();

        return 'upload_limit:' . $identifier;
    }

    /**
     * Atomically reserve hourly upload-size budget.
     *
     * Combines the read-modify-write that previously lived in
     * checkSizeLimit() + incrementSizeTracking() into a single atomic
     * Cache::increment so two concurrent uploads can't both pass a check
     * that would jointly overflow the hourly cap. On stores that don't
     * support increment (e.g. file/array drivers in tests), the call
     * returns false and we fall back to the non-atomic path — acceptable
     * because those drivers aren't used in concurrent production.
     *
     * @return bool true if the budget was reserved, false if it would exceed the cap
     */
    protected function reserveHourlySize( Request $request, int $fileSize ): bool
    {
        $config         = config( 'artisanpack.secure-uploads.rateLimiting', [] );
        $maxSizePerHour = $config['maxTotalSizePerHour'] ?? ( 100 * 1024 * 1024 );

        $key      = $this->getKey( $request ) . ':size';
        $resetKey = $key . ':reset';

        // Pin the window's deadline once, when the counter is first created,
        // so subsequent fallback updates can preserve the original TTL
        // rather than sliding it forward on every write.
        $windowResetAt = now()->addHour()->getTimestamp();
        Cache::add( $key, 0, now()->addHour() );
        Cache::add( $resetKey, $windowResetAt, now()->addHour() );

        $newTotal = Cache::increment( $key, $fileSize );

        if ( false === $newTotal ) {
            // Driver doesn't support atomic increment — fall back, but
            // keep the original window deadline so this isn't effectively
            // a sliding window.
            $current = (int) Cache::get( $key, 0 );
            if ( ( $current + $fileSize ) > $maxSizePerHour ) {
                return false;
            }

            $resetAt = (int) Cache::get( $resetKey, $windowResetAt );
            $ttl     = max( 1, $resetAt - now()->getTimestamp() );

            Cache::put( $key, $current + $fileSize, now()->addSeconds( $ttl ) );

            return true;
        }

        if ( $newTotal > $maxSizePerHour ) {
            // Roll the reservation back so the next caller sees the real total.
            Cache::decrement( $key, $fileSize );

            return false;
        }

        return true;
    }
}
