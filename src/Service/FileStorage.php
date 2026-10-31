<?php

declare(strict_types=1);

namespace App\Service;

final class FileStorage
{
    public function __construct(private string $storagePath)
    {
    }

    private function key(): string
    {
        $path = $this->storagePath . '/encryption.key';
        $key = is_file($path) ? file_get_contents($path) : false;
        if ($key === false || strlen($key) !== 32) {
            throw new \RuntimeException('Run composer setup before starting the application.');
        }
        return $key;
    }

    public function ready(): bool
    {
        try {
            $this->key();
            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }

    private function path(string $id): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new \InvalidArgumentException('Invalid storage identifier.');
        }
        return $this->storagePath . '/files/' . $id . '.bin';
    }

    public function store(string $id, string $contents): void
    {
        $nonce = random_bytes(12);
        $ciphertext = openssl_encrypt($contents, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $nonce, $tag, 'SecureBox:v1', 16);
        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed.');
        }
        $path = $this->path($id);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        $temporary = $path . '.part';
        $payload = 'SBX1' . $nonce . $tag . $ciphertext;
        if (file_put_contents($temporary, $payload, LOCK_EX) !== strlen($payload)) {
            @unlink($temporary);
            throw new \RuntimeException('File write failed.');
        }
        chmod($temporary, 0600);
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new \RuntimeException('File move failed.');
        }
    }

    public function read(string $id): string
    {
        $path = $this->path($id);
        $payload = is_file($path) ? file_get_contents($path) : false;
        if ($payload === false || strlen($payload) < 32 || substr($payload, 0, 4) !== 'SBX1') {
            throw new \RuntimeException('Encrypted file unavailable.');
        }
        $contents = openssl_decrypt(substr($payload, 32), 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, substr($payload, 4, 12), substr($payload, 16, 16), 'SecureBox:v1');
        if ($contents === false) {
            throw new \RuntimeException('File integrity check failed.');
        }
        return $contents;
    }

    public function remove(string $id): void
    {
        $path = $this->path($id);
        if (is_file($path) && !unlink($path)) {
            throw new \RuntimeException('File removal failed.');
        }
    }
}
