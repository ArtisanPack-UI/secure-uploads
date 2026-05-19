---
title: Custom Scanners
---

# Custom Scanners

`MalwareScannerInterface` has three methods. Implement them, bind your class, and the rest of the package picks it up without further changes.

## The contract

```php
namespace ArtisanPackUI\SecureUploads\Contracts;

use ArtisanPackUI\SecureUploads\FileUpload\ScanResult;

interface MalwareScannerInterface
{
    public function scan(string $filePath): ScanResult;
    public function isAvailable(): bool;
    public function getName(): string;
}
```

- `scan($filePath)` — given an absolute path to the file on disk, return a `ScanResult`. Should not throw on infected files — return `ScanResult` with `infected = true` instead. Throwing is reserved for scanner unavailability or transport errors.
- `isAvailable()` — quick health check (socket reachable, API key configured, binary on PATH). Used by `failOnScanError` and by the quarantine command to skip drivers that are down.
- `getName()` — short identifier (`'clamav'`, `'virustotal'`). Used in `ScanResult` and in event payloads so listeners can attribute results.

## Example: scan via an external HTTP service

```php
namespace App\Scanners;

use ArtisanPackUI\SecureUploads\Contracts\MalwareScannerInterface;
use ArtisanPackUI\SecureUploads\FileUpload\ScanResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

class InternalScannerClient implements MalwareScannerInterface
{
    public function __construct(
        protected string $endpoint,
        protected string $apiKey,
        protected int $timeoutSeconds = 30,
    ) {}

    public function scan(string $filePath): ScanResult
    {
        $response = Http::withToken($this->apiKey)
            ->timeout($this->timeoutSeconds)
            ->attach('file', fopen($filePath, 'r'), basename($filePath))
            ->post($this->endpoint . '/scan');

        $response->throw();   // unavailability or transport errors raise

        $body = $response->json();

        return new ScanResult(
            infected: $body['infected'] ?? false,
            signature: $body['signature'] ?? null,
            scanner: $this->getName(),
            scannedAt: CarbonImmutable::now(),
            raw: $body,
        );
    }

    public function isAvailable(): bool
    {
        return rescue(
            fn () => Http::withToken($this->apiKey)
                ->timeout(2)
                ->get($this->endpoint . '/health')
                ->successful(),
            false,
        );
    }

    public function getName(): string
    {
        return 'internal-scanner';
    }
}
```

## Binding your scanner

Two paths.

### Path A — replace the default binding

In a service provider's `register()`:

```php
$this->app->singleton(
    MalwareScannerInterface::class,
    fn ($app) => new InternalScannerClient(
        endpoint: config('services.internal_scanner.endpoint'),
        apiKey: config('services.internal_scanner.key'),
    ),
);
```

Your scanner is now used everywhere the contract is resolved — `SecureFileStorageService`, the `scan.upload` middleware, the `scan-quarantine` command.

### Path B — extend the driver switch

If you want to pick the scanner via config (`SECURE_UPLOADS_MALWARE_DRIVER=internal-scanner`), override the package's service provider binding with a `match` that includes your driver:

```php
$this->app->extend(
    MalwareScannerInterface::class,
    function ($default, $app) {
        $driver = config('artisanpack.secure-uploads.malwareScanning.driver');

        return match ($driver) {
            'internal-scanner' => new InternalScannerClient(/* ... */),
            default => $default,
        };
    },
);
```

The default (from the package's switch) is still available for the original three drivers.

## Testing your scanner

The package's `NullScanner` is a good reference for what a passing scanner looks like. For your own tests, use the EICAR test file (https://www.eicar.org/) — it's a known benign string that triggers every legitimate antivirus.

```php
$scanner = new InternalScannerClient(/* ... */);
$result = $scanner->scan(__DIR__ . '/fixtures/eicar.com.txt');
expect($result->infected)->toBeTrue();
expect($result->signature)->toContain('EICAR');
```

## Conventions

- **Fail closed in production.** Pair your scanner with `failOnScanError = true` unless you have a specific reason to let uploads through during scanner outages.
- **`getName()` should be stable.** Listeners may filter on it.
- **Don't throw on infected files.** Return `ScanResult` with `infected = true`. Throwing is reserved for the scanner itself being broken.
- **Be fast.** Sync scanning blocks the user — anything slower than a few seconds should run async via the quarantine workflow.
