---
title: Requirements
---

# Requirements

## PHP

- PHP 8.2 or higher
- `ext-fileinfo` (bundled with PHP) — required for MIME detection against file contents

## Laravel

- Laravel 10 / 11 / 12

## Composer dependencies

The runtime requirements pulled in automatically:

- `artisanpack-ui/core: ^1.0`
- `symfony/mime: ^6.0|^7.0` — used for MIME type normalization

## Optional system dependencies

Only required if you enable the corresponding scanner driver.

### ClamAV (for `clamav` scanner)

You can run ClamAV in one of two modes — the package supports both.

| Mode | What you need | How to enable |
|---|---|---|
| Socket (recommended for production) | `clamd` running with a Unix socket | Set `CLAMAV_SOCKET_PATH=/var/run/clamav/clamd.sock` |
| Binary (simpler for low-volume / batch) | `clamscan` binary on PATH | Set `CLAMAV_BINARY_PATH=/usr/bin/clamscan` |

The scanner tries socket first, falls back to binary. The socket mode is dramatically faster — it avoids spawning a new process per scan and reuses the loaded virus signature database.

Install on Debian / Ubuntu:

```bash
sudo apt install clamav clamav-daemon
sudo systemctl enable --now clamav-daemon
sudo freshclam   # initial signature DB download
```

On macOS (Homebrew):

```bash
brew install clamav
```

### VirusTotal (for `virustotal` scanner)

- A [VirusTotal API key](https://www.virustotal.com/) (free tier works for low-volume use; consider the paid tier for production)
- Set `VIRUSTOTAL_API_KEY=...` in your `.env`

Note: VirusTotal uploads file content to a third-party service. If you handle confidential or regulated data, use ClamAV instead — it scans locally.

## Storage

The package writes to the default Laravel filesystem disk by default. For production you'll typically want:

- A dedicated disk for secure files (S3, GCS, or local outside the public root)
- A separate quarantine path (defaults to `storage/app/quarantine`)

See [Configuration](configuration.md) for both.
