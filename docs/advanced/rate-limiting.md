---
title: Rate Limiting
---

# Rate Limiting

`FileUploadRateLimiter` wraps Laravel's `RateLimiter` with sensible defaults for upload endpoints. It's exposed as a service you can inject — and as middleware you can apply to routes.

## Defaults

```php
'rateLimiting' => [
    'enabled' => true,
    'attempts' => 60,
    'decayMinutes' => 1,
],
```

60 uploads per minute, keyed per user when authenticated, per IP otherwise. Override per-route by passing different options to the middleware factory.

## Apply as middleware

```php
use ArtisanPackUI\SecureUploads\Services\FileUploadRateLimiter;

Route::post('/uploads', [UploadController::class, 'store'])
    ->middleware([
        'validate.upload',
        'scan.upload',
        FileUploadRateLimiter::middleware(),
    ]);
```

The middleware factory accepts overrides:

```php
FileUploadRateLimiter::middleware(attempts: 10, decayMinutes: 1);   // 10 per minute
FileUploadRateLimiter::middleware(attempts: 100, decayMinutes: 60); // 100 per hour
```

Rate-limited requests get a 429 with a `Retry-After` header.

## Use the service directly

When you need to gate something that isn't a route — a background job, a webhook handler, an internal queue worker:

```php
use ArtisanPackUI\SecureUploads\Services\FileUploadRateLimiter;

$limiter = app(FileUploadRateLimiter::class);

if (! $limiter->canUpload($user)) {
    abort(429);
}

$post->attachSecureFile($file);
$limiter->hit($user);   // record the attempt
```

`canUpload()` returns false when the user has hit the limit. `hit()` records the attempt against the configured window.

## Keying

The limiter keys by:

1. Authenticated user ID, when available.
2. IP address otherwise.
3. A custom key, when you pass one to `canUpload(key: 'tenant:42')`.

The third form is useful for tenant-scoped quotas where IP and user aren't the right granularity.

## Tuning

- **Authenticated, low-volume admin tool.** Bump to 100 per minute or per hour — admin users don't deserve aggressive throttling.
- **Public anonymous uploads.** Drop to 5 per minute per IP. Pair with CAPTCHA on the form for additional defense.
- **API uploads.** Match the rest of your API's rate limit conventions. Often a higher per-minute limit (200+) plus a per-day cap.
- **Async / batch processors.** Bypass the limiter entirely — they're not subject to abuse vectors.

## Combining with Laravel's default rate limiter

Don't double-throttle. If your route already has Laravel's `throttle` middleware applied, drop the `FileUploadRateLimiter::middleware()` and configure the throttle key to match the upload key scheme.

```php
// RouteServiceProvider
RateLimiter::for('uploads', function (Request $request) {
    return Limit::perMinute(60)
        ->by(optional($request->user())->id ?: $request->ip());
});

// Route
Route::post('/uploads', ...)->middleware(['throttle:uploads', 'validate.upload', 'scan.upload']);
```

Either approach is fine — pick the one that fits your project conventions.
