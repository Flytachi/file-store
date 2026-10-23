# Exceptions

The library exposes a single exception type. Everything that can go wrong
funnels through it.

---

## `FileStorageException`

```php
namespace Flytachi\FileStore;

class FileStorageException extends \Exception {}
```

A plain extension of `\Exception` — no custom methods, no codes, no extra
fields. The only distinguishing feature is the class name, so you can catch
this library's failures without catching unrelated `\Exception`s.

---

## When it's thrown

### From the constructor

| Condition | Message |
|-----------|---------|
| `$rootPath` is not writable | `Path not writable: <rootPath>` |
| `mkdir()` of the storage directory failed and the directory still does not exist | `Directory not created: <directory>` |
| Storage directory exists but is not writable | `Directory not writable: <directory>` |

### From `write()`

| Condition | Message |
|-----------|---------|
| `file_put_contents()` returned `false` | `Failed to write tmp file: <path>` |
| `rename()` returned `false` | `Failed to rename tmp into place: <path>` |
| Underlying `\Throwable` (I/O error, `\Random\RandomException` from `random_bytes`, etc.) | Original `getMessage()`; original exception is preserved as `$previous` |

### From `getFilePath()` (called internally by `write`, `read`, `has`, `del`)

Only when `isHash=false`:

| Condition | Message |
|-----------|---------|
| Key is `''`, `'.'`, `'..'`, or contains `/`, `\`, or `\0` | `Invalid key: <key>` |

### What does NOT throw

- `read()` on a missing key — returns `null`
- `has()` on a missing or expired key — returns `false`
- `del()` on a missing key — silent no-op
- `clear()` on individual `unlink()` failures — uses `@unlink`
- `keys()` on an empty directory — returns `[]`

These are documented to "soft fail" because they're the cache-style fast paths.
Treat them as "did we have it? yes/no" — not as I/O operations that might
error out.

---

## Catching and wrapping

```php
use Flytachi\FileStore\FileStorageException;

try {
    $store->write('session:abc', $payload, expireAtTimestamp: time() + 3600);
} catch (FileStorageException $e) {
    $logger->error('session store write failed', [
        'message'  => $e->getMessage(),
        'cause'    => $e->getPrevious()?->getMessage(),
        'causeCls' => $e->getPrevious()?->::class,
    ]);

    // re-throw, or fall back to a different cache, or surface to the user
    throw new RuntimeException('session unavailable', previous: $e);
}
```

The `$previous` chain is your friend — `random_bytes` failures arrive as
`\Random\RandomException`, filesystem errors as PHP `\Error` or `\Exception`
subclasses depending on the underlying call. Inspect it when debugging.

---

## Why a single exception type

Multiple specific exception classes (e.g. `WriteFailedException`,
`PermissionDeniedException`, `InvalidKeyException`) would let callers branch
on the kind of failure. In practice for a small file store, the only useful
branch is "did the operation succeed?" — every failure leaves the store in the
same state (the call was a no-op, ignore it or retry). Splitting the
hierarchy would add noise without any practical decision point.

If you genuinely need to distinguish error kinds, inspect `$e->getMessage()` or
the `$previous` chain. The exception class itself is intentionally flat.
