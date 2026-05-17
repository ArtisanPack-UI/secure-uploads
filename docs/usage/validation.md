---
title: Validation Pipeline
---

# Validation Pipeline

`FileValidationService` (bound to `FileValidatorInterface`) runs every uploaded file through MIME sniffing, extension checks, size limits, and trick detection before any storage or scanning happens.

## Via validation rule (recommended)

The simplest way — drop the shipped rules into a Form Request:

```php
use ArtisanPackUI\SecureUploads\Rules\SafeFilename;
use ArtisanPackUI\SecureUploads\Rules\SecureFile;

public function rules(): array
{
    return [
        'attachment' => ['required', 'file', new SecureFile, new SafeFilename],
    ];
}
```

- `SecureFile` — runs the full validation pipeline (MIME, extension, size, tricks).
- `SafeFilename` — checks just the filename for path traversal, null bytes, double extensions, and other shenanigans. Use independently when you accept raw filename strings (e.g. from API metadata) rather than `UploadedFile` instances.

## Via the service directly

```php
use ArtisanPackUI\SecureUploads\Contracts\FileValidatorInterface;
use ArtisanPackUI\SecureUploads\FileUpload\ValidationResult;

$validator = app(FileValidatorInterface::class);
$result = $validator->validate($request->file('attachment'));

if (! $result->isValid()) {
    return back()->withErrors(['attachment' => $result->getErrors()]);
}
```

`ValidationResult` has `isValid()`, `getErrors()`, and a few accessors for the resolved MIME type and sanitized filename.

## What the pipeline checks

In order:

1. **File present and non-empty.**
2. **Size against `maxFileSize` and `maxFileSizePerType`.** Per-type wins when smaller.
3. **MIME against allow / blocklists.** When `validateMimeByContent` is on, `FileValidationService::detectMimeType()` calls `finfo` against the actual file bytes — the supplied / claimed MIME type is ignored.
4. **Extension against allow / blocklists.**
5. **Double-extension trick** (when enabled) — `file.php.jpg` style.
6. **Null-byte trick** (when enabled) — `file.php\x00.jpg` style.
7. **Filename sanitization** — strips path separators, collapses whitespace, normalizes Unicode, truncates to a reasonable length.
8. **EXIF stripping** for JPEG / TIFF / PNG (when enabled).

Each failed check appends to `ValidationResult::getErrors()`. The pipeline runs every check rather than short-circuiting so the user sees all problems at once.

## Per-call overrides

`validate()` accepts an `$options` array to override config for a single call:

```php
$validator->validate($request->file('attachment'), [
    'maxFileSize' => 50 * 1024 * 1024,
    'allowedMimeTypes' => ['application/pdf'],
]);
```

Useful for endpoints with stricter or looser policies than the application default.

## Media library defaults

The shipped `withMediaLibraryDefaults()` preset configures the validator for typical CMS media library use (broad image + document support, EXIF stripped, double-extension and null-byte checks on):

```php
$validator = app(FileValidatorInterface::class)->withMediaLibraryDefaults();
```

Useful when wiring the validator into a media library upload endpoint that has different policy from the rest of your app.

## Customizing

See [Custom validators](../advanced/custom-validators.md) for extending the validation pipeline with your own checks.
