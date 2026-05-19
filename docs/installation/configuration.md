---
title: Configuration
---

# Configuration

Publish the shipped config to override any of the defaults:

```bash
php artisan vendor:publish --tag=secure-uploads-config
```

The published file lives at `config/artisanpack/secure-uploads.php`. Sections, in shipped order:

## Top-level toggle

```php
'enabled' => env('SECURE_UPLOADS_ENABLED', true),
```

Master kill-switch. When `false`, the validation pipeline short-circuits to "allow" — useful for emergency rollbacks. Storage and signed URL serving still work.

## Allow / blocklists

```php
'allowedMimeTypes' => [ /* ... */ ],
'allowedExtensions' => [ /* ... */ ],
'blockedMimeTypes' => [ /* ... */ ],
'blockedExtensions' => [ /* ... */ ],
```

If `allowedMimeTypes` or `allowedExtensions` is non-empty, the pipeline is allowlist-mode for that field. Blocklists are always checked. Block wins over allow when an entry appears in both.

The published file ships with safe defaults for common document and image types; tighten or expand for your use case.

## Size limits

```php
'maxFileSize' => 10 * 1024 * 1024,  // 10 MB
'maxFileSizePerType' => [
    'image/jpeg' => 5 * 1024 * 1024,
    'application/pdf' => 25 * 1024 * 1024,
    // ...
],
```

`maxFileSize` is the absolute ceiling. `maxFileSizePerType` overrides it per MIME type when smaller — useful for "images max 5 MB, PDFs max 25 MB" policies.

## Validation tricks

```php
'validateMimeByContent' => true,    // sniff actual file content via fileinfo
'checkForDoubleExtensions' => true, // catch file.php.jpg
'checkForNullBytes' => true,        // catch file.php%00.jpg
'stripExifData' => true,            // remove EXIF from JPEG / TIFF / PNG
```

Each toggle controls a corresponding check in the validation pipeline. Leave them on in production — these are the protections against the most common upload attacks.

## Malware scanning

```php
'malwareScanning' => [
    'enabled' => env('SECURE_UPLOADS_MALWARE_SCANNING_ENABLED', false),
    'driver' => env('SECURE_UPLOADS_MALWARE_DRIVER', 'null'),
    'failOnScanError' => true,
    'async' => false,
    'quarantinePath' => storage_path('app/quarantine'),
    'clamav' => [
        'socketPath' => env('CLAMAV_SOCKET_PATH', '/var/run/clamav/clamd.sock'),
        'binaryPath' => env('CLAMAV_BINARY_PATH', '/usr/bin/clamscan'),
        'timeout' => 30,
    ],
    'virustotal' => [
        'apiKey' => env('VIRUSTOTAL_API_KEY'),
        'timeout' => 60,
    ],
],
```

| Key | Notes |
|---|---|
| `enabled` | Master toggle for malware scanning. When `false`, even configured scanners are skipped. |
| `driver` | `null` (no-op), `clamav`, or `virustotal`. |
| `failOnScanError` | When `true`, an upload is rejected if the scanner is unavailable or errors. When `false`, the upload proceeds and the error is logged. Default `true` (fail closed). |
| `async` | When `true`, uploads are quarantined and scanned out-of-band by `security:scan-quarantine`. When `false`, the upload waits for the scan result synchronously. |
| `quarantinePath` | Where async-mode files live until cleared. |
| `clamav.*` | Socket path tried first, then binary fallback. Timeout applies to both. |
| `virustotal.*` | API key required for the `virustotal` driver. |

See [Scanner setup](scanners.md) for the operational side.

## Rate limiting

```php
'rateLimiting' => [
    'enabled' => true,
    'attempts' => 60,
    'decayMinutes' => 1,
],
```

Wraps Laravel's `RateLimiter`. The limiter is keyed by user (when authenticated) or IP, scoped per route via the `validate.upload` middleware.

## Disk + path

The shipped config doesn't pin a specific disk — it uses the application's default (`config('filesystems.default')`). To override, pass `disk` and `path` in the `$options` array when calling `attachSecureFile()` or `SecureFileStorageService::store()`:

```php
$post->attachSecureFile($request->file('attachment'), [
    'disk' => 's3-private',
    'path' => 'posts/' . $post->id,
]);
```
