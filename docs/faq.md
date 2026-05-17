---
title: FAQ
---

# FAQ

## Does this package require `artisanpack-ui/security`?

No. Secure Uploads is standalone — it pulls in only `artisanpack-ui/core` and `symfony/mime` from outside Laravel. Install it in any Laravel app or package.

## Can I use it without malware scanning?

Yes. The `null` scanner ships as the default driver. The package still does MIME sniffing, extension checks, size limits, filename sanitization, and EXIF stripping — those run independently of scanning.

## Does the file get served directly from S3, or proxied through Laravel?

Through Laravel. The shipped `SecureFileController::show()` and `download()` routes read the file from the configured disk and stream it through the response. This adds latency vs S3 signed URLs but keeps the access control in your application and lets you audit access via the `FileServed` event.

If you need S3 signed URLs instead — fewer hops, no Laravel in the request path — generate them in your own controller and bypass the bundled routes. You give up the audit trail and the `scan_status` gating in exchange.

## Can I use this with multiple disks?

Yes. Pass `disk` in the `$options` array on `attachSecureFile()` or `SecureFileStorageService::store()`:

```php
$post->attachSecureFile($file, ['disk' => 's3-private-1']);
```

The `disk` is persisted on the `SecureUploadedFile` row, so retrievals find the right disk automatically.

## How big a file can it handle?

The validator enforces `maxFileSize` (default 10 MB). Bump it in config for larger files. The validation pipeline streams from disk rather than loading into memory, so multi-GB files are fine for validation.

For the storage step, the limiting factor is the underlying disk driver — S3 supports multipart uploads up to 5 TB per object, local disk is bounded by your filesystem.

## What about HEIC, AVIF, and other modern formats?

The package ships with the common image MIME types in `allowedMimeTypes`. HEIC and AVIF aren't in the defaults — add them via published config if you need them:

```php
'allowedMimeTypes' => [
    // ... defaults
    'image/heic', 'image/heif',
    'image/avif',
],
```

EXIF stripping is currently JPEG / TIFF / PNG only. HEIC EXIF stripping isn't bundled; if you need it, override `FileValidationService::stripExifData()` in a subclass.

## How do I rotate the signing key?

`URL::signedRoute()` uses Laravel's `APP_KEY`. Rotating `APP_KEY` invalidates every existing signed URL — your users will see 403s on previously-issued links. Plan rotation around regenerating any embedded URLs (re-render templates, re-issue emails).

If you need rolling rotation, build your own signed URL scheme in your controller — the package routes are signed-by-default but you can mark them unsigned and apply your own middleware.

## What happens to quarantined files long-term?

Nothing automatic — they stay in the quarantine path until you delete them. `security:cleanup-files` doesn't touch quarantined files (intentional; quarantined files are evidence). Build your own retention policy when needed:

```php
SecureUploadedFile::where('scan_status', 'infected')
    ->where('created_at', '<', now()->subDays(90))
    ->each(fn ($file) => $storage->delete($file->identifier));
```

## Does it work with Inertia or API uploads?

Yes. The validation rules and the `HasSecureFiles` trait don't care what handles the request. Use the rules in your Form Request and call `attachSecureFile()` from your controller as normal.

For API uploads, you'll typically generate signed URLs and return them in the JSON response rather than redirecting to the show route.

## How does this compare to `spatie/laravel-medialibrary`?

Different scope.

- **Spatie's media library** is feature-rich for image manipulation, conversions, responsive images, and collections. Less opinionated about security.
- **Secure Uploads** focuses on the security path — validation, malware scanning, signed serving. No image conversion, no responsive variants.

They're not mutually exclusive. Some projects use Spatie's library for image conversions while running uploaded files through Secure Uploads' validation rules in the Form Request.

## Why not just use Laravel's built-in `Rule::file()`?

Laravel's file validation covers basic shape — MIME type by claim, size, mimes vs mimetypes. Secure Uploads adds:

- MIME validation against actual file content (not just the claimed type)
- Double-extension and null-byte trick detection
- EXIF stripping
- Malware scanning
- Signed-URL serving with audit trail

For low-risk uploads (e.g. avatars on a small app), built-in validation may be enough. For anything user-facing or regulated, the extra layers earn their keep.
