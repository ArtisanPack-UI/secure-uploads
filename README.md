# ArtisanPack UI — Secure Uploads

File upload security for Laravel: content-type validation, filename sanitization, malware scanning, upload rate limiting, secure storage, and quarantine workflows.

This package is part of the **ArtisanPack UI Security 2.0** split — the upload-focused features previously bundled inside `artisanpack-ui/security` (1.x) live here in 2.0+.

> **Status:** scaffold. Content is being extracted from `artisanpack-ui/security` 1.x in follow-up PRs. See the package roadmap on the issue tracker.

## Installation

```bash
composer require artisanpack-ui/secure-uploads
```

## Scope

Once content extraction lands, this package will provide:

- File validation pipeline (`FileValidationService`) — MIME sniffing, magic-byte verification, extension allowlists, size limits
- Filename sanitization (`SafeFilename` rule, `SecureFile` rule)
- Malware scanning via pluggable scanners — `ClamAvScanner`, `VirusTotalScanner`, `NullScanner` (testing)
- Secure storage (`SecureFileStorageService`) with the `SecureUploadedFile` model and a `SecureFileController` for signed retrieval
- Rate limiting (`FileUploadRateLimiter`)
- Middleware: `ValidateFileUpload`, `ScanUploadedFiles`
- The `HasSecureFiles` Eloquent concern for attaching validated, scanned files to models
- Events: `FileUploaded`, `FileUploadRejected`, `FileServed`, `MalwareDetected`

## Sibling packages

| Package | Scope |
|---|---|
| [`artisanpack-ui/security`](https://github.com/ArtisanPack-UI/security) | Core: input sanitization, output escaping, KSES, CSP, security headers |
| [`artisanpack-ui/security-advanced-auth`](https://github.com/ArtisanPack-UI/security-advanced-auth) | WebAuthn, SSO, social login, biometric, device fingerprinting |
| [`artisanpack-ui/rbac`](https://github.com/ArtisanPack-UI/rbac) | Roles, permissions, hierarchy, Blade directives, Gate integration |
| [`artisanpack-ui/secure-uploads`](https://github.com/ArtisanPack-UI/secure-uploads) | File validation, malware scanning, secure storage |
| [`artisanpack-ui/security-analytics`](https://github.com/ArtisanPack-UI/security-analytics) | Event logging, anomaly detection, SIEM, dashboards |
| [`artisanpack-ui/compliance`](https://github.com/ArtisanPack-UI/compliance) | GDPR / CCPA / LGPD compliance tools |
| [`artisanpack-ui/security-full`](https://github.com/ArtisanPack-UI/security-full) | Meta-package bundling all of the above |

## Contributing

As an open-source project, this package is open to contributions from anyone. Please [read through the contributing guidelines](CONTRIBUTING.md) to learn more about how you can contribute to this project.
