---
title: Middleware
---

# Middleware

The service provider registers two middleware aliases for routes that handle uploads directly (without going through `HasSecureFiles::attachSecureFile()`, which runs the same checks internally).

## `validate.upload`

Runs `FileValidationService` over every file in the request before the controller runs.

```php
Route::post('/uploads', [UploadController::class, 'store'])
    ->middleware('validate.upload');
```

Failed validation returns a 422 response with the errors. Successful validation lets the request through unchanged — the controller sees the same `UploadedFile` instances.

## `scan.upload`

Runs the configured malware scanner over every file in the request.

```php
Route::post('/uploads', [UploadController::class, 'store'])
    ->middleware(['validate.upload', 'scan.upload']);
```

Apply in that order — there's no point scanning a file that's going to fail validation. Failed scans return 422 with the `MalwareDetected` info; clean scans let the request through.

## Combined with rate limiting

```php
use ArtisanPackUI\SecureUploads\Services\FileUploadRateLimiter;

Route::post('/uploads', [UploadController::class, 'store'])
    ->middleware([
        'validate.upload',
        'scan.upload',
        FileUploadRateLimiter::middleware(),
    ]);
```

See [Rate limiting](../advanced/rate-limiting.md) for the rate limiter's keying and tuning.

## When to use middleware vs the trait

| Use middleware when... | Use the trait when... |
|---|---|
| You want validation to happen before any controller logic runs | The upload is part of a larger controller flow (validate the form, run business logic, then attach the file) |
| The controller doesn't need a `SecureUploadedFile` row — just wants the raw file | You want the file attached to an Eloquent model and a row in `secure_files` |
| You're integrating with an existing upload controller you don't want to rewrite | You're building a new feature on top of `HasSecureFiles` from scratch |

Both paths run the same checks. The trait does more work (storing the file, creating the row) but gives you a typed result and a relationship for follow-up queries.
