---
title: Artisan Commands
---

# Artisan Commands

Two maintenance commands. Both are safe to schedule via Laravel's scheduler.

## `security:cleanup-files`

Removes expired or old uploaded files from disk and database.

```bash
php artisan security:cleanup-files
php artisan security:cleanup-files --older-than=30
php artisan security:cleanup-files --dry-run
```

| Option | Default | Notes |
|---|---|---|
| `--older-than={days}` | (config-driven) | Override the retention window for this run |
| `--dry-run` | false | Print what would be deleted without deleting |
| `--disk={name}` | all configured disks | Limit cleanup to a specific disk |

The command processes files whose `expires_at` has passed (if set) and files older than the retention window without recent access. Deletion fires no events — pair with your own pruning audit if you need a trail.

Schedule in `app/Console/Kernel.php`:

```php
$schedule->command('security:cleanup-files')->daily();
```

## `security:scan-quarantine`

Processes the quarantine queue for the async scanning workflow.

```bash
php artisan security:scan-quarantine
php artisan security:scan-quarantine --limit=200
```

| Option | Default | Notes |
|---|---|---|
| `--limit={n}` | 100 | Maximum files to process per run |
| `--driver={name}` | configured driver | Override the scanner for this run (e.g. force `virustotal` while debugging) |

For each pending file:

1. Runs the configured scanner.
2. On clean: moves the file to its normal storage location, sets `scan_status = clean`, fires `FileUploaded`.
3. On infected: keeps the file in quarantine, sets `scan_status = infected`, fires `MalwareDetected`.
4. On scanner error: sets `scan_status = error`. The file stays in quarantine and a subsequent run will retry.

Schedule frequently in async mode:

```php
$schedule->command('security:scan-quarantine')->everyFiveMinutes();
```

The frequency should match how quickly your users expect their uploads to become available. Sync mode (`async = false`) doesn't need this command.

## When to use which

- `cleanup-files` — always. Free disk space + DB rows for orphaned / expired uploads.
- `scan-quarantine` — only when `malwareScanning.async = true`. Without async mode, scanning happens inline and there's nothing to drain.
