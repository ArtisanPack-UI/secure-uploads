---
title: Troubleshooting
---

# Troubleshooting

## `View [secure-file] not found` on the show route

The package doesn't ship views — `SecureFileController` returns the file contents directly (image / PDF / download) rather than rendering a view. If you're seeing this error, you've probably accidentally registered a route that uses a controller alias that conflicts with the package's. Inspect the route via `php artisan route:list --name=secure-file` and confirm the action points at `ArtisanPackUI\SecureUploads\Http\Controllers\SecureFileController`.

## Signed URLs always return 403

Three common causes:

1. **The URL expired.** Default expiration is short — generate a fresh URL each request rather than caching them.
2. **`APP_KEY` changed.** Signed URLs are tied to the application key. Rotating `APP_KEY` invalidates every issued URL.
3. **Trusted proxies aren't set.** Laravel's `signed` middleware verifies against the absolute URL it sees, which can differ from what the client requested when behind a load balancer / reverse proxy. Set `TRUSTED_PROXIES` properly.

## `clamscan: command not found` or socket errors

The ClamAV scanner expects either a Unix socket (`/var/run/clamav/clamd.sock` by default) or the `clamscan` binary on PATH. Verify with:

```bash
clamscan --version
ls -la /var/run/clamav/clamd.sock
```

If the socket exists but errors on connect, the user running PHP (often `www-data`) isn't in the `clamav` group. Add them and restart PHP-FPM. Or set the daemon's socket permissions to be world-readable.

If neither socket nor binary works, the scanner's `isAvailable()` returns `false`. With `failOnScanError = true`, every upload is rejected. Either restore ClamAV, set the driver to `null` temporarily, or flip `failOnScanError` to `false` (and accept the security risk).

## VirusTotal returns 429 / quota exceeded

The free tier is rate-limited to ~4 requests per minute. Production volume needs the paid tier or a different scanner. Symptoms:

- Uploads fail with scanner-error status when `failOnScanError = true`
- The quarantine queue grows when `async = true`

Short-term: switch driver to `clamav` or `null` while you sort out a paid VirusTotal tier or a hash-cache layer in front of the scanner.

## Uploads work locally but fail in production with `RuntimeException: Disk not found`

You're either:

- Passing a `disk` option to `attachSecureFile()` that doesn't exist in production's `config/filesystems.php`, or
- Relying on the default disk (`config('filesystems.default')`) and production has a different default than dev (e.g. `local` locally, `s3` in production).

Hardcode the disk in your call or set up matching disks across environments.

## Quarantine queue isn't draining

Check three things:

1. **Is the scheduler running?** `php artisan schedule:work` or your cron entry.
2. **Is `security:scan-quarantine` actually scheduled?** Inspect `app/Console/Kernel.php`.
3. **Is the scanner returning errors instead of clean/infected?** Files in `scan_status = 'error'` are retried but never resolved without a working scanner. Run the command manually and watch the output.

## `FileUploaded` event listeners aren't firing

The event fires after successful storage. If you're not seeing it:

- For sync mode, the upload must have completed (validation passed, scan returned clean, file written, DB row created). Validation failure fires `FileUploadRejected` instead; scan failure fires `MalwareDetected`.
- For async mode, `FileUploaded` fires from the `security:scan-quarantine` worker — not from the original request. Listen on the queue connection that runs the command.

## Large files time out before scanning completes

Bump the scanner timeout:

```php
'malwareScanning' => [
    'clamav' => ['timeout' => 120, /* ... */],
    'virustotal' => ['timeout' => 300, /* ... */],
],
```

For very large files, prefer async mode regardless of scanner speed.

## EXIF stripping isn't removing GPS coordinates

The shipped stripping handles JPEG / TIFF / PNG. Some formats (HEIC, AVIF, RAW) aren't covered. For full coverage, install ImageMagick / exiftool and either:

- Override `FileValidationService::stripExifData()` in a subclass to call `exiftool -overwrite_original -all=`
- Or run a separate pass after upload using ImageMagick's stripping

The shipped stripping is best-effort against the common formats; treat it as a baseline, not a guarantee.

## Still stuck?

Open an issue at https://github.com/ArtisanPack-UI/secure-uploads/issues with:

- PHP and Laravel versions
- Scanner driver and its install method
- The relevant code path (Form Request, controller call to `attachSecureFile()`, route registration)
- Exact error / output, including any stack trace
- Storage driver in use (`local` / `s3` / etc.)
