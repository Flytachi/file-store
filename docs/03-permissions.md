# Permissions

This is the most operationally tricky part of any file-backed store. Read it
once and you'll never debug "why is my queue file unwritable from PHP-FPM"
again.

---

## The defaults

```php
$store = new FileStorage('/var/app', 'cache/jobs');
// dirMode:  0770   →  rwxrwx---  (owner + group full, others none)
// fileMode: 0660   →  rw-rw----  (owner + group write, others none)
```

The defaults are designed for the most common production setup:
**PHP-FPM and a worker (or Nginx, or cron) in the same Unix group**, where
either side might write the file and both need to read/delete it.

---

## The umask trap (why the old code was broken)

PHP's `mkdir()` and `file_put_contents()` both honour the process `umask`. On
most Linux distros the default umask is `0022`, which **strips group write**.

```php
mkdir($dir, 0770);
// stat() shows:  0750  (drwxr-x---)   ← group lost its write bit
```

Same trap with files:

```php
file_put_contents($path, $data);   // created as 0666 & ~umask = 0644
// stat() shows:  0644  (-rw-r--r--)   ← group can't write
```

If the first process to write was, say, root (e.g. a system init script), the
file lands at `0644 root:root` and **the FPM user cannot overwrite it**. Worse,
they can't `unlink()` it either if the directory itself dropped group-write.
This is exactly the bug that drove the addition of `dirMode` / `fileMode`.

---

## The fix — `chmod()` after every write

`chmod()` ignores umask. The constructor and `write()` now apply mode
explicitly:

```php
if (!is_dir($this->directory)) {
    mkdir($this->directory, $this->dirMode, true);
    @chmod($this->directory, $this->dirMode);   // ← forces real 0770
}

// in write():
file_put_contents($tmp, $data, LOCK_EX);
@chmod($tmp, $this->fileMode);                  // ← forces real 0660
rename($tmp, $filePath);                        // mode is preserved across rename
```

The `@` prefix silences `chmod` warnings — if the file vanished or we lack
permission to chmod a pre-existing file, the operation is best-effort. This
matches the intent: **new files we create get the requested mode; we don't
crash if we encounter a pre-existing entry we don't own.**

---

## Why not `umask(0)` wrap?

A common "fix" you'll see online:

```php
$prev = umask(0);
mkdir($dir, 0770);
umask($prev);
```

Don't. Two problems:

1. **`umask` is process-global.** In PHP-FPM, a worker handles many requests sequentially. During the window between `umask(0)` and `umask($prev)`, *any* other code in the same process that creates files will write them world-writable.
2. **Exception unwind.** If something between the two calls throws, the umask stays `0` for the rest of the worker's life. Subtle, hard to spot.

`chmod()` after creation is local, idempotent, and has neither problem.

---

## Permission cheat sheet

| Scenario | `dirMode` / `fileMode` | Notes |
|----------|------------------------|-------|
| FPM + worker + cron in shared group (typical) | `0770 / 0660` (default) | Soft-secure. Group can read/write/delete. Others have no access. |
| Single-user setup (only one Unix account ever touches the store) | `0700 / 0600` | Strictest. Nobody outside the owner can even read the directory. |
| Kubernetes pods with no shared group | `0777 / 0666` | Last resort. Anyone-writable. Security scanners will complain. Prefer fsGroup or runAsGroup at the pod level. |
| Read-only deploy artefact | `0750 / 0640` | Writes will only succeed from the owner; group can read. Useful for build outputs consumed by web servers. |

Pick the tightest mode that still lets all your writers write.

---

## Migrating existing files after upgrading

If you upgrade an existing install, **the fix only applies to new files**.
Existing files keep whatever mode they were created with (possibly `0644
root:root`).

One-time cleanup:

```bash
# fix mode
sudo chmod -R u+rwX,g+rwX /var/app/cache/jobs/

# optional: re-own to your service account
sudo chown -R fpm-user:www-shared /var/app/cache/jobs/
```

After this, new writes go in at `0660` (or whatever you configured) and stay
consistent.

---

## Multi-host / network filesystems

This library is single-host. If your store lives on NFS, GlusterFS, or a CSI
volume mounted into multiple pods:

- The **atomic rename** invariant (see [04-concurrency.md](04-concurrency.md)) depends on `rename()` being atomic *on the same filesystem* — true for POSIX, but check your specific NFS/SMB config.
- `LOCK_EX` semantics on networked filesystems vary wildly. NFSv4 honours locks; older NFS modes are advisory at best.
- For genuinely distributed workloads, this isn't the right tool — reach for Redis, etcd, or a database.
