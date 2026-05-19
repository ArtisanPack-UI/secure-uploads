---
title: Quarantine Workflow
---

# Quarantine Workflow

When `malwareScanning.async = true`, uploads don't wait for the scanner. Files land in a quarantine area, get a pending DB row, and a background worker drains the queue. This page walks the full flow.

## When to use it

| Choose async when... | Choose sync when... |
|---|---|
| Scanning is slow (VirusTotal, large files, in-process antivirus) | Scanning is fast (ClamAV socket on the same host) |
| User-facing latency matters — you want the upload request to finish in <100 ms | Users expect immediate "your file is safe" feedback |
| Your worker fleet can run the scan cron | You don't have a queue / scheduler set up |
| Scanner quotas / costs make per-request scanning impractical | Scanner cost is irrelevant |

You can switch per-call too:

```php
$post->attachSecureFile($file, ['async' => true]);
```

## The flow

```text
Upload arrives
   │
   ▼
Validate (sync)
   │
   ├── invalid → reject (no DB row, no file written)
   │
   ▼
Write file to quarantine path
   │
   ▼
Create SecureUploadedFile with scan_status = 'pending'
   │
   ▼
Return StoredFile to caller (request completes here)

  ── time passes ──

Scheduler fires security:scan-quarantine
   │
   ▼
Pick up files with scan_status = 'pending' (up to --limit)
   │
   ▼
For each:
   ├── Run scanner
   │
   ├── Clean    → move file to normal storage location
   │            → set scan_status = 'clean'
   │            → fire FileUploaded
   │
   ├── Infected → keep in quarantine
   │            → set scan_status = 'infected'
   │            → fire MalwareDetected
   │
   └── Error    → keep in quarantine
                → set scan_status = 'error'
                → next run retries
```

## Caller-side considerations

Async means the caller can't immediately tell users "your file is safe." Options:

- **Optimistic UI.** Show the file as "pending review" until the scan finishes. Poll `SecureUploadedFile::scan_status` or push an update via Laravel Echo when the worker updates the row.
- **Don't link from rendered content.** Don't render `<img src="signed-url">` for a file until `scan_status = 'clean'` — otherwise users see a broken image when the controller refuses to serve a pending file.
- **Block access in the controller.** `SecureFileController::show()` checks `scan_status` and returns 423 (Locked) for pending or 451 (Unavailable For Legal Reasons) for infected. Customize as needed.

## Worker-side considerations

The `security:scan-quarantine` command is intentionally simple — pick N files, scan each, update status. Operational notes:

- **Schedule frequency** should be tighter than user expectations. Every 5 minutes is reasonable for end-user uploads; every minute for higher SLAs. Don't run more often than the scanner driver can handle.
- **`--limit`** defaults to 100. Bump it for higher throughput, drop it for very slow scanners. Tune empirically.
- **Concurrency.** The command is single-threaded. For high volume, run multiple workers concurrently — the DB row pick uses `SELECT ... LIMIT N` plus an `UPDATE` to grab ownership before scanning, so concurrent workers don't collide on the same files.
- **Retries.** Files in `scan_status = 'error'` are retried on the next run. There's no retry limit — if your scanner is broken, the queue keeps growing. Monitor `getPendingScanFiles()` count and alert.

## Recovering from a backlog

If the queue is growing faster than the worker can drain:

1. **Scale workers horizontally.** Multiple parallel `security:scan-quarantine` invocations — they coordinate via row-level locks.
2. **Increase `--limit`.** Process more per run.
3. **Switch driver temporarily.** If VirusTotal quotas are the bottleneck, switch to ClamAV (or vice versa) until the backlog clears.
4. **Drain selectively.** Bypass the command and call `SecureFileStorageService::quarantine()` directly to discard known-bad uploads without scanning.

## Recovering an infected file (false positive)

```php
$file = SecureUploadedFile::find($id);
$file->update(['scan_status' => 'clean']);
$storage->retrieve($file->identifier);   // file is still in quarantine path
// Manually move out of quarantine
```

Be sure before doing this — overriding a positive scan result is a security decision worth a paper trail.
