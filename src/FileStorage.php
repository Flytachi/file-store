<?php

declare(strict_types=1);

namespace Flytachi\FileStore;

class FileStorage
{
    private string $directory;
    private string $dirKey;
    private bool $isHash;
    private string $hmacType;
    private int $dirMode;
    private int $fileMode;

    /**
     * @throws FileStorageException
     */
    public function __construct(
        string $rootPath,
        string $folderName,
        bool $isHash = true,
        string $hmacType = 'sha256',
        int $dirMode = 0770,
        int $fileMode = 0660
    ) {
        $this->isHash = $isHash;
        $this->hmacType = $hmacType;
        $this->dirMode = $dirMode;
        $this->fileMode = $fileMode;
        $rootPath = rtrim($rootPath, '/');
        $folderName = ltrim($folderName, '/');

        if (!is_writable($rootPath)) {
            throw new FileStorageException("Path not writable: $rootPath");
        }

        $this->directory = $rootPath . '/' . $folderName;

        if (!is_dir($this->directory)) {
            // Another process may create it between the check and mkdir() — two workers
            // opening the same store for the first time. Its mkdir() then fails although
            // the directory is there, so only a directory that still does not exist is
            // an error.
            if (!@mkdir($this->directory, $this->dirMode, true) && !is_dir($this->directory)) {
                throw new FileStorageException("Directory not created: {$this->directory}");
            }
            @chmod($this->directory, $this->dirMode);
        }

        if (!is_writable($this->directory)) {
            throw new FileStorageException("Directory not writable: {$this->directory}");
        }

        // Keyed by the directory itself, not by how the path was written: through a
        // symlink, a relative segment or a trailing slash the same directory would get
        // another key, and every process reaching it another way would see none of the
        // files the others wrote. A plain absolute path is its own real path, so its
        // key — and every file name derived from it — is unchanged.
        $this->dirKey = $this->md5Normalized(realpath($this->directory) ?: $this->directory);
    }



    public function write(string $key, mixed $content, ?int $expireAtTimestamp = null): void
    {
        if (empty($content)) {
            return;
        }

        $filePath = $this->getFilePath($key);
        $data = serialize($content);

        if ($expireAtTimestamp !== null) {
            $data = "#^e:{$expireAtTimestamp}\n" . $data;
        }

        $tmpPath = null;
        try {
            $tmpPath = $filePath . '.tmp.' . bin2hex(random_bytes(6));
            $written = file_put_contents($tmpPath, $data, LOCK_EX);
            if ($written === false) {
                throw new FileStorageException("Failed to write tmp file: $tmpPath");
            }
            @chmod($tmpPath, $this->fileMode);
            if (!rename($tmpPath, $filePath)) {
                throw new FileStorageException("Failed to rename tmp into place: $filePath");
            }
        } catch (\Throwable $e) {
            if ($tmpPath !== null) {
                @unlink($tmpPath);
            }
            throw new FileStorageException($e->getMessage(), previous: $e);
        }
    }

    public function del(string $key): void
    {
        $this->safeUnlink($this->getFilePath($key));
    }

    public function has(string $key): bool
    {
        $filePath = $this->getFilePath($key);

        if (!is_file($filePath)) {
            return false;
        }

        $fp = @fopen($filePath, 'r');
        if (!$fp) {
            return false;
        }

        $firstLine = fgets($fp);
        fclose($fp);

        return !$this->isExpiredLine($firstLine, $filePath);
    }

    public function read(string $key): mixed
    {
        $filePath = $this->getFilePath($key);
        $data = @file_get_contents($filePath);
        if ($data === false) {
            return null;
        }

        $parsed = $this->extractDataWithTtl($data, $filePath);
        return $parsed === null ? null : unserialize($parsed);
    }

    public function keys(): array
    {
        $keys = [];
        foreach (glob($this->directory . '/*') as $file) {
            $name = basename($file);
            if (str_contains($name, '.tmp.')) {
                continue;
            }
            $keys[] = $name;
        }
        return $keys;
    }

    public function clear(): void
    {
        foreach (glob($this->directory . '/*') as $file) {
            $this->safeUnlink($file);
        }
    }

    public function gc(int $tmpMaxAgeSeconds = 3600): int
    {
        $deleted = 0;
        $now = time();

        foreach (new \DirectoryIterator($this->directory) as $entry) {
            if ($entry->isDot() || !$entry->isFile()) {
                continue;
            }

            $path = $entry->getPathname();
            $name = $entry->getFilename();

            if (str_contains($name, '.tmp.')) {
                if ($now - $entry->getMTime() > $tmpMaxAgeSeconds) {
                    if (@unlink($path)) {
                        $deleted++;
                    }
                }
                continue;
            }

            $fp = @fopen($path, 'r');
            if (!$fp) {
                continue;
            }
            $firstLine = fgets($fp);
            fclose($fp);

            if ($this->isExpiredLine($firstLine === false ? null : $firstLine, $path)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    // -------------------------
    // Internals
    // -------------------------

    private function getFilePath(string $key): string
    {
        if ($this->isHash && !empty($this->hmacType)) {
            $fileName = hash_hmac($this->hmacType, $key, $this->dirKey);
        } else {
            if (
                $key === '' || $key === '.' || $key === '..'
                || str_contains($key, '/')
                || str_contains($key, '\\')
                || str_contains($key, "\0")
            ) {
                throw new FileStorageException("Invalid key: $key");
            }
            $fileName = $key;
        }
        return $this->directory . '/' . $fileName;
    }

    private function safeUnlink(string $filePath): void
    {
        if (is_file($filePath)) {
            @unlink($filePath);
        }
    }

    private function isExpiredLine(?string $line, string $filePath): bool
    {
        if ($line && str_starts_with($line, '#^e:')) {
            $expireAt = (int) substr($line, 4);
            if ($expireAt < time()) {
                $this->safeUnlink($filePath);
                return true;
            }
        }

        return false;
    }

    private function extractDataWithTtl(string $data, string $filePath): ?string
    {
        if (str_starts_with($data, '#^e:')) {
            $data = substr($data, 4);
            $pos = strpos($data, "\n");

            if ($pos !== false) {
                $expireAt = (int) substr($data, 0, $pos);
                if ($expireAt < time()) {
                    $this->safeUnlink($filePath);
                    return null;
                }
                $data = substr($data, $pos + 1);
            }
        }

        return $data;
    }

    private function md5Normalized(string $str): string
    {
        $bom = "\xEF\xBB\xBF";
        if (substr($str, 0, 3) === $bom) {
            $str = substr($str, 3);
        }

        $str = preg_replace('/\r\n?/', "\n", $str);
        $str = trim($str);
        if (!mb_check_encoding($str, 'UTF-8')) {
            $str = mb_convert_encoding($str, 'UTF-8');
        }

        return md5($str);
    }
}
