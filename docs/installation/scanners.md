---
title: Scanner Setup
---

# Scanner Setup

Three shipped scanner implementations satisfy `MalwareScannerInterface`. Pick one by setting `SECURE_UPLOADS_MALWARE_DRIVER` (or `config('artisanpack.secure-uploads.malwareScanning.driver')`).

## `null` (default)

No-op scanner. Every scan returns "clean". Use in dev, CI, and as a kill-switch.

```dotenv
SECURE_UPLOADS_MALWARE_DRIVER=null
SECURE_UPLOADS_MALWARE_SCANNING_ENABLED=false   # or true; either works
```

`isAvailable()` always returns `true`, so `failOnScanError` is irrelevant.

## `clamav`

Runs against a local ClamAV install. Socket-first with binary fallback.

```dotenv
SECURE_UPLOADS_MALWARE_SCANNING_ENABLED=true
SECURE_UPLOADS_MALWARE_DRIVER=clamav
CLAMAV_SOCKET_PATH=/var/run/clamav/clamd.sock
CLAMAV_BINARY_PATH=/usr/bin/clamscan
```

The scanner tries the socket first. If the socket doesn't exist or can't connect, it falls back to invoking the binary. Socket is dramatically faster (no process spawn + signature DB stays loaded) — prefer it for production.

### Operational notes

- **Keep `freshclam` running.** The signature DB rots quickly. On Debian / Ubuntu the `clamav-freshclam` service handles this.
- **The socket needs filesystem-level access.** Add your web server / queue worker user to the `clamav` group, or run `clamd` with a socket path your app user can read.
- **`isAvailable()` returns false** when neither the socket nor the binary works. Pair with `failOnScanError => true` in production to fail closed.
- **`timeout`** (default 30s) applies to both socket and binary modes. Files larger than your typical scan time should bump this.

### Testing your install

```php
use ArtisanPackUI\SecureUploads\Services\Scanners\ClamAvScanner;

$scanner = new ClamAvScanner();
dump($scanner->isAvailable());   // true if socket or binary responds
$result = $scanner->scan('/path/to/eicar.com.txt');   // EICAR test file
dump($result->infected, $result->signature);
```

Download the EICAR test file from https://www.eicar.org/ — it triggers every legitimate AV without being malicious itself.

## `virustotal`

Runs against the VirusTotal cloud service.

```dotenv
SECURE_UPLOADS_MALWARE_SCANNING_ENABLED=true
SECURE_UPLOADS_MALWARE_DRIVER=virustotal
VIRUSTOTAL_API_KEY=your_key_here
```

The scanner first computes the file's SHA-256 hash and calls `scanByHash()` to check if VirusTotal has already analyzed an identical file. If a result is cached at VirusTotal, no upload happens — the cached verdict is used. Only previously-unseen files are uploaded.

### Operational notes

- **API quota.** The free tier is ~4 requests / minute. Production uploads at any meaningful volume need the paid tier.
- **Privacy.** Files are sent to a third-party service. Don't use VirusTotal for confidential / regulated data.
- **Latency.** Cold-path scans (file unknown to VirusTotal) involve an upload + a wait for analysis. The default `timeout` is 60 seconds; bump it for large files.
- **`isAvailable()`** checks that the API key is set and the VirusTotal API responds.

### Testing your install

```php
use ArtisanPackUI\SecureUploads\Services\Scanners\VirusTotalScanner;

$scanner = new VirusTotalScanner();
dump($scanner->isAvailable());   // true if API responds
// EICAR's SHA-256 is well-known and pre-classified at VirusTotal
$result = $scanner->scan('/path/to/eicar.com.txt');
```

## Building your own

Implement `MalwareScannerInterface` (3 methods: `scan`, `isAvailable`, `getName`) and bind it in a service provider. See [Custom scanners](../advanced/custom-scanners.md) for the contract details and a worked example.
