<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Manager;

use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Notes\Markdown\Dto\MarkdownNoteInputInterface;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNote;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Markdown\Repository\MarkdownNoteRepository;
use Aurora\Module\Notes\Markdown\Service\MarkdownNoteImageService;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(MarkdownNoteManagerInterface::class)]
class MarkdownNoteManager implements MarkdownNoteManagerInterface
{
    /** Matches [[anything]] occurrences in markdown content. */
    protected const string WIKI_LINK_REGEX = '/\[\[([^\]]+)\]\]/';

    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly MarkdownNoteRepository $noteRepository,
        protected readonly AuditLogger $auditLogger,
        protected readonly MarkdownNoteImageService $imageService,
    ) {}

    public function create(CoreUserInterface $user, MarkdownNoteInputInterface $input): MarkdownNoteInterface
    {
        $note = $this->createNote();
        $note->setUser($user);

        $this->applyInput($note, $input);

        if (null === $input->getPosition()) {
            $maxPosition = $this->noteRepository->findMaxPositionForUserAndParent($user, $input->getParentId());
            $note->setPosition(null === $maxPosition ? 0 : $maxPosition + 1);
        }

        $this->entityManager->persist($note);
        $this->entityManager->flush();

        $this->auditCreated($note);

        return $note;
    }

    public function update(MarkdownNoteInterface $note, MarkdownNoteInputInterface $input): void
    {
        $oldTitle = $note->getTitle();
        $oldContent = $note->getContent();

        $this->applyInput($note, $input);

        $newTitle = $note->getTitle();
        if (null !== $oldTitle && null !== $newTitle && '' !== $oldTitle && $oldTitle !== $newTitle) {
            $this->renameWikiLinks($note->getUser(), $note->getId(), $oldTitle, $newTitle);
        }

        $this->cleanupOrphanedImages($note->getUser(), $oldContent, $note->getContent());

        $this->entityManager->flush();

        $this->auditUpdated($note);
    }

    public function delete(MarkdownNoteInterface $note): void
    {
        $this->auditDeleted($note);

        $this->cleanupOrphanedImages($note->getUser(), $note->getContent(), null);

        $this->entityManager->remove($note);
        $this->entityManager->flush();
    }

    public function move(MarkdownNoteInterface $note, ?MarkdownNoteInterface $parent): void
    {
        $note->setParent($parent);
        $this->entityManager->flush();
    }

    public function reorder(CoreUserInterface $user, array $entries): void
    {
        if ([] === $entries) {
            return;
        }

        $ids = array_map(static fn (array $entry): int => $entry['id'], $entries);
        $notes = $this->noteRepository->findBy(['id' => $ids, 'user' => $user]);

        $byId = [];
        foreach ($notes as $note) {
            $byId[$note->getId()] = $note;
        }

        // Build the intended parent map first so we can detect cycles before
        // mutating any entity (otherwise partial state could mask a cycle).
        $parentMap = [];
        foreach ($entries as $entry) {
            $id = (int) $entry['id'];
            if (!isset($byId[$id])) {
                continue;
            }

            $parentId = $entry['parentId'] ?? null;
            $parentMap[$id] = null === $parentId ? null : (int) $parentId;
        }

        foreach ($parentMap as $id => $initialParentId) {
            $visited = [$id => true];
            $current = $initialParentId;
            while (null !== $current) {
                if (isset($visited[$current])) {
                    throw new InvalidArgumentException(sprintf('Reorder would create a cycle at note %d.', $id));
                }

                $visited[$current] = true;
                $current = $parentMap[$current] ?? null;
            }
        }

        // Detach parents first to side-step transient cycles while we're
        // reshuffling - mirrors the TaxonomyManager pattern.
        foreach (array_keys($parentMap) as $id) {
            $byId[$id]->setParent(null);
        }

        foreach ($entries as $entry) {
            $id = (int) $entry['id'];
            $note = $byId[$id] ?? null;
            if (null === $note) {
                continue;
            }

            $parentId = $parentMap[$id] ?? null;
            $note->setParent(null !== $parentId ? ($byId[$parentId] ?? null) : null);
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
        foreach ($this->noteRepository->findAllWithContentForUser($user) as $note) {
            $tags = $note->getTags();
            if (!in_array($tag, $tags, true)) {
                continue;
            }

            $note->setTags(array_values(array_filter($tags, static fn (string $existing): bool => $existing !== $tag)));
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
        foreach ($this->noteRepository->findAllWithContentForUser($user) as $note) {
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
    protected function renameWikiLinks(CoreUserInterface $user, ?int $excludeId, string $oldTitle, string $newTitle): void
    {
        $oldPattern = '[['.$oldTitle.']]';
        $newPattern = '[['.$newTitle.']]';

        foreach ($this->noteRepository->findAllWithContentForUser($user) as $other) {
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
    protected function cleanupOrphanedImages(CoreUserInterface $user, ?string $oldContent, ?string $newContent): void
    {
        $oldFilenames = $this->imageService->extractFilenames($oldContent);
        if ([] === $oldFilenames) {
            return;
        }

        $newFilenames = $this->imageService->extractFilenames($newContent);
        $orphans = array_diff($oldFilenames, $newFilenames);

        foreach ($orphans as $filename) {
            $this->imageService->delete($filename, $user);
        }
    }

    /**
     * Hook: hydrate the note from the DTO. Clients override to add custom
     * fields, e.g. parent::applyInput($note, $input); $note->setExtra(...).
     */
    protected function applyInput(MarkdownNoteInterface $note, MarkdownNoteInputInterface $input): void
    {
        $note->setTitle($input->getTitle());
        $note->setContent($input->getContent());
        $note->setTags($input->getTags());

        if (null !== $input->getPosition()) {
            $note->setPosition($input->getPosition());
        }

        if (null !== $input->getParentId()) {
            $parent = $this->noteRepository->findOneByUserAndId($note->getUser(), $input->getParentId());
            $note->setParent($parent);
        } else {
            $note->setParent(null);
        }
    }

    protected function auditCreated(MarkdownNoteInterface $note): void
    {
        $this->auditLogger->log('notes_markdown', 'note.created', 'MarkdownNote', $note->getId(), $this->auditPayload($note));
    }

    protected function auditUpdated(MarkdownNoteInterface $note): void
    {
        $this->auditLogger->log('notes_markdown', 'note.updated', 'MarkdownNote', $note->getId(), $this->auditPayload($note));
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
            'parentId' => $note->getParent()?->getId(),
            'position' => $note->getPosition(),
        ];
    }
}
