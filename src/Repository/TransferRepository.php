<?php

declare(strict_types=1);

namespace App\Repository;

final class TransferRepository
{
    private \PDO $connection;

    public function __construct(string $storagePath)
    {
        if (!is_dir($storagePath)) {
            mkdir($storagePath, 0700, true);
        }
        $this->connection = new \PDO('sqlite:' . $storagePath . '/transfers.sqlite', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $this->connection->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000;');
        $this->connection->exec('CREATE TABLE IF NOT EXISTS transfers (
            id TEXT PRIMARY KEY, token_hash TEXT NOT NULL UNIQUE, owner_hash TEXT NOT NULL,
            filename TEXT NOT NULL, size INTEGER NOT NULL, password_hash TEXT,
            created_at INTEGER NOT NULL, expires_at INTEGER NOT NULL, downloads INTEGER NOT NULL DEFAULT 0
        ); CREATE INDEX IF NOT EXISTS transfers_expiry ON transfers(expires_at);');
    }

    public function insert(array $transfer): void
    {
        $this->connection->exec('BEGIN IMMEDIATE');
        try {
            $usage = $this->connection->query('SELECT COUNT(*) AS count, COALESCE(SUM(size),0) AS bytes FROM transfers')->fetch();
            if ($usage['count'] >= 500 || $usage['bytes'] + $transfer['size'] > 1073741824) {
                throw new \RuntimeException('Storage capacity reached.');
            }
            $statement = $this->connection->prepare('INSERT INTO transfers (id, token_hash, owner_hash, filename, size, password_hash, created_at, expires_at) VALUES (:id,:token_hash,:owner_hash,:filename,:size,:password_hash,:created_at,:expires_at)');
            $statement->execute($transfer);
            $this->connection->exec('COMMIT');
        } catch (\Throwable $error) {
            $this->connection->exec('ROLLBACK');
            throw $error;
        }
    }

    public function find(string $tokenHash): ?array
    {
        $statement = $this->connection->prepare('SELECT * FROM transfers WHERE token_hash=?');
        $statement->execute([$tokenHash]);
        return $statement->fetch() ?: null;
    }

    public function countDownload(string $id, int $now): bool
    {
        $statement = $this->connection->prepare('UPDATE transfers SET downloads=downloads+1 WHERE id=? AND expires_at>?');
        $statement->execute([$id, $now]);
        return $statement->rowCount() === 1;
    }

    public function remove(string $id): void
    {
        $statement = $this->connection->prepare('DELETE FROM transfers WHERE id=?');
        $statement->execute([$id]);
    }

    public function expired(int $now): array
    {
        $statement = $this->connection->prepare('SELECT id FROM transfers WHERE expires_at<=?');
        $statement->execute([$now]);
        return $statement->fetchAll(\PDO::FETCH_COLUMN);
    }
}
