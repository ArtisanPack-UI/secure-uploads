---
title: Installation
---

# Installation

## Install via Composer

```bash
composer require artisanpack-ui/secure-uploads
```

The package auto-registers via Laravel's package discovery.

## Run the migration

```bash
php artisan migrate
```

Creates the `secure_files` table used by the `SecureUploadedFile` model.

## (Optional) Publish the config

```bash
php artisan vendor:publish --tag=secure-uploads-config
```

Publishes `config/artisanpack/secure-uploads.php`. Override allow/blocklists, size limits, scanner driver selection, rate limiting, and quarantine settings here.

## Add the routes

The package's `SecureFileController` routes are loaded automatically by the service provider:

```php
GET  /secure-file/{identifier}
GET  /secure-file/{identifier}/download
```

Both routes are wrapped in Laravel's `signed` middleware — you generate URLs via `URL::signedRoute()` or `secure_uploads()->generateSecureUrl()`.

## Add the trait to consumer models

Any model that owns uploaded files should use the `HasSecureFiles` trait:

```php
use ArtisanPackUI\SecureUploads\Concerns\HasSecureFiles;

class Post extends Model
{
    use HasSecureFiles;
}
```

## Deeper topics

- [Requirements](installation/requirements.md) — PHP / Laravel versions, optional system deps (ClamAV, VirusTotal API access)
- [Configuration](installation/configuration.md) — full config reference
- [Scanner setup](installation/scanners.md) — wiring ClamAV (socket or binary) and VirusTotal
