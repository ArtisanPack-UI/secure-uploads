---
title: Secure Storage
---

# Secure Storage

`SecureFileStorageService` (bound to `SecureFileStorageInterface`) is the entry point for storing and retrieving files. Most callers go through `HasSecureFiles::attachSecureFile()` rather than touching the service directly.

## Store

```php
use ArtisanPackUI\SecureUploads\Contracts\SecureFileStorageInterface;

$storage = app(SecureFileStorageInterface::class);
$stored = $storage->store($request->file('attachment'), [
    'disk' => 's3-private',           // optional — default config('filesystems.default')
    'path' => 'posts/' . $post->id,    // optional — default 'secure-files'
    'owner' => $post,                  // optional — links to a model via morph
    'metadata' => ['source' => 'cms'], // optional — stored as JSON
]);

$stored->identifier;   // string — opaque ID used in signed URLs
$stored->disk;         // string
$stored->path;         // string — disk-relative path
$stored->filename;     // string — sanitized original filename
$stored->mimeType;     // string — detected MIME
$stored->size;         // int — bytes
```

## Retrieve

```php
$file = $storage->retrieve($identifier);   // ?StoredFile
$file->stream();                            // resource — for streaming responses
```

Or read the bytes directly:

```php
$contents = $storage->getContents($identifier);   // ?string
```

For Eloquent access:

```php
$model = $storage->getModel($identifier);   // ?SecureUploadedFile
```

## Signed URLs

```php
$url = $storage->generateSecureUrl($identifier, expirationMinutes: 15);
```

Or via the helper:

```php
$url = secure_uploads()->generateSecureUrl($identifier);
```

The URL points at the bundled `secure-file.show` route. Pass the file URL to your view / API response — anyone with the URL can fetch the file until expiration.

For force-download links, hit the `secure-file.download` route instead:

```php
$url = URL::signedRoute('secure-file.download', ['identifier' => $identifier]);
```

The download route sets `Content-Disposition: attachment` so browsers save rather than render.

## Delete

```php
$storage->delete($identifier);   // bool — removes disk + DB row
```

`HasSecureFiles::detachSecureFile()` is a thin wrapper that also removes the morph relationship row.

## Quarantine

```php
$storage->quarantine($identifier);   // moves the file from its disk to the quarantine path
```

Called automatically by the async scanning flow when a file fails its scan. You can also call it manually when reviewing flagged uploads.

## Pending-scan inventory

```php
$pending = $storage->getPendingScanFiles(limit: 100);
```

Returns a `Collection<SecureUploadedFile>` of files awaiting async scan. `security:scan-quarantine` uses this internally.

## Disk + path conventions

- **Disk:** by default the package writes to the application's default disk. Use a dedicated private disk in production — never store secure files on a public disk.
- **Path:** default `'secure-files'`. Use a per-owner path (`'posts/123'`, `'users/42/avatars'`) when you want filesystem-level inspection to be readable.
- **Filename:** always sanitized via `FileValidationService::sanitizeFilename()`. Stored with a hash prefix to avoid collisions; the original (sanitized) filename is preserved in the DB for download.
