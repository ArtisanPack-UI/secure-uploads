# ArtisanPack UI — Secure Uploads Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-05-18

### Added

- Initial release of the standalone Secure Uploads package, extracted from `artisanpack-ui/security` 1.x as part of the Security 2.0 package split.
- `FileValidationService` (451 lines) covering MIME sniffing against actual file content, magic-byte verification, extension allowlists / blocklists, per-type + absolute size limits, double-extension trick detection, null-byte trick detection, EXIF stripping for images, and a `withMediaLibraryDefaults()` preset.
- `SafeFilename` and `SecureFile` validation rules, both drop-in for Form Requests.
- Pluggable malware scanning via the `MalwareScannerInterface`:
    - `ClamAvScanner` — Unix-socket-first with binary fallback, configurable socket path / binary path / timeout
    - `VirusTotalScanner` — full API integration plus by-hash short-circuit (`scanByHash`) to skip re-uploading known files
    - `NullScanner` — no-op for dev and CI
- `SecureFileStorageService` (344 lines) — `store`, `retrieve`, `delete`, `generateSecureUrl`, `exists`, `getContents`, `getModel`, `getPendingScanFiles`, `quarantine`.
- `SecureFileController` with bundled routes (`/secure-file/{identifier}` and `/secure-file/{identifier}/download`) protected by Laravel's `signed` middleware.
- `HasSecureFiles` Eloquent concern — `secureFiles()` morphMany, `attachSecureFile`, `attachSecureFiles`, `detachSecureFile`, `detachAllSecureFiles`, `secureFilesOfType`, `secureImages`, `secureDocuments`, `primarySecureFile`, `hasSecureFiles`, `secureFilesTotalSize`.
- `SecureUploadedFile` Eloquent model + `create_secure_files_table` migration.
- `FileUploadRateLimiter` service wrapping Laravel's `RateLimiter`.
- Middleware aliases — `validate.upload`, `scan.upload`.
- Events — `FileUploaded`, `FileUploadRejected`, `FileServed`, `MalwareDetected` (subscribed to by `artisanpack-ui/security-analytics` for audit trail).
- Artisan commands — `security:cleanup-files` (purge expired / old files), `security:scan-quarantine` (process the quarantine queue).
- Value objects — `RequestContext`, `ScanResult`, `StoredFile`, `ValidationResult` for typed pipeline returns.
- Quarantine workflow: when `malwareScanning.async = true`, uploads are quarantined until `security:scan-quarantine` runs.
- Configurable scanner driver via `SECURE_UPLOADS_MALWARE_DRIVER` env var (`null` / `clamav` / `virustotal`).

### Changed

- (none — initial release)

### Removed

- This package contains the file upload security content previously bundled in `artisanpack-ui/security` 1.x. See the [`artisanpack-ui/security` UPGRADE guide](https://github.com/ArtisanPack-UI/security/blob/main/UPGRADE.md) for migration instructions from 1.x. The 1.x `SecureFile` and `PasswordPolicy` rules split — `SecureFile` lives here, `PasswordPolicy` moved to `artisanpack-ui/security-auth`.
