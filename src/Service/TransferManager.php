<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\TransferRepository;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class TransferManager
{
    public const MAX_BYTES = 50 * 1024 * 1024;

    public function __construct(private TransferRepository $repository, private FileStorage $storage, private string $appUrl)
    {
    }

    public function create(UploadedFile $file, int $hours, string $password): array
    {
        $this->purgeExpired();
        if (!$file->isValid()) {
            throw new HttpException(400, 'Le fichier n’a pas pu être reçu. Limite : 50 Mo.');
        }
        $size = $file->getSize();
        if ($size === false || $size < 1 || $size > self::MAX_BYTES) {
            throw new HttpException(400, 'Choisissez un fichier non vide de 50 Mo maximum.');
        }
        if ($hours < 1 || $hours > 168) {
            throw new HttpException(400, 'La durée doit être comprise entre 1 et 168 heures.');
        }
        if ($password !== '' && (mb_strlen($password) < 8 || strlen($password) > 64)) {
            throw new HttpException(400, 'Le mot de passe doit contenir au moins 8 caractères et au plus 64 octets.');
        }
        $filename = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        $filename = mb_strcut(preg_replace('/[\x00-\x1f\x7f]/u', '', $filename) ?? '', 0, 180, 'UTF-8');
        if ($filename === '' || in_array($filename, ['.', '..'], true)) {
            $filename = 'fichier';
        }
        $token = self::token();
        $ownerToken = self::token();
        $id = bin2hex(random_bytes(16));
        $record = ['id' => $id, 'token_hash' => hash('sha256', $token), 'owner_hash' => hash('sha256', $ownerToken), 'filename' => $filename, 'size' => $size, 'password_hash' => $password === '' ? null : password_hash($password, PASSWORD_DEFAULT), 'created_at' => time(), 'expires_at' => time() + $hours * 3600];
        $contents = file_get_contents($file->getPathname());
        if ($contents === false) {
            throw new \RuntimeException('Uploaded file unreadable.');
        }
        $this->storage->store($id, $contents);
        try {
            $this->repository->insert($record);
        } catch (\Throwable $error) {
            $this->storage->remove($id);
            throw $error;
        }
        return $this->describe($record + ['downloads' => 0], $token) + ['ownerToken' => $ownerToken];
    }

    public function resolve(string $token): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/D', $token)) {
            throw new HttpException(404, 'Ce transfert est expiré ou introuvable.');
        }
        $record = $this->repository->find(hash('sha256', $token));
        if (!$record) {
            throw new HttpException(404, 'Ce transfert est expiré ou introuvable.');
        }
        if ($record['expires_at'] <= time()) {
            $this->storage->remove($record['id']);
            $this->repository->remove($record['id']);
            throw new HttpException(410, 'Ce transfert a expiré.');
        }
        return $record;
    }

    public function owned(string $token, string $ownerToken): array
    {
        $record = $this->resolve($token);
        if (!hash_equals($record['owner_hash'], hash('sha256', $ownerToken))) {
            throw new HttpException(404, 'Transfert introuvable.');
        }
        return $record;
    }

    public function download(string $token, string $password): array
    {
        $record = $this->resolve($token);
        if ($record['password_hash'] && !password_verify($password, $record['password_hash'])) {
            throw new HttpException(403, 'Le mot de passe est incorrect.');
        }
        $contents = $this->storage->read($record['id']);
        // Recheck expiry after decryption and increment atomically.
        if (!$this->repository->countDownload($record['id'], time())) {
            throw new HttpException(410, 'Ce transfert n’est plus disponible.');
        }
        return ['filename' => $record['filename'], 'contents' => $contents];
    }

    public function delete(string $token, string $ownerToken): void
    {
        $record = $this->owned($token, $ownerToken);
        $this->storage->remove($record['id']);
        $this->repository->remove($record['id']);
    }

    public function describe(array $record, string $token): array
    {
        return ['token' => $token, 'filename' => $record['filename'], 'size' => (int) $record['size'], 'protected' => $record['password_hash'] !== null, 'createdAt' => gmdate('c', $record['created_at']), 'expiresAt' => gmdate('c', $record['expires_at']), 'downloads' => (int) $record['downloads'], 'url' => rtrim($this->appUrl, '/') . '/t/' . $token];
    }

    public function purgeExpired(): int
    {
        $ids = $this->repository->expired(time());
        foreach ($ids as $id) {
            $this->storage->remove($id);
            $this->repository->remove($id);
        }
        return count($ids);
    }

    private static function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
