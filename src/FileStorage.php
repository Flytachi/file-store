<?php

declare(strict_types=1);

namespace Flytachi\FileStore\Src;

class FileStorage
{
    private string $directory;
    private string $dirKey;
    private string $hmacType = 'sha256';

    /**
     * @throws FileStorageException
     */
    public function __construct(string $rootPath, string $folderName)
    {
        $rootPath = rtrim($rootPath, '/');
        $folderName = ltrim($folderName, '/');

        if (!is_writable($rootPath)) {
            throw new FileStorageException("Path not writable: $rootPath");
        }

        $this->directory = $rootPath . '/' . $folderName;

        if (!is_dir($this->directory)) {
            if (!mkdir($this->directory, 0770, true)) {
                throw new FileStorageException("Directory not created: {$this->directory}");
            }
        }

        if (!is_writable($this->directory)) {
            throw new FileStorageException("Directory not writable: {$this->directory}");
        }

        $this->dirKey = md5($this->directory);
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

        try {
            file_put_contents($filePath, $data, LOCK_EX);
        } catch (\Throwable $e) {
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

    public function clear(): void
    {
        foreach (glob($this->directory . '/*') as $file) {
            $this->safeUnlink($file);
        }
    }

    // -------------------------
    // Internals
    // -------------------------

    private function getFilePath(string $key): string
    {
        $fileName = hash_hmac($this->hmacType, $key, $this->dirKey);
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
}
