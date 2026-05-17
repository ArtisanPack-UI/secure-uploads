---
title: Getting Started
---

# Getting Started

A five-minute path from install to serving an uploaded file behind a signed URL.

## 1. Install

```bash
composer require artisanpack-ui/secure-uploads
php artisan migrate
```

The migration adds the `secure_files` table.

## 2. Add the trait to a model

```php
use ArtisanPackUI\SecureUploads\Concerns\HasSecureFiles;
use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    use HasSecureFiles;
}
```

The trait gives the model a `morphMany` relationship to `SecureUploadedFile` plus a set of attach / detach / query helpers.

## 3. Attach a file from a request

```php
public function store(Request $request, Post $post)
{
    $request->validate([
        'attachment' => ['required', 'file', new \ArtisanPackUI\SecureUploads\Rules\SecureFile],
    ]);

    $stored = $post->attachSecureFile($request->file('attachment'));

    return redirect()->route('secure-file.show', ['identifier' => $stored->identifier]);
}
```

`attachSecureFile()` runs the full pipeline: validates MIME against the file's actual contents, sanitizes the filename, optionally runs malware scanning, stores the file outside the public root, and creates a `SecureUploadedFile` model linked to the post.

## 4. Serve the file via signed URL

The package ships routes for serving and downloading. Get a fresh signed URL whenever you need to render or hand out the file:

```php
$url = secure_uploads()
    ->generateSecureUrl($stored->identifier, expirationMinutes: 15);
```

Or use Laravel's route helpers directly — both routes are signed-only and protected by the bundled `SecureFileController`:

```php
URL::signedRoute('secure-file.show', ['identifier' => $stored->identifier]);
URL::signedRoute('secure-file.download', ['identifier' => $stored->identifier]);
```

## 5. (Optional) Enable malware scanning

Out of the box the package uses the `NullScanner` — useful for dev / CI. Switch to a real scanner by setting an env var:

```dotenv
SECURE_UPLOADS_MALWARE_SCANNING_ENABLED=true
SECURE_UPLOADS_MALWARE_DRIVER=clamav
CLAMAV_SOCKET_PATH=/var/run/clamav/clamd.sock
```

Or use VirusTotal:

```dotenv
SECURE_UPLOADS_MALWARE_DRIVER=virustotal
VIRUSTOTAL_API_KEY=...
```

The `attachSecureFile()` pipeline calls the configured scanner automatically. See [Malware scanning](usage/malware-scanning.md) for the full flow.

## Next steps

- [Usage](usage.md) — full reference for the validation pipeline, scanner contract, storage service, events, middleware, and Artisan commands.
- [Advanced](advanced.md) — building a custom scanner, customizing the validation pipeline, processing the quarantine queue.
- [Installation](installation.md) — requirements and the full config reference.
