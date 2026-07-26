<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Notes\Markdown\Service;

use Aurora\Core\Storage\Enum\MimeTypeEnum;
use Aurora\Module\Notes\Markdown\Service\MarkdownNoteImageService;
use Aurora\Module\Platform\User\Entity\User;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use const DIRECTORY_SEPARATOR;
use const FILE_APPEND;

final class MarkdownNoteImageServiceTest extends TestCase
{
    private string $storageDir;
    private MarkdownNoteImageService $noteImageService;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->storageDir = sys_get_temp_dir().'/aurora-note-images-'.bin2hex(random_bytes(4));
        $this->filesystem = new Filesystem();
        $this->noteImageService = new MarkdownNoteImageService($this->storageDir, $this->filesystem);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storageDir)) {
            $this->filesystem->remove($this->storageDir);
        }
    }

    public function testStoreValidPngWritesUnderUserDirWithUuidName(): void
    {
        $user = $this->makeUser(7);
        $upload = $this->makeUpload(MimeTypeEnum::Png, 64);

        $filename = $this->noteImageService->store($upload, $user);

        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}\.png$/', $filename);
        self::assertFileExists($this->storageDir.'/7/'.$filename);
    }

    public function testStoreRejectsUnsupportedMime(): void
    {
        $this->expectException(FileException::class);
        $this->noteImageService->store($this->makeUpload(null, 64), $this->makeUser());
    }

    public function testStoreRejectsOversizedFile(): void
    {
        $this->expectException(FileException::class);
        $oversize = MarkdownNoteImageService::MAX_FILE_SIZE + 1;
        $this->noteImageService->store($this->makeUpload(MimeTypeEnum::Png, $oversize), $this->makeUser());
    }

    public function testPathReturnsAbsolutePathOfUserFile(): void
    {
        $user = $this->makeUser(9);
        $filename = $this->noteImageService->store($this->makeUpload(MimeTypeEnum::Jpeg, 32), $user);

        $resolved = $this->noteImageService->path($filename, $user);

        self::assertFileExists($resolved);
        // realpath() on both sides: $resolved went through realpath() in the
        // service (needed for the traversal check), so compare against the
        // storage dir's canonical path too — on macOS TMPDIR sits behind a
        // /var -> /private/var symlink that realpath() resolves.
        self::assertStringStartsWith(realpath($this->storageDir).DIRECTORY_SEPARATOR.'9', $resolved);
    }

    public function testPathThrowsOnMissingFile(): void
    {
        $this->expectException(RuntimeException::class);
        $this->noteImageService->path('does-not-exist.png', $this->makeUser());
    }

    public function testPathRefusesTraversalAttempt(): void
    {
        $user = $this->makeUser(1);
        // Plant a file in user 2's dir, then try to read it as user 1.
        $other = $this->makeUser(2);
        $this->noteImageService->store($this->makeUpload(MimeTypeEnum::Png, 32), $other);
        $other_files = glob($this->storageDir.'/2/*.png');
        self::assertNotEmpty($other_files);
        $bareName = basename($other_files[0]);

        $this->expectException(RuntimeException::class);
        $this->noteImageService->path('../2/'.$bareName, $user);
    }

    public function testDeleteRemovesFileAndIsIdempotent(): void
    {
        $user = $this->makeUser(3);
        $filename = $this->noteImageService->store($this->makeUpload(MimeTypeEnum::Webp, 32), $user);

        $this->noteImageService->delete($filename, $user);
        self::assertFileDoesNotExist($this->storageDir.'/3/'.$filename);

        // Second call must not throw — orphan cleanup runs multiple times.
        $this->noteImageService->delete($filename, $user);
        self::assertTrue(true);
    }

    public function testExtractFilenamesParsesContentAndDedupes(): void
    {
        $content = "Hello ![a](/backend/notes/markdown/images/abc.png) and ![b](/backend/notes/markdown/images/def.jpg)\n"
            .'Again: /backend/notes/markdown/images/abc.png';

        $filenames = $this->noteImageService->extractFilenames($content);

        sort($filenames);
        self::assertSame(['abc.png', 'def.jpg'], $filenames);
    }

    public function testExtractFilenamesReturnsEmptyOnNullOrEmpty(): void
    {
        self::assertSame([], $this->noteImageService->extractFilenames(null));
        self::assertSame([], $this->noteImageService->extractFilenames(''));
        self::assertSame([], $this->noteImageService->extractFilenames('no images here'));
    }

    private function makeUser(int $id = 42): User
    {
        $user = new User();
        $reflection = new ReflectionProperty(User::class, 'id');
        $reflection->setValue($user, $id);

        return $user;
    }

    /**
     * Build a real UploadedFile pointing at a tmp file of `size` bytes
     * with the requested MIME. Forces the test-only constructor flag so
     * the file is treated as trusted (no `is_uploaded_file` check).
     */
    /**
     * Build a real, libmagic-recognisable fixture file for the given
     * MIME via GD, then pad it to the requested size. `null` produces a
     * non-image (`application/pdf`) used by the rejection test.
     */
    private function makeUpload(?MimeTypeEnum $imageMime, int $size): UploadedFile
    {
        $mime = $imageMime?->value ?? 'application/pdf';
        $extension = $imageMime?->extension() ?? 'bin';
        $path = tempnam(sys_get_temp_dir(), 'aurora-upload-');

        $image = imagecreatetruecolor(1, 1);
        match ($imageMime) {
            MimeTypeEnum::Png => imagepng($image, $path),
            MimeTypeEnum::Jpeg, MimeTypeEnum::Jpg => imagejpeg($image, $path),
            MimeTypeEnum::Webp => imagewebp($image, $path),
            MimeTypeEnum::Gif => imagegif($image, $path),
            default => file_put_contents($path, "%PDF-1.4\n%fake pdf\n"),
        };
        imagedestroy($image);

        // Pad the file up to `size` bytes so size-limit tests get
        // sensible inputs. Appending bytes after a valid header still
        // leaves libmagic happy with the original signature.
        $currentSize = filesize($path);
        if ($size > $currentSize) {
            file_put_contents($path, str_repeat('x', $size - $currentSize), FILE_APPEND);
        }

        return new UploadedFile(
            path: $path,
            originalName: 'screenshot.'.$extension,
            mimeType: $mime,
            test: true,
        );
    }
}
