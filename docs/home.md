---
title: ArtisanPack UI Secure Uploads Documentation
---

# ArtisanPack UI Secure Uploads

File upload security for Laravel — validation, malware scanning, secure storage, signed URLs, rate limiting, and quarantine workflows.

This package is part of the **ArtisanPack UI Security 2.0** split. The 1.x security toolkit's upload features now live here as a focused, standalone package you can install on its own or alongside the rest of the ecosystem.

## What's in this package

- **Validation pipeline** — MIME sniffing, magic-byte verification, extension allow/blocklists, size limits, double-extension and null-byte trick detection, EXIF stripping
- **Malware scanning** — pluggable scanners with shipped implementations for ClamAV (socket + binary), VirusTotal (API + by-hash), and a no-op Null scanner for dev / CI
- **Secure storage** — files stored outside the public root, served only via signed URLs through the bundled `SecureFileController`
- **Quarantine workflow** — async scanning quarantines files until `security:scan-quarantine` clears them
- **`HasSecureFiles` Eloquent concern** — attach validated, scanned files to any model via a `morphMany` relationship
- **Events** — observe `FileUploaded`, `FileUploadRejected`, `FileServed`, `MalwareDetected`
- **Middleware + rate limiting** — `validate.upload`, `scan.upload`, `FileUploadRateLimiter`
- **Artisan commands** — `security:cleanup-files`, `security:scan-quarantine`

## Documentation map

- [Getting Started](getting-started.md) — 5-minute install + first signed-URL upload
- [Installation](installation.md) — requirements, configuration, scanner setup
- [Usage](usage.md) — validation, scanning, storage, signed URLs, events, middleware, commands
- [Advanced](advanced.md) — extending validators, custom scanners, quarantine workflow, rate limiting
- [FAQ](faq.md)
- [Troubleshooting](troubleshooting.md)

## Related packages

| Package | Scope |
|---|---|
| [`artisanpack-ui/security`](https://github.com/ArtisanPack-UI/security) | Core: input sanitization, escaping, CSP, security headers |
| [`artisanpack-ui/security-auth`](https://github.com/ArtisanPack-UI/security-auth) | 2FA, password complexity, account lockout, sessions |
| [`artisanpack-ui/security-advanced-auth`](https://github.com/ArtisanPack-UI/security-advanced-auth) | WebAuthn, SSO, social login |
| [`artisanpack-ui/rbac`](https://github.com/ArtisanPack-UI/rbac) | Roles, permissions, Gate integration |
| [`artisanpack-ui/security-analytics`](https://github.com/ArtisanPack-UI/security-analytics) | Event logging, anomaly detection, SIEM, dashboards (subscribes to the `FileUploaded` / `MalwareDetected` events) |
