<?php

declare(strict_types=1);

namespace Flytachi\FileStore\Tests\Unit;

use Flytachi\FileStore\FileStorage;
use Flytachi\FileStore\FileStorageException;
use PHPUnit\Framework\TestCase;

class FileStorageTest extends TestCase
{
    private string $root;
    private string $folder;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/file-store-tests-' . bin2hex(random_bytes(4));
        $this->folder = 'store';
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->root);
    }

    private function rmrf(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->rmrf($path . '/' . $entry);
        }
        @rmdir($path);
    }

    private function newStore(bool $isHash = true): FileStorage
    {
        return new FileStorage($this->root, $this->folder, isHash: $isHash);
    }

    private function storeDir(): string
    {
        return $this->root . '/' . $this->folder;
    }

    // ─── Construction ────────────────────────────────────────────────────

    public function testConstructorCreatesDirectoryIfMissing(): void
    {
        $this->assertDirectoryDoesNotExist($this->storeDir());
        $this->newStore();
        $this->assertDirectoryExists($this->storeDir());
    }

    public function testConstructorThrowsWhenRootNotWritable(): void
    {
        $this->expectException(FileStorageException::class);
        $this->expectExceptionMessage('Path not writable');
        new FileStorage('/nonexistent/path/that/should/not/exist', 'x');
    }

    public function testConstructorAppliesDirMode(): void
    {
        new FileStorage($this->root, $this->folder, dirMode: 0775);
        clearstatcache();
        $mode = fileperms($this->storeDir()) & 0777;
        $this->assertSame(0775, $mode);
    }

    public function testConstructorAcceptsExistingDirectory(): void
    {
        mkdir($this->storeDir(), 0770, true);
        $store = $this->newStore();
        $this->assertInstanceOf(FileStorage::class, $store);
    }

    // ─── write / read roundtrip ──────────────────────────────────────────

    public function testWriteReadString(): void
    {
        $store = $this->newStore();
        $store->write('greeting', 'hello world');
        $this->assertSame('hello world', $store->read('greeting'));
    }

    public function testWriteReadArray(): void
    {
        $store = $this->newStore();
        $payload = ['user' => 'Alice', 'roles' => ['admin', 'editor'], 'age' => 42];
        $store->write('user:1', $payload);
        $this->assertSame($payload, $store->read('user:1'));
    }

    public function testWriteReadObject(): void
    {
        $store = $this->newStore();
        $obj = new \stdClass();
        $obj->name = 'Bob';
        $obj->ts = 12345;
        $store->write('obj', $obj);
        $back = $store->read('obj');
        $this->assertEquals($obj, $back);
    }

    public function testReadMissingReturnsNull(): void
    {
        $store = $this->newStore();
        $this->assertNull($store->read('nope'));
    }

    public function testWriteEmptyContentIsNoop(): void
    {
        $store = $this->newStore();
        $store->write('x', '');
        $store->write('x', null);
        $store->write('x', 0);
        $store->write('x', []);
        $this->assertFalse($store->has('x'));
    }

    // ─── has / del ───────────────────────────────────────────────────────

    public function testHasMissingReturnsFalse(): void
    {
        $store = $this->newStore();
        $this->assertFalse($store->has('missing'));
    }

    public function testHasPresentReturnsTrue(): void
    {
        $store = $this->newStore();
        $store->write('present', 'value');
        $this->assertTrue($store->has('present'));
    }

    public function testDelRemovesEntry(): void
    {
        $store = $this->newStore();
        $store->write('key', 'value');
        $this->assertTrue($store->has('key'));
        $store->del('key');
        $this->assertFalse($store->has('key'));
    }

    public function testDelMissingIsNoop(): void
    {
        $store = $this->newStore();
        $store->del('never-existed');
        $this->assertFalse($store->has('never-existed'));
    }

    // ─── TTL ─────────────────────────────────────────────────────────────

    public function testTtlFutureKeepsEntry(): void
    {
        $store = $this->newStore();
        $store->write('alive', 'data', time() + 3600);
        $this->assertSame('data', $store->read('alive'));
        $this->assertTrue($store->has('alive'));
    }

    public function testTtlPastReturnsNullAndUnlinks(): void
    {
        $store = $this->newStore();
        $store->write('dead', 'data', time() - 10);
        $this->assertNull($store->read('dead'));
        $this->assertCount(0, $store->keys());
    }

    public function testTtlPastHasIsFalseAndUnlinks(): void
    {
        $store = $this->newStore();
        $store->write('dead', 'data', time() - 10);
        $this->assertFalse($store->has('dead'));
        $this->assertCount(0, $store->keys());
    }

    // ─── keys / clear ────────────────────────────────────────────────────

    public function testKeysReturnsHashedFilenamesByDefault(): void
    {
        $store = $this->newStore();
        $store->write('a', 1);
        $store->write('b', 2);
        $keys = $store->keys();
        $this->assertCount(2, $keys);
        foreach ($keys as $k) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $k);
        }
    }

    public function testKeysExcludesTmpFiles(): void
    {
        $store = $this->newStore();
        $store->write('real', 'data');
        // Inject a fake tmp file simulating an in-flight or crashed writer
        file_put_contents($this->storeDir() . '/fakehash.tmp.deadbeef', 'inflight');

        $keys = $store->keys();
        $this->assertCount(1, $keys);
        foreach ($keys as $k) {
            $this->assertStringNotContainsString('.tmp.', $k);
        }
    }

    public function testClearRemovesAllEntries(): void
    {
        $store = $this->newStore();
        $store->write('a', 1);
        $store->write('b', 2);
        $store->write('c', 3);
        $store->clear();
        $this->assertCount(0, $store->keys());
    }

    // ─── Atomic write — no tmp leakage on success ────────────────────────

    public function testWriteLeavesNoTmpFile(): void
    {
        $store = $this->newStore();
        $store->write('atomic', str_repeat('x', 10000));

        $stragglers = glob($this->storeDir() . '/*.tmp.*');
        $this->assertSame([], $stragglers);
    }

    public function testWriteAppliesFileMode(): void
    {
        $store = new FileStorage($this->root, $this->folder, fileMode: 0664);
        $store->write('mode-check', 'data');

        clearstatcache();
        $files = glob($this->storeDir() . '/*');
        $this->assertCount(1, $files);
        $mode = fileperms($files[0]) & 0777;
        $this->assertSame(0664, $mode);
    }

    public function testWriteOverwritesExistingValue(): void
    {
        $store = $this->newStore();
        $store->write('k', 'first');
        $store->write('k', 'second');
        $this->assertSame('second', $store->read('k'));
    }

    // ─── isHash=false path traversal guard ───────────────────────────────

    public function testRawKeyForwardSlashRejected(): void
    {
        $store = $this->newStore(isHash: false);
        $this->expectException(FileStorageException::class);
        $this->expectExceptionMessage('Invalid key');
        $store->write('../escape', 'data');
    }

    public function testRawKeyBackslashRejected(): void
    {
        $store = $this->newStore(isHash: false);
        $this->expectException(FileStorageException::class);
        $store->write('..\\escape', 'data');
    }

    public function testRawKeyNullByteRejected(): void
    {
        $store = $this->newStore(isHash: false);
        $this->expectException(FileStorageException::class);
        $store->write("safe\0../etc/passwd", 'data');
    }

    public function testRawKeyEmptyRejected(): void
    {
        $store = $this->newStore(isHash: false);
        $this->expectException(FileStorageException::class);
        $store->write('', 'data');
    }

    public function testRawKeyDotRejected(): void
    {
        $store = $this->newStore(isHash: false);
        $this->expectException(FileStorageException::class);
        $store->write('.', 'data');
    }

    public function testRawKeyDoubleDotRejected(): void
    {
        $store = $this->newStore(isHash: false);
        $this->expectException(FileStorageException::class);
        $store->write('..', 'data');
    }

    public function testRawKeySafeStringAccepted(): void
    {
        $store = $this->newStore(isHash: false);
        $store->write('production.json', ['env' => 'prod']);
        $this->assertSame(['env' => 'prod'], $store->read('production.json'));
        // Should land on disk with the literal key as the filename
        $this->assertFileExists($this->storeDir() . '/production.json');
    }

    public function testHashedKeyWithSlashIsAccepted(): void
    {
        // With isHash=true, even traversal-looking keys are safe (they become hex)
        $store = $this->newStore();
        $store->write('../../etc/passwd', 'safe-value');
        $this->assertSame('safe-value', $store->read('../../etc/passwd'));
    }

    // ─── gc() ────────────────────────────────────────────────────────────

    public function testGcRemovesExpiredEntries(): void
    {
        $store = $this->newStore();
        $store->write('alive', 'a', time() + 3600);
        $store->write('dead1', 'b', time() - 10);
        $store->write('dead2', 'c', time() - 99999);
        $store->write('forever', 'd');

        $deleted = $store->gc();
        $this->assertSame(2, $deleted);

        $this->assertSame('a', $store->read('alive'));
        $this->assertSame('d', $store->read('forever'));
        $this->assertCount(2, $store->keys());
    }

    public function testGcReturnsZeroWhenNothingExpired(): void
    {
        $store = $this->newStore();
        $store->write('a', 1, time() + 3600);
        $store->write('b', 2);
        $this->assertSame(0, $store->gc());
    }

    public function testGcCleansStaleTmpFiles(): void
    {
        $store = $this->newStore();

        $stale = $this->storeDir() . '/abc.tmp.deadbeef';
        $fresh = $this->storeDir() . '/xyz.tmp.cafebabe';
        file_put_contents($stale, 'crash');
        file_put_contents($fresh, 'inflight');
        touch($stale, time() - 7200); // 2h old
        touch($fresh, time());        // just now

        $deleted = $store->gc(); // default threshold = 3600

        $this->assertSame(1, $deleted);
        $this->assertFileDoesNotExist($stale);
        $this->assertFileExists($fresh);
    }

    public function testGcRespectsCustomTmpAge(): void
    {
        $store = $this->newStore();
        $tmp = $this->storeDir() . '/abc.tmp.deadbeef';
        file_put_contents($tmp, 'x');
        touch($tmp, time() - 120); // 2 minutes old

        // Default 3600s — should NOT remove it
        $this->assertSame(0, $store->gc());
        $this->assertFileExists($tmp);

        // 60s threshold — SHOULD remove it
        $this->assertSame(1, $store->gc(tmpMaxAgeSeconds: 60));
        $this->assertFileDoesNotExist($tmp);
    }

    public function testGcOnEmptyDirectory(): void
    {
        $store = $this->newStore();
        $this->assertSame(0, $store->gc());
    }

    public function testGcOnMixedDirectory(): void
    {
        $store = $this->newStore();
        $store->write('alive', 'a', time() + 3600);
        $store->write('dead', 'b', time() - 10);
        $store->write('forever', 'c');

        $stale = $this->storeDir() . '/abc.tmp.deadbeef';
        $fresh = $this->storeDir() . '/xyz.tmp.cafebabe';
        file_put_contents($stale, 'crash');
        file_put_contents($fresh, 'inflight');
        touch($stale, time() - 7200);

        $this->assertSame(2, $store->gc()); // expired entry + stale tmp
        $this->assertCount(2, $store->keys()); // alive + forever (fresh tmp excluded)
    }

    // ─── Misc ────────────────────────────────────────────────────────────

    public function testWriteIsDeterministicForSameKey(): void
    {
        $a = new FileStorage($this->root, $this->folder);
        $b = new FileStorage($this->root, $this->folder);
        $a->write('shared', 'A');
        $this->assertSame('A', $b->read('shared'));
    }

    public function testDifferentFoldersProduceDifferentFiles(): void
    {
        $a = new FileStorage($this->root, 'folder-a');
        $b = new FileStorage($this->root, 'folder-b');
        $a->write('same-key', 'A');
        $b->write('same-key', 'B');
        $this->assertSame('A', $a->read('same-key'));
        $this->assertSame('B', $b->read('same-key'));
    }
}
