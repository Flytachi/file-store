# API Reference

The full public surface of `Flytachi\FileStore\FileStorage`.

---

## Constructor

```php
public function __construct(
    string $rootPath,
    string $folderName,
    bool   $isHash    = true,
    string $hmacType  = 'sha256',
    int    $dirMode   = 0770,
    int    $fileMode  = 0660,
)
```

| Parameter | Meaning |
|-----------|---------|
| `$rootPath` | Existing, writable parent directory. Trailing `/` is stripped. |
| `$folderName` | Sub-path under `$rootPath`. Created with `mkdir(..., recursive: true)` if missing. Leading `/` is stripped. |
| `$isHash` | When `true` (default), keys are run through HMAC to produce filenames. When `false`, the raw key becomes the filename — with path-traversal validation. |
| `$hmacType` | Any algorithm accepted by `hash_hmac()`. Ignored when `$isHash=false`. Empty string also disables hashing. |
| `$dirMode` | `chmod()`'d onto the storage directory after creation. Bypasses umask. |
| `$fileMode` | `chmod()`'d onto every file after write. Bypasses umask. |

**Side effects on construction:**

1. Verifies `is_writable($rootPath)` — throws if not.
2. Creates `$rootPath/$folderName` with `$dirMode` if missing, then explicit `chmod`.
   Another process creating it at the same moment is not an error.
3. Verifies `is_writable` on the storage directory — throws if not.
4. Derives `$dirKey` (HMAC secret) by normalising and `md5()`-ing the real path of the
   directory — the same directory gets the same key however its path was written
   (through a symlink, with `..`, with a trailing slash).

**Throws** `FileStorageException` on any of the above failures.

---

## write()

```php
public function write(
    string $key,
    mixed  $content,
    ?int   $expireAtTimestamp = null,
): void
```

Serialises `$content` and writes it to the file derived from `$key`.

- **Empty content short-circuits.** If `empty($content)` returns true (PHP semantics: `null`, `0`, `''`, `[]`, `false`, …), the call returns immediately without writing. This means **you cannot persist falsy values**.
- **TTL** — if `$expireAtTimestamp` is supplied, a `#^e:<ts>\n` header is prepended to the stored payload. Past timestamps are accepted; the entry will be considered expired immediately on first access.
- **Atomic** — writes to `$file.tmp.<random>`, `chmod`s to `$fileMode`, then `rename()`s into place. Readers see either the previous version or the new one.
- **Locking** — the temp file is written with `LOCK_EX`. Concurrent writes to the same key serialise on the lock; the last `rename()` wins.

**Throws** `FileStorageException` on:
- HMAC computation failure / invalid raw key
- `random_bytes()` exhaustion
- `file_put_contents()` returning `false`
- `rename()` returning `false`
- Any underlying I/O error (wrapped as `previous`)

---

## read()

```php
public function read(string $key): mixed
```

Returns the previously written content, or `null` if:
- the file doesn't exist
- the file is unreadable
- the TTL has expired (the file is unlinked as a side effect)

**No exception is thrown for "missing key".** This is by design — `read()` is
the cache-style fast path. If you need to distinguish "missing" from "I/O
error", check `has()` first or wrap the call.

> **Note on corrupt files.** The returned value is `unserialize($body)`. If the
> body is corrupt, PHP returns `false` and `read()` returns `false` — which is
> indistinguishable from a legitimately stored `false`. In practice, since
> `write()` rejects falsy values, a `false` return always indicates corruption.

---

## has()

```php
public function has(string $key): bool
```

Returns `true` if the key exists and is not expired. Returns `false`:
- when the file does not exist
- when the file cannot be opened
- when the entry has expired (and unlinks it)

Cheaper than `read()` — opens the file and reads only the first line to inspect
the TTL header, without loading the full body or running `unserialize()`.

---

## del()

```php
public function del(string $key): void
```

Unlinks the file for `$key`. Silent no-op if the file doesn't exist
(`safeUnlink` checks `is_file` first). Does not throw.

---

## keys()

```php
public function keys(): array
```

Returns a flat `array<string>` of the filenames in the storage directory,
obtained via `glob($directory . '/*')` and `basename()`.

- When `$isHash=true` (default), these are HMAC strings — **not** the original keys. You cannot reverse them.
- When `$isHash=false`, these are the raw keys.
- The list includes expired entries that have not yet been touched.
- Order matches `glob()`'s default (alphabetical on most systems, but not guaranteed).

---

## clear()

```php
public function clear(): void
```

Iterates the directory and unlinks every entry via `safeUnlink`. The directory
itself is preserved. Does not throw on individual unlink failures (uses `@unlink`).

---

## gc()

```php
public function gc(int $tmpMaxAgeSeconds = 3600): int
```

Bulk-evicts expired TTL entries and cleans up stale tmp files from crashed
writers. Returns the number of files deleted.

**What gets deleted:**

1. **Expired TTL entries** — for each non-tmp file in the directory, reads the first line, parses the `#^e:<ts>` header, and unlinks if the timestamp is in the past. Files without a TTL header are skipped (TTL is opt-in, not default).
2. **Stale tmp files** — any file with `.tmp.` in its name whose `mtime` is older than `$tmpMaxAgeSeconds`. These are left over from writers that died between `file_put_contents()` and `rename()` (e.g. SIGKILL'd PHP-FPM workers). The 1-hour default is conservative — adjust down if your writes finish in seconds, up if you have legitimately long-running writes.

**Streaming iteration.** Uses `DirectoryIterator` instead of `glob()` to avoid
loading the full filename list into memory. Safe for directories with hundreds
of thousands of entries.

**Designed for cron.** Call from a periodic job; the cost is O(n) on the number
of files in the directory, plus one `fopen`+`fgets` per non-tmp entry.

```php
// In a cron script:
$store   = new FileStorage('/var/app', 'cache/jobs');
$deleted = $store->gc();
$logger->info('file-store gc', ['deleted' => $deleted]);
```

Without `gc()`, TTL eviction only happens on `read()`/`has()` access —
un-accessed expired keys persist on disk indefinitely.

**Does not throw.** Individual `unlink`/`fopen` failures are silently
swallowed; the iteration continues with the next entry.

---

## FileStorageException

```php
namespace Flytachi\FileStore;

class FileStorageException extends \Exception {}
```

The single exception type. `getPrevious()` carries the original PHP exception
(`\Random\RandomException`, `\Throwable` from I/O, etc.) when the failure
originated in a wrapped underlying call. See [06-exceptions.md](06-exceptions.md).
