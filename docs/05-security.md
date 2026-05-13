# Security Notes

A short list of what this library does and doesn't protect against.

---

## HMAC-hashed filenames (default)

When `isHash=true` (the default), every key is run through:

```php
hash_hmac($hmacType, $key, $this->dirKey);
```

…where `$dirKey` is an `md5` of the normalised storage directory path.

This achieves two things:

1. **Filenames don't reveal key contents.** If you write `account:42:secret`, the filename on disk is a hex digest — not the original string. A `ls` doesn't leak the namespace structure.
2. **Path traversal is impossible.** Hex digests can't contain `/`, `..`, or `\0`.

The `$dirKey` is **not** a cryptographic secret — anyone who can read the
directory path can compute it. The HMAC here is a **filename derivation
function**, not an authentication mechanism. Do not rely on it to protect
against an attacker who already has filesystem access.

---

## Path traversal guard (when `isHash=false`)

When you opt out of hashing — `new FileStorage(..., isHash: false)` — the raw
key becomes the filename. The library validates the key on every call:

```php
if ($key === '' || $key === '.' || $key === '..'
    || str_contains($key, '/')
    || str_contains($key, '\\')
    || str_contains($key, "\0")
) {
    throw new FileStorageException("Invalid key: $key");
}
```

This blocks the classic attacks:

| Attempt | Result |
|---------|--------|
| `'../../etc/passwd'` | rejected (contains `/`) |
| `'..\\..\\windows\\config'` | rejected (contains `\`) |
| `"\0../etc/shadow"` | rejected (contains `\0`) |
| `'.'` / `'..'` / `''` | rejected (exact match) |

It does **not** validate that the key is a "good" filename in other ways —
extremely long keys, keys containing control characters, or filesystem-reserved
names on Windows (e.g. `CON`, `NUL`) are passed through. If you're accepting
keys from untrusted sources, either keep `isHash=true` (recommended) or
validate further in your application layer.

---

## `unserialize()` and untrusted input

`read()` calls `unserialize()` on the file contents. PHP's `unserialize` can
trigger arbitrary object instantiation and `__wakeup`/`__destruct` magic
methods — if an attacker can write to your storage directory, they can
construct any class loaded in your application by writing a crafted payload.

**This means: the storage directory must be considered as trusted as your
application code.** Don't expose it to:
- world-writable mounts
- user-uploadable paths
- shared volumes with untrusted tenants

If you must accept potentially-tainted bytes, consider a different storage
format (JSON via `json_decode`) and a fork of this library — or store opaque
bytes and parse them yourself after `read()`.

---

## Disclosure surface

| Path | What's visible |
|------|----------------|
| Directory listing | Filenames (HMAC digests by default; raw keys if `isHash=false`) |
| File modification time | When the entry was last written |
| File size | Approximate size of the serialised payload |
| File contents | The full serialised payload + optional TTL header |

If any of these matter for your threat model, encrypt the payload before
calling `write()` — this library does no encryption at rest.

---

## What the library does NOT do

- **No encryption.** Files are plaintext-serialised on disk.
- **No integrity check.** A modified file is read back as-is; you'll only notice if `unserialize` fails.
- **No access control beyond Unix mode bits.** Two processes in the same group can read and overwrite each other's entries.
- **No quota.** A runaway `write()` loop can fill the disk.
- **No automatic cleanup of expired entries.** TTL is lazy-evicted; un-accessed expired files persist on disk until you iterate and read them, or call `clear()`.

These are deliberate omissions to keep the library small. Build them in your
application layer if you need them.
