<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Search;

use Aurora\Core\Encryption\Doctrine\EncryptedTextType;
use Aurora\Core\Search\SuiteSearchProviderInterface;
use Aurora\Module\Notes\Markdown\Service\NoteAddresses;
use Aurora\Module\Notes\NotesContext;
use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
use Aurora\Module\Notes\Space\Enum\NoteSpaceScopeEnum;
use Aurora\Module\Notes\Space\Hosting\NoteSpaceScope;
use Aurora\Module\Notes\Space\Repository\NoteSpaceRepository;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

use function mb_trim;
use function preg_replace;

/**
 * The notebook's slice of the suite global search.
 *
 * **Searched in memory, not in SQL.** A note's title and body are both stored
 * encrypted ({@see EncryptedTextType}), so a
 * `LIKE` in the database would compare the query with ciphertext and never
 * match. The notebook's own search box already answers this way, through
 * `MarkdownNoteManager::searchContent()`: it loads the reader's living notes,
 * decrypted by Doctrine, and compares there. This does the same, over the same
 * rows, and adds the title.
 *
 * **Every note the reader may open**: their own, and those of every note space
 * they belong to, the spaces a client space opens for its team included
 * (`visibleTo` in the repository is the one rule). The cost is the decryption
 * of those notes on each search, bounded by what the reader can already read.
 *
 * **A hosted note opens where it lives** (10/10/2026): a client space's note
 * leads to the client space, with the space's name under its title. Somebody
 * without the Notes module still finds the notes of the client spaces whose
 * team they are in - the search runs over the hosted spaces only for them.
 *
 * Title matches first: somebody typing a note's name wants that note, not the
 * ten others that mention it.
 */
final readonly class NotesSuiteSearchProvider implements SuiteSearchProviderInterface
{
    private const int LIMIT = 8;

    /** Characters kept on each side of the match in a body snippet. */

    /** The privilege the notebook's controller is behind. */
    private const string PRIVILEGE = 'notes.markdown.use';

    public function __construct(
        private NoteSearch $noteSearch,
        private NotesContext $notesContext,
        private Security $security,
        private NoteAddresses $noteAddresses,
        private NoteSpaceRepository $spaceRepository,
        private NoteSpaceScope $scope,
        private TranslatorInterface $translator,
    ) {}

    public function search(string $query): array
    {
        // The contract says never throw: a decryption failure on one row must
        // not take the whole search box down with it.
        try {
            $user = $this->security->getUser();
            if (
                !$user instanceof CoreUserInterface
                || !$this->notesContext->isSuiteEnabled()
                || !$this->notesContext->isMarkdownEnabled()
                || '' === mb_trim($query)
            ) {
                return [];
            }

            // The notebook's own search (10/10/2026): accents and case
            // ignored, every word required, the best match first, and the
            // passage that matched. Already without the trashed notes.
            $search = fn (): array => $this->noteSearch->search($user, $query, 'relevance', self::LIMIT)['results'];
            $results = $this->security->isGranted(self::PRIVILEGE)
                ? $search()
                : $this->scope->within(NoteSpaceScopeEnum::Hosts, $search);

            $spaces = $this->spacesOf($results);
            $rows = [];
            foreach ($results as $result) {
                $space = $spaces[(int) $result['spaceId']] ?? null;
                if ($space instanceof NoteSpaceInterface) {
                    $rows[] = $this->row($result, $space);
                }
            }

            return ['notes' => $rows];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * One result row.
     *
     * A title match shows the folder under the title, which tells two notes
     * of the same name apart; a match elsewhere shows the passage that
     * matched, since the title alone would not say why the note came up.
     *
     * A hosted note shows its host's name for the space instead of the
     * folder, and leads to the host's page.
     *
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function row(array $result, NoteSpaceInterface $space): array
    {
        $title = (string) $result['title'];
        $passage = null;
        foreach ($result['snippets'] as $snippet) {
            if ('content' === $snippet['field'] || 'comment' === $snippet['field'] || 'heading' === $snippet['field'] || 'property' === $snippet['field']) {
                $passage = preg_replace('/\s+/u', ' ', $snippet['text']) ?? $snippet['text'];
                break;
            }
        }

        $titleMatched = [] !== $result['titleRanges'];
        $hostLabel = $this->noteAddresses->hostLabel($space);
        $place = $hostLabel ?? $this->folderName($result['folderName']);

        return [
            'id' => $result['id'],
            'title' => '' === mb_trim($title) ? $this->translator->trans('notes.markdown.untitled') : $title,
            'subtitle' => $titleMatched || null === $passage ? $place : (null === $hostLabel ? $passage : $hostLabel.' · '.$passage),
            'path' => $this->noteAddresses->noteUrlIn($space, (int) $result['id']),
        ];
    }

    /**
     * The spaces of the results, by id, in one query.
     *
     * @param list<array<string, mixed>> $results
     *
     * @return array<int, NoteSpaceInterface>
     */
    private function spacesOf(array $results): array
    {
        $ids = array_values(array_unique(array_map(static fn (array $result): int => (int) $result['spaceId'], $results)));
        if ([] === $ids) {
            return [];
        }

        $spaces = [];
        foreach ($this->spaceRepository->findBy(['id' => $ids]) as $space) {
            $spaces[(int) $space->getId()] = $space;
        }

        return $spaces;
    }

    private function folderName(?string $name): ?string
    {
        if (null === $name) {
            return null;
        }

        return '' === mb_trim($name) ? $this->translator->trans('notes.markdown.folders.untitled') : $name;
    }
}
