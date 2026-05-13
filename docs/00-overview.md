# File Store — Documentation Overview

**File Store** is a minimal, dependency-free key/value file storage for PHP. It
gives you a flat namespace of keys backed by individual files on disk, with
TTL, atomic writes, and shared-group permissions.

---

## What's in the box

```
Flytachi\FileStore\
    ├── FileStorage              — the only public class
    │     ├── write(key, value, ?ttl)
    │     ├── read(key)
    │     ├── has(key)
    │     ├── del(key)
    │     ├── keys()
    │     ├── clear()
    │     └── gc(tmpMaxAgeSeconds = 3600)
    └── FileStorageException     — single exception type
```

No interfaces, no abstract bases, no service locator. One class, one exception.

---

## How an entry lives on disk

```
$rootPath/$folderName/
    ├── <hmac-of-key-1>          ← optionally prefixed with #^e:<unix-ts>\n
    ├── <hmac-of-key-2>
    └── …
```

The body of each file is **PHP-serialised** content (`serialize()`). If a TTL
was supplied at write time, the file begins with a single header line:

```
#^e:1715600000
a:2:{s:6:"status";s:6:"queued";s:8:"attempts";i:0;}
```

`read()` strips and validates this header; if the entry has expired, the file
is `unlink`'d on the spot and `read()` returns `null`.

---

## Write lifecycle (post-fix, since 1.0.6)

```
write(key, value):
    1. serialize($value) → $data
    2. optionally prepend "#^e:<ts>\n"
    3. write to $filePath.tmp.<random>     ← LOCK_EX
    4. chmod($tmp, $fileMode)              ← bypasses umask
    5. rename($tmp, $filePath)             ← POSIX-atomic on same fs
    6. on any failure: unlink the tmp file, throw FileStorageException
```

Readers either see the **previous** version of the file or the **new** one —
never a torn write. See [04-concurrency.md](04-concurrency.md).

---

## Read lifecycle

```
read(key):
    1. file_get_contents($filePath)        ← null if missing or unreadable
    2. parse optional #^e: header
    3. if expired → unlink, return null
    4. unserialize remainder, return
```

`has()` follows the same TTL logic but only reads the first line for cheapness.

---

## Documentation Index

| # | File | Contents |
|---|------|----------|
| 00 | [00-overview.md](00-overview.md) | This document |
| 01 | [01-quick-start.md](01-quick-start.md) | Minimal setup, common patterns, when to use what |
| 02 | [02-api-reference.md](02-api-reference.md) | Every public method, parameters, return values |
| 03 | [03-permissions.md](03-permissions.md) | `dirMode` / `fileMode`, umask trap, multi-user setups |
| 04 | [04-concurrency.md](04-concurrency.md) | Atomic write, LOCK_EX, race conditions |
| 05 | [05-security.md](05-security.md) | HMAC filenames, path-traversal guard, `unserialize` notes |
| 06 | [06-exceptions.md](06-exceptions.md) | `FileStorageException` — when it's thrown, how to wrap |
