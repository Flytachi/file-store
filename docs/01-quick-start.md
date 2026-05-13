# Quick Start

## 1. Install

```bash
composer require flytachi/file-store
```

## 2. Instantiate

```php
use Flytachi\FileStore\FileStorage;

$store = new FileStorage(
    rootPath:   '/var/app',
    folderName: 'cache/jobs',
);
```

This creates `/var/app/cache/jobs/` if it doesn't exist (mode `0770`), and
verifies that `/var/app/` is writable by the current process. The directory
key — used as the HMAC secret for filenames — is derived from the full path.

Throws `FileStorageException` if:
- `$rootPath` is not writable
- the storage directory cannot be created or is not writable

## 3. Read / write / delete

```php
$store->write('user:42', ['name' => 'Alice', 'role' => 'admin']);

$user = $store->read('user:42');    // ['name' => 'Alice', 'role' => 'admin']

$store->has('user:42');             // true
$store->del('user:42');
$store->has('user:42');             // false
```

`read()` returns `null` if the key is missing or expired.

## 4. With TTL

```php
// expire 30 minutes from now
$store->write(
    'session:abc123',
    ['user_id' => 42, 'roles' => ['admin']],
    expireAtTimestamp: time() + 1800,
);

// after 1801s:
$store->read('session:abc123');    // null — file auto-deleted
$store->has('session:abc123');     // false
```

TTL is checked **lazily** — expired files are unlinked the first time you call
`read()`, `has()`, or anything that touches that key. If you never touch an
expired key, the file stays on disk. For bulk eviction, run `gc()` from a cron
(see below).

## 5. Enumerate / wipe

```php
foreach ($store->keys() as $filename) {
    // when isHash=true, $filename is the HMAC, not the original key
    echo $filename, PHP_EOL;
}

$store->clear();    // delete every file in the directory
```

---

## Periodic cleanup with `gc()`

```php
// bin/file-store-gc.php — run from cron, e.g. every hour
$store = new FileStorage('/var/app', 'cache/jobs');
$deleted = $store->gc();
$logger->info('file-store gc', ['deleted' => $deleted]);
```

```cron
0 * * * * php /var/app/bin/file-store-gc.php
```

`gc()` walks the directory once and deletes:
- entries whose TTL has expired
- stale `.tmp.*` files older than 1 hour (left from crashed writers)

Returns the number of files deleted. Safe to run while writers are active.

---

## Common patterns

### Shared write between PHP-FPM and a worker

```php
// In php-fpm pool config:
//   user  = winter
//   group = www-shared

// In worker systemd unit:
//   User=worker
//   Group=www-shared

$store = new FileStorage(
    rootPath:   '/var/app',
    folderName: 'queue',
    // defaults: dirMode=0770, fileMode=0660
);
```

Both processes are in `www-shared`, both can read and write. See
[03-permissions.md](03-permissions.md) for the why.

### Disable hashing — keys-as-filenames

```php
$store = new FileStorage(
    rootPath:   __DIR__ . '/storage',
    folderName: 'configs',
    isHash:     false,
);

$store->write('production.json', $cfg);
// → /storage/configs/production.json
```

When `isHash=false`, raw keys are validated to prevent path traversal. Empty,
`.`, `..`, and any key containing `/`, `\`, or `\0` throws
`FileStorageException`. See [05-security.md](05-security.md).

### Custom permissions for non-shared groups

```php
// Kubernetes pods with no shared group — anyone-writable:
$store = new FileStorage(
    rootPath:   '/data',
    folderName: 'shared',
    dirMode:    0777,
    fileMode:   0666,
);
```

Use `0777/0666` only when you genuinely have no group in common. See
[03-permissions.md](03-permissions.md) for the trade-offs.

### Catching failures

```php
use Flytachi\FileStore\FileStorageException;

try {
    $store->write('big-payload', $data);
} catch (FileStorageException $e) {
    $logger->error('Store write failed', [
        'key' => 'big-payload',
        'err' => $e->getMessage(),
        'cause' => $e->getPrevious()?->getMessage(),
    ]);
}
```

All exposed failures funnel through `FileStorageException` — see
[06-exceptions.md](06-exceptions.md).
