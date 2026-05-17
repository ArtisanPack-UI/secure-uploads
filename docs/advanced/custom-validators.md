---
title: Custom Validators
---

# Custom Validators

`FileValidationService` is bound to `FileValidatorInterface` in the service provider. To extend or replace the pipeline, build your own implementation and rebind it.

## Add checks via a subclass

The lightest-touch option — keep the shipped behaviour and add your own:

```php
namespace App\Services;

use ArtisanPackUI\SecureUploads\FileUpload\ValidationResult;
use ArtisanPackUI\SecureUploads\Services\FileValidationService;
use Illuminate\Http\UploadedFile;

class TenantAwareFileValidator extends FileValidationService
{
    public function validate(UploadedFile $file, array $options = []): ValidationResult
    {
        $result = parent::validate($file, $options);

        if ($this->wouldExceedTenantQuota($file)) {
            $result->addError('Upload would exceed your storage quota.');
        }

        return $result;
    }
}
```

Bind in a service provider's `register()`:

```php
$this->app->singleton(FileValidatorInterface::class, TenantAwareFileValidator::class);
```

Your subclass is now used everywhere — the trait, the middleware, the storage service all resolve from the container.

## Replace the pipeline entirely

Implement `FileValidatorInterface` from scratch when the shipped pipeline doesn't fit:

```php
namespace App\Services;

use ArtisanPackUI\SecureUploads\Contracts\FileValidatorInterface;
use ArtisanPackUI\SecureUploads\FileUpload\ValidationResult;
use Illuminate\Http\UploadedFile;

class StrictPdfValidator implements FileValidatorInterface
{
    public function validate(UploadedFile $file, array $options = []): ValidationResult
    {
        $result = new ValidationResult($file);

        if ($file->getClientMimeType() !== 'application/pdf') {
            $result->addError('Only PDF uploads accepted on this endpoint.');
        }

        if ($file->getSize() > 50 * 1024 * 1024) {
            $result->addError('PDF too large (max 50 MB).');
        }

        // ... your checks
        return $result;
    }

    // Implement the rest of the interface ...
}
```

Most apps don't need this — the shipped service covers the common cases and is configurable. Subclassing is almost always the right choice.

## Per-endpoint overrides

For endpoint-specific rules without rebinding, pass `$options` to `validate()` or `attachSecureFile()`:

```php
$post->attachSecureFile($file, [
    'maxFileSize' => 100 * 1024 * 1024,
    'allowedMimeTypes' => ['video/mp4'],
]);
```

Use this for single-endpoint policy variation; reserve rebinding for cross-cutting changes that should apply everywhere.

## Building rules instead

If you need a one-off check that only applies in a specific Form Request, write a normal Laravel rule rather than subclassing the validator:

```php
namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Http\UploadedFile;

class PdfHasNoEmbeddedFonts implements Rule
{
    public function passes($attribute, $value)
    {
        return $value instanceof UploadedFile
            && ! preg_match('/\/Font/', file_get_contents($value->getPathname(), false, null, 0, 10_000));
    }

    public function message()
    {
        return 'PDF must not embed fonts.';
    }
}
```

Then drop it into the Form Request:

```php
'attachment' => ['required', 'file', new SecureFile, new PdfHasNoEmbeddedFonts],
```

Cleaner for narrow / single-purpose checks than touching the package's validator.
