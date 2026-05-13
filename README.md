# File Store

[![Latest Version on Packagist](https://img.shields.io/packagist/v/flytachi/file-store.svg)](https://packagist.org/packages/flytachi/file-store)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)

A small, dependency-free key/value file store for PHP — atomic writes, optional
TTL, optional HMAC-hashed filenames, and group-shared file permissions for
multi-process setups (PHP-FPM + Nginx, queue workers, cron).

---

## Requirements

- PHP >= 8.3
- ext-mbstring

## Installation

```bash
composer require flytachi/file-store
```

---

## Quick Start

```php
use Flytachi\FileStore\FileStorage;

$store = new FileStorage(
    rootPath:   '/var/app',
    folderName: 'cache/jobs',
);

// Write a value
$store->write('job:42', ['status' => 'queued', 'attempts' => 0]);

// Write with TTL — expires after 1 hour
$store->write('session:abc', $payload, expireAtTimestamp: time() + 3600);

// Read
$job = $store->read('job:42');         // mixed|null

// Check presence (also checks TTL)
if ($store->has('session:abc')) { /* ... */ }

// Delete
$store->del('job:42');

// Enumerate / wipe
$store->keys();     // array of filenames in the store
$store->clear();    // unlink all entries

// Bulk-evict expired entries + crashed-writer tmp files (run from cron)
$store->gc();       // returns count of deleted files
```

---

## Constructor

```php
new FileStorage(
    string $rootPath,                  // existing, writable directory
    string $folderName,                // subdirectory inside rootPath (created if missing)
    bool   $isHash    = true,          // hash keys → HMAC filenames; off = raw key as filename
    string $hmacType  = 'sha256',      // any hash_hmac() algo
    int    $dirMode   = 0770,          // permissions for the storage directory
    int    $fileMode  = 0660,          // permissions for written files
);
```

`$dirMode` / `$fileMode` are applied **after** `mkdir` / `file_put_contents` via
explicit `chmod()` calls — so the resulting permissions are exactly what you
asked for, regardless of the process umask. See
[docs/03-permissions.md](docs/03-permissions.md) for the full story.

---

## Features at a Glance

| Feature | How it works |
|---|---|
| **Atomic write** | Writes go to `*.tmp.<random>`, then `rename()` into place. Readers never see a half-written file. |
| **TTL** | A `#^e:<unix-ts>\n` prefix line; expired entries are unlinked on first access. |
| **HMAC filenames** | When `isHash=true`, the key is run through `hash_hmac($hmacType, $key, dirKey)` — directory listings reveal no key contents. |
| **Path-traversal guard** | When `isHash=false`, raw keys are validated — `/`, `\`, `\0`, `.`, `..`, and empty strings are rejected. |
| **Group-writable mode** | Default `0770/0660` lets PHP-FPM and Nginx (or any two processes in the same group) share the store. |
| **Single dependency** | Only `ext-mbstring` for `mb_check_encoding` / `mb_convert_encoding` in directory-key normalisation. |

---

## When to Use This

- **Job payloads** shared between a web process and a worker
- **Per-key caches** that need TTL and group-shared writability
- **Lightweight session/blob storage** without pulling in Redis/Memcached
- Any case where SQLite is too heavy and APCu is too local

For high-throughput hot caches or multi-host deployments, reach for something
network-aware (Redis, Memcached) — this is a single-host, filesystem-backed
store.

---

## Documentation

| File | Topic |
|------|-------|
| [00-overview.md](docs/00-overview.md) | Architecture, file layout, lifecycle of an entry |
| [01-quick-start.md](docs/01-quick-start.md) | Minimal setup, common patterns |
| [02-api-reference.md](docs/02-api-reference.md) | Every public method, every parameter |
| [03-permissions.md](docs/03-permissions.md) | `dirMode` / `fileMode`, umask, multi-user setups |
| [04-concurrency.md](docs/04-concurrency.md) | Atomic write, locking, race-conditions |
| [05-security.md](docs/05-security.md) | HMAC hashing, path traversal, `unserialize` notes |
| [06-exceptions.md](docs/06-exceptions.md) | `FileStorageException` — when it's thrown |

---

## License

MIT License. See [LICENSE](LICENSE).
