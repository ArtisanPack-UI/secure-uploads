---
title: Usage
---

# Usage

The package exposes its functionality through six layers — validation pipeline, malware scanning, secure storage, the `HasSecureFiles` trait, middleware, and events — plus two Artisan commands for maintenance.

## Topics

- [Validation pipeline](usage/validation.md) — MIME sniffing, extension checks, size limits, filename sanitization
- [Malware scanning](usage/malware-scanning.md) — the contract, the three shipped drivers, sync vs async
- [Secure storage](usage/storage.md) — storing, retrieving, deleting, signed URLs
- [HasSecureFiles trait](usage/has-secure-files.md) — attaching files to Eloquent models
- [Middleware](usage/middleware.md) — `validate.upload`, `scan.upload`
- [Events](usage/events.md) — `FileUploaded`, `FileUploadRejected`, `FileServed`, `MalwareDetected`
- [Artisan commands](usage/artisan-commands.md) — `security:cleanup-files`, `security:scan-quarantine`

## Quick reference

```php
// Validation (in a Form Request)
'attachment' => ['required', 'file', new SecureFile, new SafeFilename],

// Storage (via the trait)
$stored = $post->attachSecureFile($request->file('attachment'));

// Signed URL
$url = secure_uploads()->generateSecureUrl($stored->identifier);

// Signed route URLs (auto-loaded — routes registered by the service provider)
URL::signedRoute('secure-file.show', ['identifier' => $stored->identifier]);
URL::signedRoute('secure-file.download', ['identifier' => $stored->identifier]);

// Middleware
Route::post('/upload', ...)->middleware(['validate.upload', 'scan.upload']);

// Events
Event::listen(FileUploaded::class, AuditUpload::class);

// Commands
php artisan security:cleanup-files --older-than=30
php artisan security:scan-quarantine
```
