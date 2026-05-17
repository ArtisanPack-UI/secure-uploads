---
title: Events
---

# Events

Four events fire across the upload lifecycle. All four use Laravel's class-based event syntax — listen via `Event::listen()` or `EventServiceProvider::$listen`.

| Event | When it fires | Payload |
|---|---|---|
| `FileUploaded` | After validation, scan, and storage all succeed | `SecureUploadedFile $file`, `RequestContext $context` |
| `FileUploadRejected` | When validation fails | `UploadedFile $file`, `ValidationResult $result`, `RequestContext $context` |
| `MalwareDetected` | When a scan returns `infected = true` | `SecureUploadedFile|UploadedFile $file`, `ScanResult $scanResult`, `RequestContext $context` |
| `FileServed` | When a signed-URL request hits `SecureFileController::show()` or `download()` | `SecureUploadedFile $file`, `Request $request` |

## Listening

```php
use ArtisanPackUI\SecureUploads\Events\MalwareDetected;
use Illuminate\Support\Facades\Event;

Event::listen(MalwareDetected::class, function (MalwareDetected $event) {
    logger()->critical('Malware detected', [
        'filename' => $event->file->original_filename,
        'signature' => $event->scanResult->signature,
        'ip' => $event->context->ipAddress,
    ]);
});
```

Or in `EventServiceProvider`:

```php
protected $listen = [
    MalwareDetected::class => [
        AlertSecurityTeam::class,
        QuarantineUploaderAccount::class,
    ],
    FileUploaded::class => [
        AuditFileUpload::class,
    ],
];
```

## Use cases

**Audit logging.** `artisanpack-ui/security-analytics` subscribes to all four events for the unified security audit trail. Listen for `FileUploaded` and `FileServed` to build your own access logs.

**Threat response.** Listen for `MalwareDetected` to alert (PagerDuty, Slack), quarantine the uploader account, or revoke active sessions.

**Storage cleanup.** Listen for `FileUploadRejected` to immediately purge any partial uploads — though the package handles this internally for the common cases.

**Usage analytics.** Aggregate `FileUploaded` payloads to compute storage usage per user / per model type for billing or quota enforcement.

## `RequestContext`

`RequestContext` is a value object that captures everything relevant about the request that triggered the upload:

```php
$context->ipAddress;     // string
$context->userAgent;     // ?string
$context->userId;        // ?int — authenticated user, if any
$context->sessionId;     // ?string
$context->referer;       // ?string
$context->routeName;     // ?string
```

It's built from the current `Illuminate\Http\Request` at the moment of attachment, so listeners get the full request context without having to re-derive it.
