<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Manager;

use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Notes\Folder\Entity\NoteFolderInterface;
use Aurora\Module\Notes\Folder\Repository\NoteFolderRepository;
use Aurora\Module\Notes\Markdown\Dto\MarkdownNoteInputInterface;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNote;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteRevision;
use Aurora\Module\Notes\Markdown\Enum\NoteAppearanceEnum;
use Aurora\Module\Notes\Markdown\Repository\MarkdownNoteRepository;
use Aurora\Module\Notes\Markdown\Service\MarkdownNoteImageService;
use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
use Aurora\Module\Notes\Space\Repository\NoteSpaceRepository;
use Aurora\Module\Notes\Space\Service\NoteSpaceAccess;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(MarkdownNoteManagerInterface::class)]
class MarkdownNoteManager implements MarkdownNoteManagerInterface
{
    /** Matches [[anything]] occurrences in markdown content. */
    protected const string WIKI_LINK_REGEX = '/\[\[([^\]]+)\]\]/';

    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly MarkdownNoteRepository $noteRepository,
        protected readonly NoteFolderRepository $folderRepository,
        protected readonly AuditLogger $auditLogger,
        protected readonly MarkdownNoteImageService $imageService,
        protected readonly NoteSpaceAccess $spaceAccess,
        protected readonly NoteSpaceRepository $spaceRepository,
    ) {}

    public function create(CoreUserInterface $user, MarkdownNoteInputInterface $input): MarkdownNoteInterface
    {
        $note = $this->createNote();
        $note->setUser($user);

        $note->setSpace($this->targetSpace($user, $input->getFolderId(), $input->getSpaceId()));

        $this->applyInput($note, $input);

        if (null === $input->getPosition()) {
            $note->setPosition($this->nextPosition($note->getSpace(), $note->getFolder()?->getId()));
        }

        $this->entityManager->persist($note);
        $this->entityManager->flush();

        $this->auditCreated($note);

        return $note;
    }

    public function createMany(CoreUserInterface $user, array $inputs): array
    {
        if ([] === $inputs) {
            return [];
        }

        // What `create()` asks the database once per note - the folder, the
        // last position in it - asked once per folder, and a single flush.
        // Note by note, a vault of five hundred cost some three thousand
        // queries and five hundred flushes, each re-encrypting every note
        // already written, inside one request that could time out half-way.
        /** @var array<int|string, NoteFolderInterface|null> $folders */
        $folders = [];
        /** @var array<int|string, int> $nextPosition */
        $nextPosition = [];
        /** @var array<int|string, NoteSpaceInterface> $spaces */
        $spaces = [];
        $notes = [];

        foreach ($inputs as $input) {
            $folderId = $input->getFolderId();
            // Une racine par espace : sans dossier, `spaceId` dit laquelle.
            $key = $folderId ?? 'root:'.($input->getSpaceId() ?? 'personal');

            if (!array_key_exists($key, $folders)) {
                $folders[$key] = null === $folderId ? null : $this->folderRepository->findOneByUserAndId($user, $folderId);
                $spaces[$key] = $folders[$key]?->getSpace() ?? $this->targetSpace($user, null, $input->getSpaceId());
                $nextPosition[$key] = $this->nextPosition($spaces[$key], $folders[$key]?->getId());
            }

            $note = $this->createNote();
            $note->setUser($user);
            $note->setSpace($spaces[$key]);
            $note->setTitle($input->getTitle());
            $note->setContent($input->getContent());
            $note->setTags($input->getTags());
            $note->setCoverUrl($input->getCoverUrl());
            $note->setCoverCreditName($input->getCoverCreditName());
            $note->setCoverCreditUrl($input->getCoverCreditUrl());
            $note->setAppearance(NoteAppearanceEnum::fromNullable($input->getAppearance()));
            if (null !== $input->getCoverPosition()) {
                $note->setCoverPosition($input->getCoverPosition());
            }

            $note->setFolder($folders[$key]);
            $note->setPosition($input->getPosition() ?? $nextPosition[$key]++);

            $this->entityManager->persist($note);
            $notes[] = $note;
        }

        $this->entityManager->flush();

        $this->auditLogger->logMany('notes_markdown', 'note.created', 'MarkdownNote', array_map(
            fn (MarkdownNoteInterface $note): array => ['id' => $note->getId(), 'data' => $this->auditPayload($note)],
            $notes,
        ));

        return $notes;
    }

    public function update(MarkdownNoteInterface $note, MarkdownNoteInputInterface $input): void
    {
        $oldTitle = $note->getTitle();
        $oldContent = $note->getContent();

        $this->applyInput($note, $input);
        $note->bumpVersion();

        $newTitle = $note->getTitle();
        if (null !== $oldTitle && null !== $newTitle && '' !== $oldTitle && $oldTitle !== $newTitle) {
            $this->renameWikiLinks($note, $oldTitle, $newTitle);
        }

        $this->cleanupOrphanedImages($this->imageService->bucketOf($note), $oldContent, $note->getContent(), $note);

        $this->entityManager->flush();

        $this->auditUpdated($note);
    }

    /**
     * Pas un champ du formulaire : l'identifiant ne vient que de l'import, et
     * le laisser passer par l'enregistrement ordinaire laisserait n'importe
     * quel appel rattacher une note à un document Craft qui n'est pas le sien.
     */
    public function markImportedFromCraft(MarkdownNoteInterface $note, string $craftDocumentId): void
    {
        $note->setCraftDocumentId($craftDocumentId);
        $this->entityManager->flush();

        $this->auditLogger->log('notes_markdown', 'note.imported_from_craft', 'MarkdownNote', $note->getId(), [
            ...$this->auditPayload($note),
            'craftDocumentId' => $craftDocumentId,
        ]);
    }

    /**
     * Moves a note to the trash.
     *
     * One note, nothing else: a note has no sub-notes any more, and a folder
     * deleted with its contents is the folder manager's business.
     *
     * The images stay where they are. Cleaning them up here would empty the
     * note of its illustrations while promising it can come back, and a
     * restored page missing half its pictures is worse than no restore at all:
     * they go with the purge, when the note really leaves.
     */
    public function delete(MarkdownNoteInterface $note): void
    {
        if ($note->isTrashed()) {
            return;
        }

        $note->setDeletedAt(new DateTimeImmutable())->setTrashedWithFolderId(null);

        $this->entityManager->flush();

        $this->auditTrashed($note);
    }

    /**
     * Brings a note back.
     *
     * A note restored into a folder that is still in the trash would be
     * unreachable, so it comes back at the root instead.
     */
    public function restore(MarkdownNoteInterface $note): void
    {
        if (!$note->isTrashed()) {
            return;
        }

        $folder = $note->getFolder();
        if ($folder instanceof NoteFolderInterface && $folder->isTrashed()) {
            $note->setFolder(null);
        }

        $note->setDeletedAt(null)->setTrashedWithFolderId(null);

        $this->entityManager->flush();

        $this->auditRestored($note);
    }

    /** Deletes a note for good, images included. */
    public function forceDelete(MarkdownNoteInterface $note): void
    {
        $this->destroy([$note]);
    }

    public function purgeTrashedBefore(DateTimeImmutable $cutoff): int
    {
        return $this->destroy($this->noteRepository->findTrashedBefore($cutoff));
    }

    /**
     * Destroys a set of notes, their images with them.
     *
     * @param list<MarkdownNoteInterface> $notes
     */
    protected function destroy(array $notes): int
    {
        if ([] === $notes) {
            return 0;
        }

        // The audit lines together, before any row goes: inside the loop each
        // line's flush also ran the removal queued before it.
        $this->auditLogger->logMany('notes_markdown', 'note.deleted', 'MarkdownNote', array_map(
            fn (MarkdownNoteInterface $note): array => ['id' => $note->getId(), 'data' => $this->auditPayload($note)],
            $notes,
        ));

        foreach ($notes as $note) {
            $this->cleanupOrphanedImages($this->imageService->bucketOf($note), $this->contentWithHistory($note), null);
            $this->entityManager->remove($note);
        }

        $this->entityManager->flush();

        return count($notes);
    }

    public function duplicate(CoreUserInterface $user, MarkdownNoteInterface $note, string $title): MarkdownNoteInterface
    {
        $space = $note->getSpace();
        $folderId = $note->getFolder()?->getId();

        // Right under its original, as Craft and Notion place a copy: the
        // neighbours after it step down one rank, folders included, since
        // both share one order.
        $this->noteRepository->shiftAfter($space, $folderId, $note->getPosition());
        $this->folderRepository->shiftAfter($space, $folderId, $note->getPosition());

        $bucket = $this->imageService->bucketOf($note);
        $copy = $this->copyOf($user, $note, $note->getFolder(), $space, $title, $this->imageService->copyAsNew($note->getContent(), $bucket, $bucket));
        $copy->setPosition($note->getPosition() + 1);
        $copy->setTemplate($note->isTemplate());

        $this->entityManager->persist($copy);
        $this->entityManager->flush();

        $this->auditCreated($copy);

        return $copy;
    }

    public function createFromTemplate(CoreUserInterface $user, MarkdownNoteInterface $template, ?NoteFolderInterface $folder, NoteSpaceInterface $space, string $title, array $replacements = []): MarkdownNoteInterface
    {
        // Its own copies of the template's pictures, in the space it is
        // written in: shared files would vanish from the template the day
        // the new note drops them.
        $content = $this->imageService->copyAsNew(strtr((string) $template->getContent(), $replacements), $this->imageService->bucketOf($template), $space);
        $note = $this->copyOf($user, $template, $folder, $space, strtr($title, $replacements), $content);
        $note->setPosition($this->nextPosition($space, $folder?->getId()));

        $this->entityManager->persist($note);
        $this->entityManager->flush();

        $this->auditCreated($note);

        return $note;
    }

    public function markTemplate(MarkdownNoteInterface $note, bool $template): void
    {
        $note->setTemplate($template);
        $this->entityManager->flush();

        $this->auditUpdated($note);
    }

    /**
     * A new note carrying another's words and look, but none of its links:
     * no share link, no favourite, no history.
     */
    protected function copyOf(CoreUserInterface $user, MarkdownNoteInterface $source, ?NoteFolderInterface $folder, NoteSpaceInterface $space, string $title, ?string $content): MarkdownNoteInterface
    {
        $note = $this->createNote();
        $note->setUser($user);
        $note->setSpace($space);
        $note->setFolder($folder);
        $note->setTitle($title);
        $note->setContent($content);
        $note->setTags($source->getTags());
        $note->setCoverUrl($source->getCoverUrl());
        $note->setCoverCreditName($source->getCoverCreditName());
        $note->setCoverCreditUrl($source->getCoverCreditUrl());
        $note->setCoverPosition($source->getCoverPosition());
        $note->setAppearance($source->getAppearance());

        return $note;
    }

    public function move(MarkdownNoteInterface $note, ?NoteFolderInterface $folder, ?NoteSpaceInterface $space = null): void
    {
        $target = $folder?->getSpace() ?? $space ?? $note->getSpace();
        $changesPlace = $note->getFolder()?->getId() !== $folder?->getId() || $note->getSpace()->getId() !== $target->getId();

        // A note that changes folder lands after everything already there.
        // It kept its old rank before, which put it at an arbitrary spot
        // among neighbours it had never been ordered against.
        if ($changesPlace) {
            $note->setPosition($this->nextPosition($target, $folder?->getId()));
        }

        $this->changeSpace($note, $target);
        $note->setFolder($folder);
        $this->entityManager->flush();

        $this->auditUpdated($note);
    }

    /**
     * The rank after the last folder or note of a folder (or of a space's
     * root).
     *
     * Folders and notes share one order among siblings, so the tree can put a
     * note before a folder: the next rank is past both, not past the notes
     * alone, or a new note would slip between two folders.
     */
    protected function nextPosition(NoteSpaceInterface $space, ?int $folderId): int
    {
        $max = max(
            $this->noteRepository->findMaxPositionForUserAndFolder($space, $folderId) ?? -1,
            $this->folderRepository->findMaxPositionForUserAndParent($space, $folderId) ?? -1,
        );

        return $max + 1;
    }

    /**
     * Fait passer une note dans un autre espace, images comprises.
     *
     * Ses adresses d'images ne portent que le nom du fichier : le fichier doit
     * donc exister dans le compartiment du nouvel espace, sinon ses nouveaux
     * lecteurs verraient des images cassées. L'auteur ne change pas - il a
     * écrit la note, où qu'elle aille.
     */
    public function changeSpace(MarkdownNoteInterface $note, NoteSpaceInterface $space): void
    {
        if ($space->getId() === $note->getSpace()->getId()) {
            return;
        }

        $from = $this->imageService->bucketOf($note);

        $note->setSpace($space);

        $this->imageService->copyReferenced($note->getContent(), $from, $space);
    }

    /**
     * L'espace d'une création : celui du dossier, sinon celui demandé, sinon
     * l'espace personnel. Le contrôleur a déjà vérifié qu'on y écrit.
     */
    protected function targetSpace(CoreUserInterface $user, ?int $folderId, ?int $spaceId): NoteSpaceInterface
    {
        $folder = null === $folderId ? null : $this->folderRepository->find($folderId);
        if ($folder instanceof NoteFolderInterface) {
            return $folder->getSpace();
        }

        $space = null === $spaceId ? null : $this->spaceRepository->find($spaceId);

        return $space instanceof NoteSpaceInterface ? $space : $this->spaceAccess->personalSpace($user);
    }

    /**
     * Files and ranks a set of notes in one shot.
     *
     * No cycle to guard against any more: a note holds nothing, so the worst
     * a bad payload can do is put a note in a folder that is not its
     * author's, which the folder lookup refuses by scoping on the user.
     */
    public function reorder(CoreUserInterface $user, array $entries): void
    {
        if ([] === $entries) {
            return;
        }

        $ids = array_map(static fn (array $entry): int => (int) $entry['id'], $entries);

        // Ce que la personne peut écrire, dans les deux espaces : une note
        // d'équipe se range par ceux qui en ont le droit, pas par son auteur.
        $byId = [];
        foreach ($this->noteRepository->findBy(['id' => $ids]) as $note) {
            if ($this->spaceAccess->canWriteNote($user, $note)) {
                $byId[(int) $note->getId()] = $note;
            }
        }

        foreach ($entries as $entry) {
            $note = $byId[(int) $entry['id']] ?? null;
            if (null === $note) {
                continue;
            }

            $folderId = $entry['folderId'] ?? null;
            $folder = $this->folderFor($note, null === $folderId ? null : (int) $folderId);

            // Un dossier d'un autre espace, ou d'un autre carnet : on ne range
            // pas là, et on ne range pas non plus à la racine par défaut - la
            // note reste où elle est. Changer d'espace est l'affaire de move().
            if (null !== $folderId && !$folder instanceof NoteFolderInterface) {
                continue;
            }

            $note->setFolder($folder);
            $note->setPosition((int) $entry['position']);
        }

        $this->entityManager->flush();
    }

    public function backlinks(CoreUserInterface $user, MarkdownNoteInterface $note): array
    {
        $title = $note->getTitle();
        if (null === $title || '' === $title) {
            return [];
        }

        $needle = '[['.mb_strtolower($title).']]';
        $results = [];

        foreach ($this->noteRepository->findAllWithContentForUser($user) as $other) {
            if ($other->getId() === $note->getId()) {
                continue;
            }

            $content = $other->getContent();
            if (null === $content) {
                continue;
            }

            if ('' === $content) {
                continue;
            }

            if (!str_contains(mb_strtolower($content), $needle)) {
                continue;
            }

            $results[] = ['id' => $other->getId(), 'title' => $other->getTitle()];
        }

        return $results;
    }

    public function unlinkedMentions(CoreUserInterface $user, MarkdownNoteInterface $note): array
    {
        $title = $note->getTitle();
        if (null === $title || '' === $title) {
            return [];
        }

        $titleLower = mb_strtolower($title);
        $linkedPattern = '[['.$titleLower.']]';
        $results = [];

        foreach ($this->noteRepository->findAllWithContentForUser($user) as $other) {
            if ($other->getId() === $note->getId()) {
                continue;
            }

            $content = $other->getContent();
            if (null === $content) {
                continue;
            }

            if ('' === $content) {
                continue;
            }

            $contentLower = mb_strtolower($content);
            if (!str_contains($contentLower, $titleLower)) {
                continue;
            }

            if (str_contains($contentLower, $linkedPattern)) {
                continue;
            }

            $results[] = ['id' => $other->getId(), 'title' => $other->getTitle()];
        }

        return $results;
    }

    public function graph(CoreUserInterface $user): array
    {
        $notes = $this->noteRepository->findAllWithContentForUser($user);

        $titleToId = [];
        $nodes = [];
        foreach ($notes as $note) {
            $title = $note->getTitle() ?? '';
            $nodes[] = ['id' => $note->getId(), 'title' => '' === $title ? 'Untitled' : $title];
            if ('' !== $title) {
                $titleToId[mb_strtolower($title)] = $note->getId();
            }
        }

        $edges = [];
        foreach ($notes as $note) {
            $content = $note->getContent();
            if (null === $content) {
                continue;
            }

            if ('' === $content) {
                continue;
            }

            if (0 === preg_match_all(self::WIKI_LINK_REGEX, $content, $matches)) {
                continue;
            }

            foreach ($matches[1] as $rawTarget) {
                // strip anchor (#heading) - `[[Title#section]]` still points to "Title"
                $target = mb_strtolower(explode('#', $rawTarget)[0]);
                if ('' === $target) {
                    continue;
                }

                if (!isset($titleToId[$target])) {
                    continue;
                }

                $targetId = $titleToId[$target];
                if ($targetId === $note->getId()) {
                    continue;
                }

                $edges[] = ['source' => $note->getId(), 'target' => $targetId];
            }
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    public function tagCounts(CoreUserInterface $user): array
    {
        return $this->noteRepository->findTagCountsForUser($user);
    }

    public function searchContent(CoreUserInterface $user, string $query): array
    {
        $needle = mb_strtolower(mb_trim($query));
        if ('' === $needle) {
            return [];
        }

        $matches = [];
        // Loads every decrypted note in memory - acceptable for the
        // current per-user volumes (≤ a few hundred notes). If this
        // ever grows, swap for a DB-side `LIKE` against an indexed
        // plain-text column or a real full-text index.
        foreach ($this->noteRepository->findAllWithContentForUser($user) as $note) {
            $content = $note->getContent();
            if (null === $content) {
                continue;
            }

            if ('' === $content) {
                continue;
            }

            if (str_contains(mb_strtolower($content), $needle)) {
                $matches[] = $note->getId();
            }
        }

        return $matches;
    }

    public function renameTag(CoreUserInterface $user, string $oldTag, string $newTag): int
    {
        $oldTag = mb_trim($oldTag);
        $newTag = mb_trim($newTag);
        if ('' === $oldTag || '' === $newTag || $oldTag === $newTag) {
            return 0;
        }

        $affected = $this->rewriteTags($user, [$oldTag => $newTag]);
        if ($affected > 0) {
            $this->entityManager->flush();
            $this->auditTagRenamed([...$this->auditTagsPayload(), 'old' => $oldTag, 'new' => $newTag, 'notes' => $affected]);
        }

        return $affected;
    }

    public function mergeTags(CoreUserInterface $user, array $sourceTags, string $targetTag): int
    {
        $targetTag = mb_trim($targetTag);
        if ('' === $targetTag) {
            return 0;
        }

        $rewrite = [];
        foreach ($sourceTags as $source) {
            $trimmed = mb_trim($source);
            if ('' === $trimmed) {
                continue;
            }

            if ($trimmed === $targetTag) {
                continue;
            }

            $rewrite[$trimmed] = $targetTag;
        }

        if ([] === $rewrite) {
            return 0;
        }

        $affected = $this->rewriteTags($user, $rewrite);
        if ($affected > 0) {
            $this->entityManager->flush();
            $this->auditTagMerged([...$this->auditTagsPayload(), 'sources' => array_keys($rewrite), 'target' => $targetTag, 'notes' => $affected]);
        }

        return $affected;
    }

    public function removeTag(CoreUserInterface $user, string $tag): int
    {
        $tag = mb_trim($tag);
        if ('' === $tag) {
            return 0;
        }

        $affected = 0;
        foreach ($this->writableNotes($user) as $note) {
            $tags = $note->getTags();
            if (!in_array($tag, $tags, true)) {
                continue;
            }

            $note->setTags(array_values(array_filter($tags, static fn (string $existing): bool => $existing !== $tag)));
            $note->bumpVersion();
            ++$affected;
        }

        if ($affected > 0) {
            $this->entityManager->flush();
            $this->auditTagRemoved([...$this->auditTagsPayload(), 'tag' => $tag, 'notes' => $affected]);
        }

        return $affected;
    }

    /**
     * Apply a tag → tag map across all the user's notes, deduping when the
     * target is already present. Returns the count of mutated notes.
     *
     * @param array<string, string> $rewrite source → target
     */
    protected function rewriteTags(CoreUserInterface $user, array $rewrite): int
    {
        $affected = 0;
        foreach ($this->writableNotes($user) as $note) {
            $current = $note->getTags();
            $next = [];
            $seen = [];
            $changed = false;

            foreach ($current as $tag) {
                $resolved = $rewrite[$tag] ?? $tag;
                if ($resolved !== $tag) {
                    $changed = true;
                }

                if (isset($seen[$resolved])) {
                    $changed = true;
                    continue;
                }

                $seen[$resolved] = true;
                $next[] = $resolved;
            }

            if ($changed) {
                $note->setTags($next);
                $note->bumpVersion();
                ++$affected;
            }
        }

        return $affected;
    }

    /**
     * Hook: base payload merged into every tag-operation audit log
     * (`tag.renamed`, `tag.merged`, `tag.removed`). Unlike `auditPayload()`
     * which is per-entity, tag operations are cross-cutting - no single
     * note to capture - so this returns an empty array by default.
     * Clients override to splat-merge custom context (workflow id,
     * triggering user role, etc.) into every tag audit log at once.
     *
     * @return array<string, mixed>
     */
    protected function auditTagsPayload(): array
    {
        return [];
    }

    /**
     * One method per action, each naming its key in full.
     *
     * The single `auditTagsOperation(string $action)` this replaces built the key
     * as `'tag.'.$action`, which reads fine and is invisible to
     * `AuditActionLabelTest`: that test pairs every emitted action against its
     * label, and a key assembled at runtime gives it nothing to pair. It failed
     * on the fragment `notes_markdown.tag.` - the labels were all present, but
     * nothing could prove it. Literals here keep the check honest, and match how
     * the note actions below are already written.
     *
     * @param array<string, mixed> $payload
     */
    protected function auditTagRenamed(array $payload): void
    {
        $this->auditLogger->log('notes_markdown', 'tag.renamed', 'MarkdownNote', null, $payload);
    }

    /** @param array<string, mixed> $payload */
    protected function auditTagMerged(array $payload): void
    {
        $this->auditLogger->log('notes_markdown', 'tag.merged', 'MarkdownNote', null, $payload);
    }

    /** @param array<string, mixed> $payload */
    protected function auditTagRemoved(array $payload): void
    {
        $this->auditLogger->log('notes_markdown', 'tag.removed', 'MarkdownNote', null, $payload);
    }

    /**
     * When a note's title changes, rewrite [[oldTitle]] → [[newTitle]] in all
     * the user's other notes. Case-sensitive substring (matches Onyx).
     * Clients override to customize the wiki-link syntax.
     */
    protected function renameWikiLinks(MarkdownNoteInterface $note, string $oldTitle, string $newTitle): void
    {
        $oldPattern = '[['.$oldTitle.']]';
        $newPattern = '[['.$newTitle.']]';
        $excludeId = $note->getId();

        // Dans l'espace de la note seulement : renommer une note d'un espace
        // partagé ne touche pas aux carnets des autres, qu'on n'a pas le
        // droit d'écrire.
        $scope = $this->noteRepository->findLivingInSpace($note->getSpace());

        foreach ($scope as $other) {
            if (null !== $excludeId && $other->getId() === $excludeId) {
                continue;
            }

            $content = $other->getContent();
            if (null === $content) {
                continue;
            }

            if (!str_contains((string) $content, $oldPattern)) {
                continue;
            }

            $other->setContent(str_replace($oldPattern, $newPattern, $content));
            // Une autre note réécrite : ouverte ailleurs, son éditeur doit le
            // savoir plutôt que remettre l'ancien lien au prochain enregistrement.
            $other->bumpVersion();
        }
    }

    /**
     * Hook: instantiate the concrete note class. Clients override to return
     * their own substituted entity.
     */
    protected function createNote(): MarkdownNoteInterface
    {
        return new MarkdownNote();
    }

    /**
     * Hook: drop image files no longer referenced by the note content.
     * Called from `update` (oldContent vs newContent) and `delete`
     * (oldContent vs null). The set difference is computed against the
     * markdown URL regex in `MarkdownNoteImageService::FILENAME_PATTERN` so a
     * client that picks a different image URL scheme can override this
     * method to scan its own pattern instead.
     */
    protected function cleanupOrphanedImages(CoreUserInterface|NoteSpaceInterface $user, ?string $oldContent, ?string $newContent, ?MarkdownNoteInterface $note = null): void
    {
        $oldFilenames = $this->imageService->extractFilenames($oldContent);
        if ([] === $oldFilenames) {
            return;
        }

        $newFilenames = $this->imageService->extractFilenames($newContent);
        $orphans = array_diff($oldFilenames, $newFilenames, $note instanceof MarkdownNoteInterface ? $this->filenamesInRevisions($note, $oldFilenames) : []);

        foreach ($orphans as $filename) {
            $this->imageService->delete($filename, $user);
        }
    }

    /**
     * The note's text and every past version's, for the pictures that go when
     * the note goes for good: a picture only an old version showed would
     * otherwise stay in storage with nothing left to cite it.
     */
    protected function contentWithHistory(MarkdownNoteInterface $note): string
    {
        $texts = [(string) $note->getContent()];
        foreach ($this->entityManager->getRepository(MarkdownNoteRevision::class)->findBy(['note' => $note]) as $revision) {
            $texts[] = (string) $revision->getContent();
        }

        return implode("\n", $texts);
    }

    /**
     * Among these pictures, the ones a past version of a note still shows.
     *
     * A picture dropped from the text stays in storage while a version cites
     * it: restoring that version must bring the picture back with it.
     *
     * @param list<string> $filenames
     *
     * @return list<string>
     */
    protected function filenamesInRevisions(MarkdownNoteInterface $note, array $filenames): array
    {
        if ([] === $filenames) {
            return [];
        }

        $kept = [];
        foreach ($this->entityManager->getRepository(MarkdownNoteRevision::class)->findBy(['note' => $note]) as $revision) {
            foreach ($this->imageService->extractFilenames($revision->getContent()) as $filename) {
                if (in_array($filename, $filenames, true)) {
                    $kept[$filename] = $filename;
                }
            }
        }

        return array_values($kept);
    }

    /**
     * Hook: hydrate the note from the DTO. Clients override to add custom
     * fields, e.g. parent::applyInput($note, $input); $note->setExtra(...).
     *
     * A folder id that belongs to somebody else resolves to null rather than
     * to their folder: the lookup is scoped to the note's author.
     */
    protected function applyInput(MarkdownNoteInterface $note, MarkdownNoteInputInterface $input): void
    {
        $note->setTitle($input->getTitle());
        $note->setContent($input->getContent());
        $note->setTags($input->getTags());
        $note->setCoverUrl($input->getCoverUrl());
        $note->setCoverCreditName($input->getCoverCreditName());
        $note->setCoverCreditUrl($input->getCoverCreditUrl());
        $note->setAppearance(NoteAppearanceEnum::fromNullable($input->getAppearance()));

        if (null !== $input->getCoverPosition()) {
            $note->setCoverPosition($input->getCoverPosition());
        }

        if (null !== $input->getPosition()) {
            $note->setPosition($input->getPosition());
        }

        $note->setFolder($this->folderFor($note, $input->getFolderId()));
    }

    /**
     * Le dossier où une note peut être rangée : du même espace qu'elle. Null
     * sinon - changer d'espace est l'affaire de move(), pas d'un
     * enregistrement.
     */
    protected function folderFor(MarkdownNoteInterface $note, ?int $folderId): ?NoteFolderInterface
    {
        if (null === $folderId) {
            return null;
        }

        $folder = $this->folderRepository->find($folderId);

        if (!$folder instanceof NoteFolderInterface || $folder->getSpace()->getId() !== $note->getSpace()->getId()) {
            return null;
        }

        return $folder;
    }

    /**
     * Les notes qu'une personne peut réécrire en masse : celles des espaces
     * où elle écrit.
     *
     * @return list<MarkdownNoteInterface>
     */
    protected function writableNotes(CoreUserInterface $user): array
    {
        return array_values(array_filter(
            $this->noteRepository->findAllWithContentForUser($user),
            fn (MarkdownNoteInterface $note): bool => $this->spaceAccess->canWriteNote($user, $note),
        ));
    }

    protected function auditCreated(MarkdownNoteInterface $note): void
    {
        $this->auditLogger->log('notes_markdown', 'note.created', 'MarkdownNote', $note->getId(), $this->auditPayload($note));
    }

    protected function auditUpdated(MarkdownNoteInterface $note): void
    {
        $this->auditLogger->log('notes_markdown', 'note.updated', 'MarkdownNote', $note->getId(), $this->auditPayload($note));
    }

    protected function auditTrashed(MarkdownNoteInterface $note): void
    {
        $this->auditLogger->log('notes_markdown', 'note.trashed', 'MarkdownNote', $note->getId(), $this->auditPayload($note));
    }

    protected function auditRestored(MarkdownNoteInterface $note): void
    {
        $this->auditLogger->log('notes_markdown', 'note.restored', 'MarkdownNote', $note->getId(), $this->auditPayload($note));
    }

    protected function auditDeleted(MarkdownNoteInterface $note): void
    {
        $this->auditLogger->log('notes_markdown', 'note.deleted', 'MarkdownNote', $note->getId(), $this->auditPayload($note));
    }

    /**
     * Hook: build the audit payload. Clients override to splat-merge their
     * own custom fields, e.g.
     *   return [...parent::auditPayload($note), 'custom' => $note->getCustom()];.
     *
     * @return array<string, mixed>
     */
    protected function auditPayload(MarkdownNoteInterface $note): array
    {
        return [
            'title' => $note->getTitle(),
            'tags' => $note->getTags(),
            'folderId' => $note->getFolder()?->getId(),
            'position' => $note->getPosition(),
        ];
    }
}
