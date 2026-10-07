<?php

declare(strict_types=1);

namespace Aurora\Core\Storage\Adapter;

use Aurora\Core\Storage\Enum\StorageDiskEnum;
use Aurora\Core\Storage\Exception\StorageException;
use Aurora\Core\Storage\Workspace\LocalPathAware;
use Aurora\Core\Storage\Workspace\LocalWorkspace;
use DateTimeImmutable;
use FilesystemIterator;
use Generator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

use function sprintf;

use const DIRECTORY_SEPARATOR;

/**
 * The disk under `var/uploads/`, behind the adapter contract.
 *
 * This is what Aurora has always done, and it keeps doing it byte for byte:
 * same directory, same relative paths, same files. Nothing here is new
 * behaviour, which is the point - it is the reference implementation every
 * other adapter is checked against, and the one the test suite runs on so the
 * suite needs no credentials.
 *
 * Every key is resolved through {@see resolve()}, which is the single place
 * that refuses to leave the root. `BinaryFileServer` guards the serving path
 * the same way with `realpath`; this guards the writing path, and it has to
 * work on keys whose file does not exist yet, so it compares the normalised
 * path rather than the resolved one.
 */
final readonly class LocalStorageAdapter implements StorageAdapterInterface, LocalPathAware
{
    public function __construct(
        private Filesystem $filesystem,
        #[Autowire(param: 'app.upload_dir')]
        private string $rootDirectory,
    ) {}

    public function disk(): StorageDiskEnum
    {
        return StorageDiskEnum::Local;
    }

    /**
     * Always. A directory that does not exist yet is created on write, so
     * there is no state in which this backend cannot be asked.
     */
    public function isReady(): bool
    {
        return true;
    }

    /**
     * Here the key *is* the file, which is what lets
     * {@see LocalWorkspace} hand image and PDF
     * tooling the stored path itself rather than a temporary copy of it.
     */
    public function localPath(string $key): string
    {
        return $this->resolve($key);
    }

    public function writeFromLocalFile(string $key, string $sourceAbsolutePath): void
    {
        if (!is_file($sourceAbsolutePath)) {
            throw StorageException::writeFailed($key, sprintf('source "%s" is not a file', $sourceAbsolutePath));
        }

        $target = $this->resolve($key);

        // A copy onto itself is what happens whenever a caller hands back the
        // very path this adapter gave it - the local fast path in
        // LocalWorkspace does exactly that. Symfony's copy() would truncate
        // the file before reading it.
        if ($this->isSameFile($sourceAbsolutePath, $target)) {
            return;
        }

        try {
            $this->filesystem->mkdir(dirname($target));
            $this->filesystem->copy($sourceAbsolutePath, $target, true);
        } catch (IOException $ioException) {
            throw StorageException::writeFailed($key, $ioException->getMessage());
        }
    }

    public function write(string $key, string $contents): void
    {
        $target = $this->resolve($key);

        try {
            $this->filesystem->mkdir(dirname($target));
            $this->filesystem->dumpFile($target, $contents);
        } catch (IOException $ioException) {
            throw StorageException::writeFailed($key, $ioException->getMessage());
        }
    }

    public function read(string $key): string
    {
        $path = $this->resolve($key);

        if (!is_file($path)) {
            throw StorageException::missingKey($key);
        }

        $contents = @file_get_contents($path);

        if (false === $contents) {
            throw StorageException::readFailed($key, 'file_get_contents() failed');
        }

        return $contents;
    }

    public function readStream(string $key): Generator
    {
        $path = $this->resolve($key);

        if (!is_file($path)) {
            throw StorageException::missingKey($key);
        }

        $handle = @fopen($path, 'r');

        if (false === $handle) {
            throw StorageException::readFailed($key, 'fopen() failed');
        }

        try {
            while (!feof($handle)) {
                $chunk = fread($handle, 1 << 19);

                if (false === $chunk) {
                    throw StorageException::readFailed($key, 'fread() failed');
                }

                yield $chunk;
            }
        } finally {
            fclose($handle);
        }
    }

    public function copyToLocalFile(string $key, string $targetAbsolutePath): void
    {
        $source = $this->resolve($key);

        if (!is_file($source)) {
            throw StorageException::missingKey($key);
        }

        if ($this->isSameFile($source, $targetAbsolutePath)) {
            return;
        }

        try {
            $this->filesystem->mkdir(dirname($targetAbsolutePath));
            $this->filesystem->copy($source, $targetAbsolutePath, true);
        } catch (IOException $ioException) {
            throw StorageException::readFailed($key, $ioException->getMessage());
        }
    }

    public function exists(string $key): bool
    {
        return is_file($this->resolve($key));
    }

    public function delete(string $key): void
    {
        // Filesystem::remove() is already quiet about what is not there.
        $this->filesystem->remove($this->resolve($key));
    }

    public function deleteMany(array $keys): void
    {
        if ([] === $keys) {
            return;
        }

        $this->filesystem->remove(array_map(
            $this->resolve(...),
            $keys,
        ));
    }

    public function list(string $prefix): Generator
    {
        $root = '' === $prefix ? $this->rootDirectory : $this->resolve($prefix);

        if (!is_dir($root)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            yield $this->describe($this->relativise($file->getPathname()), $file);
        }
    }

    public function stat(string $key): ?StoredObject
    {
        $path = $this->resolve($key);

        if (!is_file($path)) {
            return null;
        }

        return $this->describe($key, new SplFileInfo($path));
    }

    /**
     * Absolute path for a key, refusing anything that would leave the root.
     *
     * `Path::canonicalize` resolves `..` textually, without touching the
     * filesystem, which is what lets this run on a key whose file is not
     * written yet. The trailing-separator comparison is the same one
     * `BinaryFileServer` makes, and for the same reason: a sibling directory
     * that merely shares a prefix must not pass.
     */
    private function resolve(string $key): string
    {
        $normalised = Path::canonicalize($this->rootDirectory.DIRECTORY_SEPARATOR.$key);
        $root = mb_rtrim(Path::canonicalize($this->rootDirectory), '/').'/';

        if (!str_starts_with($normalised.'/', $root)) {
            throw StorageException::writeFailed($key, 'key escapes the storage root');
        }

        return $normalised;
    }

    private function relativise(string $absolutePath): string
    {
        return Path::makeRelative(Path::canonicalize($absolutePath), Path::canonicalize($this->rootDirectory));
    }

    private function describe(string $key, SplFileInfo $file): StoredObject
    {
        return new StoredObject(
            key: $key,
            size: (int) $file->getSize(),
            lastModifiedAt: new DateTimeImmutable('@'.$file->getMTime()),
        );
    }

    private function isSameFile(string $left, string $right): bool
    {
        return Path::canonicalize($left) === Path::canonicalize($right);
    }
}
