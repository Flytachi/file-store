# Concurrency & Atomicity

How File Store behaves when multiple processes hit the same key at the same
time, and what guarantees you can rely on.

---

## The atomic write contract

```php
$store->write('key', $value);
```

This call upholds the following invariant on any POSIX filesystem
(ext4, xfs, apfs, …):

> A concurrent reader sees **either** the previous full content of the file
> **or** the new full content. Never a partial mix.

It is implemented as:

```php
$tmp = $filePath . '.tmp.' . bin2hex(random_bytes(6));
file_put_contents($tmp, $data, LOCK_EX);      // 1. write tmp file (locked)
chmod($tmp, $this->fileMode);                  // 2. set permissions
rename($tmp, $filePath);                       // 3. atomic rename into place
```

Step 3 is what makes this safe. `rename(2)` on a POSIX filesystem is a single
directory-entry update — at any instant, the path `$filePath` resolves to
exactly one inode. A reader opening the path either sees the old inode or the
new one; never an empty file, never a partially-written file.

If anything fails between steps 1 and 3, the tmp file is `unlink`'d in the
catch block, so you don't leak debris on errors. The one exception is **process
death between steps 1 and 3** — SIGKILL, OOM-kill, machine crash — where no
PHP code runs to clean up. Those orphaned `.tmp.*` files are cleaned by
`gc()` (see [02-api-reference.md](02-api-reference.md#gc)).

---

## Locking

`file_put_contents($tmp, $data, LOCK_EX)` acquires an exclusive flock on the
tmp file. Since the tmp filename is randomised, **the lock has no contention
purpose between writers** — each writer has its own tmp file. The lock matters
only for partially-written tmp files in error paths: a crashed writer's tmp
file is never read by anyone else (random suffix), so the lock is mostly
ceremonial.

For **reader-side concurrency**, no `LOCK_SH` is acquired. Readers rely on the
atomic-rename invariant, not on shared locks. This means:

- `read()` and `has()` can run in parallel without coordination.
- A reader can observe the **previous** version while a writer is in the middle of step 1 — and then observe the **new** version after step 3.
- There is no read-modify-write primitive. If you need one, lock externally.

---

## What's NOT thread-safe

### Read-modify-write

```php
// ❌ Race-prone:
$counter = (int) $store->read('counter');
$store->write('counter', $counter + 1);
```

Two concurrent processes can both read `5`, both write `6`. Use a real
database, or wrap in a file lock yourself:

```php
$fp = fopen('/var/app/locks/counter.lock', 'c+');
flock($fp, LOCK_EX);
$counter = (int) $store->read('counter');
$store->write('counter', $counter + 1);
flock($fp, LOCK_UN);
fclose($fp);
```

### `clear()` vs concurrent `write()`

`clear()` walks the directory and `unlink`s every file. If a writer is in the
middle of `rename()` during a `clear()`, the new file may or may not be
deleted. Don't rely on `clear()` for synchronisation; it's a "wipe everything
when nobody is writing" tool.

### `keys()` snapshot

`keys()` returns a snapshot from a single `glob()` call. Entries may have been
added or deleted by the time you iterate. Treat it as "approximate at call
time".

---

## TTL race conditions

TTL is checked lazily, and the eviction (`safeUnlink`) happens during `read()` /
`has()`. Two readers hitting an expired key at the same time will both call
`unlink` — but `safeUnlink` uses `@unlink`, so the second one silently no-ops.
No exception is raised.

A race that **can** happen: writer creates `key` with TTL 60s, then 61s later
two readers race against a third writer rewriting the same key with a fresh
TTL. The two readers may unlink the file the third writer just renamed in,
because they read the old TTL line and don't know about the rewrite. This
window is microseconds wide, but it's there. If your workload has writers that
"refresh" TTLs on hot keys, accept that occasional spurious evictions can
happen.

---

## Performance characteristics

| Operation | Cost | Notes |
|-----------|------|-------|
| `write()` | `~3 syscalls` (open + write + rename) | Plus `chmod`, plus `random_bytes`. Negligible for payloads < 64 KiB. |
| `read()` | `1 syscall` | `file_get_contents` does one `open` + `read` + `close`. |
| `has()` | `2 syscalls` | `is_file` stat + `fopen` for first-line read. |
| `del()` | `2 syscalls` | `is_file` stat + `unlink`. |
| `keys()` | O(n) | `glob` + n × `basename`. |
| `clear()` | O(n) | `glob` + n × `safeUnlink`. |

For large payloads, the dominant cost is the actual disk I/O during `write()`.
There is no buffering — every call is durable as soon as `file_put_contents`
returns (modulo OS page cache, which `fsync` would force; this library does
not call `fsync`).

---

## When this isn't enough

If you need any of:
- read-modify-write atomicity
- transactional multi-key writes
- pub/sub or change notifications
- cross-host coordination

…use a real database or a coordination service (Redis, etcd, ZooKeeper). This
library is a single-host file cache, not a distributed system.
