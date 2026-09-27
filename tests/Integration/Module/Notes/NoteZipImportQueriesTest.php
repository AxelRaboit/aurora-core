<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Notes;

use Aurora\Module\Notes\Markdown\Entity\MarkdownNote;
use Aurora\Module\Notes\Markdown\Service\MarkdownNoteImporter;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Tests\Integration\Concern\CreatesTestUsers;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use ZipArchive;

use function array_filter;
use function array_map;
use function array_values;
use function count;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Importing a notebook costs the same reads and flushes for three notes or six.
 *
 * Each note was created on its own: its folder looked up, the last position
 * of the folder asked, a flush, a line of audit and its flush - and every
 * flush re-encrypted every note already imported. A vault of hundreds, in one
 * request, could time out half-way.
 */
final class NoteZipImportQueriesTest extends IntegrationTestCase
{
    use CreatesTestUsers;

    private ?User $user = null;

    /** @var list<string> */
    private array $zips = [];

    protected function tearDown(): void
    {
        foreach ($this->zips as $zip) {
            @unlink($zip);
        }

        if ($this->user instanceof User) {
            $entityManager = static::getContainer()->get(EntityManagerInterface::class);
            $user = $entityManager->find(User::class, $this->user->getId());
            if (null !== $user) {
                $entityManager->remove($user);
                $entityManager->flush();
            }
        }

        parent::tearDown();
    }

    public function testTheImportDoesNotGrowWithTheNotes(): void
    {
        static::bootKernel();
        $this->user = $this->createTestUser('carnet');

        // Once to warm what services keep between calls - the settings - so
        // the two counts compare the imports and nothing else.
        $this->queriesToImport('Échauffement', 1);
        [$three, $createdThree] = $this->queriesToImport('Carnet A', 3);
        [$six, $createdSix] = $this->queriesToImport('Carnet B', 6);

        self::assertSame(4, $createdThree, 'a folder and three notes');
        self::assertSame(7, $createdSix, 'a folder and six notes');
        self::assertSame($three, $six, 'three more notes, not one more query');

        $notes = static::getContainer()->get(EntityManagerInterface::class)->getRepository(MarkdownNote::class)->findBy(['user' => $this->user], ['position' => 'ASC']);
        $inB = array_filter($notes, static fn (MarkdownNote $note): bool => 'Carnet B' === $note->getFolder()?->getName());
        self::assertSame(['Note 1', 'Note 2', 'Note 3', 'Note 4', 'Note 5', 'Note 6'], array_values(array_map(static fn (MarkdownNote $note): ?string => $note->getTitle(), $inB)));
    }

    /** @return array{int, int} */
    private function queriesToImport(string $folder, int $notes): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'carnet').'.zip';
        $this->zips[] = $path;
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        for ($i = 1; $i <= $notes; ++$i) {
            $zip->addFromString(sprintf('%s/Note %d.md', $folder, $i), sprintf("Le contenu %d.\n", $i));
        }
        $zip->close();

        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();

        $created = static::getContainer()->get(MarkdownNoteImporter::class)->import(
            $this->user,
            new UploadedFile($path, 'carnet.zip', 'application/zip', null, true),
            null,
        );

        // Every row is still one INSERT and one NEXTVAL for its id - that is
        // the rows being written. What must not grow is everything else: the
        // reads, and the transactions a flush opens.
        $overhead = array_filter(
            $holder->getData()['default'] ?? [],
            static fn (array $query): bool => !str_starts_with((string) $query['sql'], 'INSERT')
                && !str_contains((string) $query['sql'], 'NEXTVAL'),
        );

        return [count($overhead), $created];
    }
}
