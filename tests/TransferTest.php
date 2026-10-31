<?php

declare(strict_types=1);

namespace App\Tests;

use App\Repository\TransferRepository;
use App\Service\{FileStorage, TransferManager};
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class TransferTest extends TestCase
{
    private string $directory;
    private FileStorage $storage;
    private TransferRepository $repository;
    private TransferManager $manager;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/securebox-test-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/files', 0700, true);
        file_put_contents($this->directory . '/encryption.key', random_bytes(32));
        $this->storage = new FileStorage($this->directory);
        $this->repository = new TransferRepository($this->directory);
        $this->manager = new TransferManager($this->repository, $this->storage, 'http://localhost:3030');
    }

    protected function tearDown(): void
    {
        unset($this->manager, $this->repository);
        foreach (glob($this->directory . '/files/*') as $file) {
            unlink($file);
        }
        foreach (glob($this->directory . '/*') as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->directory . '/files');
        rmdir($this->directory);
    }

    private function upload(string $name = 'notes.txt', string $contents = 'A private test file'): UploadedFile
    {
        $path = $this->directory . '/upload-' . bin2hex(random_bytes(4));
        file_put_contents($path, $contents);
        return new UploadedFile($path, $name, null, null, true);
    }

    public function testEncryptionRoundTripAndAuthenticatedTamperRejection(): void
    {
        $id = str_repeat('a', 32);
        $this->storage->store($id, 'Confidential sample contents');
        $path = $this->directory . '/files/' . $id . '.bin';
        $ciphertext = file_get_contents($path);
        self::assertStringNotContainsString('Confidential sample contents', $ciphertext);
        self::assertSame('Confidential sample contents', $this->storage->read($id));
        $ciphertext[35] = chr(ord($ciphertext[35]) ^ 1);
        file_put_contents($path, $ciphertext);
        $this->expectException(\RuntimeException::class);
        $this->storage->read($id);
    }

    public function testPasswordIsHashedAndWrongPasswordDoesNotCount(): void
    {
        $transfer = $this->manager->create($this->upload(), 24, 'test-password-only');
        $record = $this->manager->resolve($transfer['token']);
        self::assertNotSame('test-password-only', $record['password_hash']);
        self::assertTrue(password_verify('test-password-only', $record['password_hash']));
        try {
            $this->manager->download($transfer['token'], 'incorrect');
            self::fail('Wrong password was accepted.');
        } catch (HttpException $error) {
            self::assertSame(403, $error->getStatusCode());
        }
        self::assertSame(0, $this->manager->resolve($transfer['token'])['downloads']);
        self::assertSame('A private test file', $this->manager->download($transfer['token'], 'test-password-only')['contents']);
        self::assertSame(1, $this->manager->resolve($transfer['token'])['downloads']);
    }

    public function testMetadataHidesRawTokensAndOwnerAuthorizationIsRequired(): void
    {
        $transfer = $this->manager->create($this->upload(), 1, '');
        $record = $this->manager->owned($transfer['token'], $transfer['ownerToken']);
        self::assertSame(hash('sha256', $transfer['token']), $record['token_hash']);
        self::assertSame(hash('sha256', $transfer['ownerToken']), $record['owner_hash']);
        self::assertFalse($transfer['protected']);
        self::assertSame(43, strlen($transfer['token']));
        $this->expectException(HttpException::class);
        $this->manager->owned($transfer['token'], 'wrong-owner');
    }

    public function testRevocationDeletesFileAndDatabaseRecord(): void
    {
        $transfer = $this->manager->create($this->upload(), 1, '');
        $record = $this->manager->resolve($transfer['token']);
        $this->manager->delete($transfer['token'], $transfer['ownerToken']);
        self::assertFileDoesNotExist($this->directory . '/files/' . $record['id'] . '.bin');
        self::assertNull($this->repository->find(hash('sha256', $transfer['token'])));
    }

    public function testExpiredTransferCannotBeDownloadedAndIsDeleted(): void
    {
        $transfer = $this->manager->create($this->upload(), 1, '');
        $record = $this->manager->resolve($transfer['token']);
        $connection = new \PDO('sqlite:' . $this->directory . '/transfers.sqlite');
        $connection->exec('UPDATE transfers SET expires_at=1');
        try {
            $this->manager->download($transfer['token'], '');
            self::fail('Expired download was allowed.');
        } catch (HttpException $error) {
            self::assertSame(410, $error->getStatusCode());
        }
        self::assertFileDoesNotExist($this->directory . '/files/' . $record['id'] . '.bin');
        self::assertNull($this->repository->find(hash('sha256', $transfer['token'])));
    }

    public function testScheduledPruningRemovesExpiredFiles(): void
    {
        $this->manager->create($this->upload(), 1, '');
        $connection = new \PDO('sqlite:' . $this->directory . '/transfers.sqlite');
        $connection->exec('UPDATE transfers SET expires_at=1');
        self::assertSame(1, $this->manager->purgeExpired());
        self::assertSame([], glob($this->directory . '/files/*.bin'));
    }

    public function testFilenameCannotEscapeStorageOrInjectHeaders(): void
    {
        $transfer = $this->manager->create($this->upload("../../evil\r\nname.html"), 24, '');
        self::assertSame('evilname.html', $transfer['filename']);
        self::assertCount(1, glob($this->directory . '/files/*.bin'));
    }

    public function testInvalidDurationsPasswordsAndEmptyFilesAreRejected(): void
    {
        foreach ([[0, ''], [169, ''], [24, 'short'], [24, str_repeat('a', 65)]] as [$hours, $password]) {
            try {
                $this->manager->create($this->upload(), $hours, $password);
                self::fail('Invalid settings were accepted.');
            } catch (HttpException $error) {
                self::assertSame(400, $error->getStatusCode());
            }
        }
        $this->expectException(HttpException::class);
        $this->manager->create($this->upload('empty.txt', ''), 24, '');
    }

    public function testOversizedFilesAreRejectedBeforeEncryption(): void
    {
        $path = $this->directory . '/large-file';
        $stream = fopen($path, 'wb');
        ftruncate($stream, TransferManager::MAX_BYTES + 1);
        fclose($stream);
        $this->expectException(HttpException::class);
        $this->manager->create(new UploadedFile($path, 'large.bin', null, null, true), 24, '');
    }
    public function testStorageQuotaFailureDoesNotLeaveAnEncryptedOrphan(): void
    {
        $this->manager->create($this->upload(), 24, '');
        $connection = new \PDO('sqlite:' . $this->directory . '/transfers.sqlite');
        $connection->exec('UPDATE transfers SET size=1073741824');
        try {
            $this->manager->create($this->upload(), 24, '');
            self::fail('Storage quota was ignored.');
        } catch (\RuntimeException) {
            self::assertCount(1, glob($this->directory . '/files/*.bin'));
        }
    }
}
