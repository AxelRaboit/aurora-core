<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Search;

use Aurora\Core\Encryption\Doctrine\EncryptedTextType;
use Aurora\Core\Search\SearchSnippetBuilder;
use Aurora\Core\Search\SuiteSearchProviderInterface;
use Aurora\Module\Notes\Folder\Entity\NoteFolderInterface;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Markdown\Repository\MarkdownNoteRepository;
use Aurora\Module\Notes\NotesContext;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

use function array_slice;
use function count;
use function mb_strtolower;
use function mb_trim;
use function preg_replace;
use function str_contains;

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
 * **Only the reader's own notes**, which is also what the notebook's search box
 * covers. Notes other people opened to the back-office are readable, but they
 * live on a read-only screen of their own, and answering them here would mean
 * decrypting every shared note of the house on each keystroke of anybody's
 * search. The owner's edit screen is where these rows lead.
 *
 * Title matches first: somebody typing a note's name wants that note, not the
 * ten others that mention it.
 */
final readonly class NotesSuiteSearchProvider implements SuiteSearchProviderInterface
{
    private const int LIMIT = 8;

    /** Characters kept on each side of the match in a body snippet. */
    private const int SNIPPET_RADIUS = 40;

    /** The privilege the notebook's controller is behind. */
    private const string PRIVILEGE = 'notes.markdown.use';

    public function __construct(
        private MarkdownNoteRepository $notes,
        private NotesContext $notesContext,
        private Security $security,
        private SearchSnippetBuilder $snippets,
        private UrlGeneratorInterface $urlGenerator,
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
                || !$this->security->isGranted(self::PRIVILEGE)
            ) {
                return [];
            }

            $needle = mb_strtolower(mb_trim($query));
            if ('' === $needle) {
                return [];
            }

            $byTitle = [];
            $byContent = [];

            // Already without the trashed ones: the repository filters on
            // `deletedAt`, as it does for the notebook's own search.
            foreach ($this->notes->findAllWithContentForUser($user) as $note) {
                if (str_contains(mb_strtolower((string) $note->getTitle()), $needle)) {
                    $byTitle[] = $note;
                } elseif (str_contains(mb_strtolower((string) $note->getContent()), $needle)) {
                    $byContent[] = $note;
                }
            }

            // Rows built for the kept notes only: the folder is a lazy
            // association, and a row per match would load one per match.
            $rows = [];
            foreach (array_slice($byTitle, 0, self::LIMIT) as $note) {
                $rows[] = $this->row($note, null);
            }

            foreach (array_slice($byContent, 0, self::LIMIT - count($rows)) as $note) {
                $rows[] = $this->row($note, $query);
            }

            return ['notes' => $rows];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * One result row.
     *
     * `$query` is given when the match is in the body: the subtitle then shows
     * where, since the title alone would not say why the note came up. A title
     * match shows the folder instead, which is the other thing that tells two
     * notes of the same name apart.
     *
     * @return array<string, mixed>
     */
    private function row(MarkdownNoteInterface $note, ?string $query): array
    {
        $title = $note->getTitle();

        return [
            'id' => $note->getId(),
            'title' => null === $title || '' === mb_trim($title) ? $this->translator->trans('notes.markdown.untitled') : $title,
            'subtitle' => null === $query ? $this->folderName($note) : $this->snippet((string) $note->getContent(), $query),
            'path' => $this->urlGenerator->generate('suite_notes_markdown_show', ['id' => $note->getId()]),
        ];
    }

    private function folderName(MarkdownNoteInterface $note): ?string
    {
        $folder = $note->getFolder();

        if (!$folder instanceof NoteFolderInterface) {
            return null;
        }

        $name = $folder->getName();

        return null === $name || '' === mb_trim($name) ? $this->translator->trans('notes.markdown.folders.untitled') : $name;
    }

    /** The body around the match, on one line: the palette row has no room for paragraphs. */
    private function snippet(string $content, string $query): string
    {
        $flat = preg_replace('/\s+/u', ' ', $content) ?? $content;

        return $this->snippets->build($flat, mb_trim($query), self::SNIPPET_RADIUS);
    }
}
