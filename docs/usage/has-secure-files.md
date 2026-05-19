---
title: HasSecureFiles Trait
---

# `HasSecureFiles` Trait

The trait gives any Eloquent model a `morphMany` relationship to `SecureUploadedFile` plus a set of helpers for attaching, detaching, and querying files.

## Setup

```php
use ArtisanPackUI\SecureUploads\Concerns\HasSecureFiles;

class Post extends Model
{
    use HasSecureFiles;
}
```

No migration on the host model — `secure_files` uses morph columns to link back.

## Attaching

```php
$stored = $post->attachSecureFile($request->file('attachment'));

$stored = $post->attachSecureFile($request->file('attachment'), [
    'disk' => 's3-private',
    'path' => 'posts/' . $post->id,
    'metadata' => ['caption' => 'Conference photo'],
]);
```

Runs the full validate → scan → store pipeline. Returns a `StoredFile` value object.

Multiple at once:

```php
$results = $post->attachSecureFiles($request->file('attachments'));
// returns ['attachment.pdf' => StoredFile|null, ...]
// null entries indicate validation / scan failure for that file
```

## Querying

```php
$post->secureFiles;                    // Collection<SecureUploadedFile> — all attached
$post->hasSecureFiles();               // bool
$post->secureFilesTotalSize();         // int — bytes across all attached files
$post->primarySecureFile();            // ?SecureUploadedFile — first attached, or null
```

Filtered:

```php
$post->secureFilesOfType('image/');    // images
$post->secureImages();                 // shorthand for type 'image/'
$post->secureDocuments();              // shorthand for common document MIME types
```

## Detaching

```php
$post->detachSecureFile($identifier);              // removes morph row + file from disk + DB row
$post->detachSecureFile($identifier, deleteFile: false);  // removes morph row only — keeps file
$post->detachAllSecureFiles();                     // bulk detach + delete
```

Returns `bool` for single, `int` (count) for bulk.

## What you get on `SecureUploadedFile`

| Field | Notes |
|---|---|
| `identifier` | Opaque ID used in signed URLs |
| `original_filename` | Sanitized version of what the user uploaded |
| `mime_type` | Detected by content (not by claim) |
| `size` | Bytes |
| `disk`, `path` | Where it lives on the filesystem |
| `scan_status` | `null`, `pending`, `clean`, `infected`, `error` |
| `scan_result` | Full `ScanResult` payload as JSON when scanned |
| `metadata` | Whatever you passed in `attachSecureFile()` `$options['metadata']` |
| `owner_type`, `owner_id` | Morph columns linking back to the parent model |

## Patterns

### Single primary file (e.g. avatar)

```php
public function updateAvatar(UploadedFile $file): void
{
    $this->detachAllSecureFiles();
    $this->attachSecureFile($file, ['metadata' => ['role' => 'avatar']]);
}

public function avatarUrl(): ?string
{
    $file = $this->primarySecureFile();
    return $file
        ? secure_uploads()->generateSecureUrl($file->identifier)
        : null;
}
```

### Gallery (many files)

```blade
@foreach ($post->secureImages() as $image)
    <img src="{{ secure_uploads()->generateSecureUrl($image->identifier) }}"
         alt="{{ $image->metadata['caption'] ?? '' }}" />
@endforeach
```

### Document attachments

```blade
@foreach ($post->secureDocuments() as $doc)
    <a href="{{ URL::signedRoute('secure-file.download', ['identifier' => $doc->identifier]) }}">
        {{ $doc->original_filename }} ({{ number_format($doc->size / 1024, 1) }} KB)
    </a>
@endforeach
```
