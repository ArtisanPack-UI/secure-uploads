# ArtisanPack UI — Secure Uploads

File upload security for Laravel: content-type validation with magic-byte sniffing, filename sanitization, pluggable malware scanning (ClamAV / VirusTotal), secure signed-URL storage, upload rate limiting, and quarantine workflows.

This package is part of the **ArtisanPack UI Security 2.0** split — the upload-focused features previously bundled inside `artisanpack-ui/security` (1.x) live here in 2.0+.

## Features

- **File validation pipeline** (`FileValidationService`) — MIME sniffing against actual file content, magic-byte verification, extension allowlists / blocklists, per-type and absolute size limits, double-extension and null-byte trick detection, EXIF stripping for images
- **Validation rules** — `SafeFilename`, `SecureFile` (drop-in `Rule` classes for Form Requests)
- **Pluggable malware scanning** — `ClamAvScanner` (Unix socket or binary), `VirusTotalScanner` (API + by-hash short-circuit), `NullScanner` (dev / CI default)
- **Secure storage** (`SecureFileStorageService`) — files stored outside the public root, served only via signed URLs through the bundled `SecureFileController`
- **Quarantine workflow** — files flagged by async scanning live in a quarantine area until cleared by `security:scan-quarantine`
- **Upload rate limiting** (`FileUploadRateLimiter`)
- **Middleware** — `validate.upload`, `scan.upload`
- **Eloquent concern** — `HasSecureFiles` adds `attachSecureFile`, `secureImages`, `secureDocuments`, etc. to any model that owns uploaded files
- **Events** — `FileUploaded`, `FileUploadRejected`, `FileServed`, `MalwareDetected` (subscribed to by `artisanpack-ui/security-analytics` for audit trail)
- **Artisan commands** — `security:cleanup-files`, `security:scan-quarantine`

## Installation

```bash
composer require artisanpack-ui/secure-uploads
php artisan migrate
```

(Optional) Publish the config:

```bash
php artisan vendor:publish --tag=secure-uploads-config
```

## Quick start

```php
use ArtisanPackUI\SecureUploads\Concerns\HasSecureFiles;

class Post extends Model
{
    use HasSecureFiles;
}
```

```php
$post = Post::find(1);
$stored = $post->attachSecureFile($request->file('attachment'));

return redirect()->route('secure-file.show', ['identifier' => $stored->identifier]);
```

The `attachSecureFile()` call runs validation, optionally scans for malware, sanitizes the filename, and stores the file behind a signed URL.

## Configuration

The shipped config covers MIME / extension allow- and block-lists, size limits, EXIF stripping, scanner driver selection (`null` / `clamav` / `virustotal`), and rate limiting. Override any of it after publishing:

```bash
php artisan vendor:publish --tag=secure-uploads-config
```

See `config/artisanpack/secure-uploads.php` for the full list with inline documentation.

## Documentation

- [Documentation home](docs/home.md) — overview + map
- [Getting started](docs/getting-started.md) — 5-minute install + first upload
- [Installation](docs/installation.md) — requirements, configuration, scanner setup
- [Usage](docs/usage.md) — validation, malware scanning, storage, signed URLs, events, commands
- [Advanced](docs/advanced.md) — extending validators, custom scanners, quarantine workflow
- [FAQ](docs/faq.md)
- [Troubleshooting](docs/troubleshooting.md)
- [Changelog](CHANGELOG.md)

## Requirements

- PHP 8.2+
- Laravel 10 / 11 / 12
- `ext-fileinfo` (bundled with PHP) for MIME detection
- ClamAV daemon **or** binary (optional, only if using the ClamAV scanner)
- VirusTotal API key (optional, only if using the VirusTotal scanner)

## Sibling packages

| Package | Scope |
|---|---|
| [`artisanpack-ui/security`](https://github.com/ArtisanPack-UI/security) | Core: input sanitization, output escaping, KSES, CSP, security headers |
| [`artisanpack-ui/security-auth`](https://github.com/ArtisanPack-UI/security-auth) | 2FA, password complexity, account lockout, sessions |
| [`artisanpack-ui/security-advanced-auth`](https://github.com/ArtisanPack-UI/security-advanced-auth) | WebAuthn, SSO, social login, biometric, device fingerprinting |
| [`artisanpack-ui/rbac`](https://github.com/ArtisanPack-UI/rbac) | Roles, permissions, hierarchy, Blade directives, Gate integration |
| [`artisanpack-ui/security-analytics`](https://github.com/ArtisanPack-UI/security-analytics) | Event logging, anomaly detection, SIEM, dashboards |
| [`artisanpack-ui/compliance`](https://github.com/ArtisanPack-UI/compliance) | GDPR / CCPA / LGPD compliance tools |

## License

MIT — see [LICENSE](LICENSE).

## Contributing

Please read the [contributing guidelines](CONTRIBUTING.md) before opening an issue or PR.
