<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Search;

use Aurora\Module\Notes\Folder\Entity\NoteFolderInterface;
use DateTimeImmutable;
use Aurora\Module\Notes\Comment\Repository\NoteCommentRepository;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Markdown\Repository\MarkdownNoteRepository;
use Aurora\Module\Notes\Markdown\Service\NoteTasks;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Repository\UserRepository;
use DateTimeInterface;

use function array_filter;
use function array_map;
use function array_slice;
use function array_unique;
use function array_values;
use function arsort;
use function count;
use function hrtime;
use function implode;
use function in_array;
use function is_bool;
use function is_scalar;
use function mb_strlen;
use function mb_substr;
use function mb_substr_count;
use function mb_trim;
use function min;
use function preg_match;
use function preg_quote;
use function round;
use function str_contains;
use function str_starts_with;
use function usort;

/**
 * Searching the notes a person can read (10/10/2026), the way Obsidian and
 * Notion do: everything a note is made of, words in any order, accents and
 * case ignored, the best match first, and the passages that matched.
 *
 * **Read in PHP, not in SQL.** Titles and texts are encrypted in the
 * database, so a `LIKE` sees ciphertext: the notes are loaded, decrypted by
 * Doctrine, and read here. Nothing is cached in clear, which would undo the
 * encryption on disk. Fine for the few hundred notes of a person; `tookMs`
 * says when it stops being.
 *
 * Fields, by weight: the title, the headings, the tags, the properties, the
 * text, the comments. Every word must be found in one of them.
 */
final readonly class NoteSearch
{
    public const int DEFAULT_LIMIT = 60;

    public function __construct(
        private MarkdownNoteRepository $noteRepository,
        private NoteCommentRepository $commentRepository,
        private UserRepository $userRepository,
    ) {}

    /**
     * @return array{
     *     results: list<array<string, mixed>>,
     *     total: int,
     *     facets: array{tags: array<string, int>, folders: list<array{id: int, name: string, count: int}>, spaces: list<array{id: int, name: ?string, count: int}>},
     *     tookMs: float,
     *     needles: list<string>
     * }
     */
    public function search(CoreUserInterface $user, string $raw, string $sort = 'relevance', int $limit = self::DEFAULT_LIMIT): array
    {
        $started = hrtime(true);
        $query = NoteSearchQuery::parse($raw);
        if ($query->isEmpty()) {
            return ['results' => [], 'total' => 0, 'facets' => ['tags' => [], 'folders' => [], 'spaces' => []], 'tookMs' => 0.0, 'needles' => []];
        }

        $notes = $this->noteRepository->findAllWithContentForUser($user);
        $comments = $this->commentRepository->bodiesForNotes(array_values(array_filter(array_map(static fn (MarkdownNoteInterface $note): ?int => $note->getId(), $notes))));
        $people = $this->peopleNames($notes);

        $matches = [];
        foreach ($notes as $note) {
            $match = $this->match($note, $query, $comments[(int) $note->getId()] ?? [], $people);
            if (null !== $match) {
                $matches[] = $match;
            }
        }

        usort($matches, 'date' === $sort
            ? static fn (array $left, array $right): int => $right['updatedAt'] <=> $left['updatedAt']
            : static fn (array $left, array $right): int => [$right['score'], $right['updatedAt']] <=> [$left['score'], $left['updatedAt']]);

        return [
            'results' => array_slice($matches, 0, $limit),
            'total' => count($matches),
            'facets' => $this->facets($matches),
            'tookMs' => round((hrtime(true) - $started) / 1e6, 1),
            // What the page highlights in the note it opens.
            'needles' => $query->needles(),
        ];
    }

    /**
     * The ids only, best first: the side panel filters its tree with them.
     *
     * @return array{ids: list<int>, snippets: array<int, string>}
     */
    public function ids(CoreUserInterface $user, string $raw): array
    {
        $found = $this->search($user, $raw, 'relevance', 500);
        $snippets = [];
        foreach ($found['results'] as $result) {
            foreach ($result['snippets'] as $snippet) {
                if ('title' !== $snippet['field']) {
                    $snippets[$result['id']] = $snippet['text'];
                    break;
                }
            }
        }

        return ['ids' => array_map(static fn (array $result): int => $result['id'], $found['results']), 'snippets' => $snippets];
    }

    /**
     * @param list<string>       $comments
     * @param array<int, string> $people
     *
     * @return array<string, mixed>|null
     */
    private function match(MarkdownNoteInterface $note, NoteSearchQuery $query, array $comments, array $people): ?array
    {
        $title = (string) $note->getTitle();
        $content = (string) $note->getContent();
        $tags = $note->getTags();
        $properties = $note->getProperties();

        if (!$this->passesFilters($note, $query, $content, $tags, $properties, $comments, $people)) {
            return null;
        }

        $fields = [
            'title' => NoteSearchText::fold($title),
            'headings' => NoteSearchText::fold(implode("\n", NoteSearchText::headings($content))),
            'tags' => NoteSearchText::fold(implode(' ', $tags)),
            'properties' => NoteSearchText::fold(implode("\n", $this->propertyLines($properties, $people))),
            'content' => NoteSearchText::fold($plain = NoteSearchText::plain($content)),
            'comments' => NoteSearchText::fold(implode("\n", $comments)),
        ];
        $everything = implode("\n", $fields);

        foreach ($query->excluded as $word) {
            if (str_contains($everything, $word)) {
                return null;
            }
        }

        foreach ($query->titleTerms as $word) {
            if (!str_contains($fields['title'], $word)) {
                return null;
            }
        }

        $score = 0.0;
        $count = 0;
        foreach ([...$query->terms, ...$query->phrases] as $word) {
            if (!str_contains($everything, $word)) {
                return null;
            }

            $score += $this->weigh($word, $fields, $tags);
            $count += mb_substr_count($everything, $word);
        }

        foreach ($query->titleTerms as $word) {
            $score += 12;
            $count += mb_substr_count($fields['title'], $word);
        }

        $needles = $query->needles();

        return [
            'id' => (int) $note->getId(),
            'title' => $title,
            'titleRanges' => NoteSearchText::ranges($title, $needles),
            'icon' => $note->getIcon(),
            'tags' => array_values($tags),
            'folderId' => $note->getFolder()?->getId(),
            'folderName' => $note->getFolder()?->getName(),
            'spaceId' => $note->getSpace()->getId(),
            'spaceName' => $note->getSpace()->getName(),
            'spacePersonal' => $note->getSpace()->isPersonal(),
            'updatedAt' => $note->getUpdatedAt()->format(DateTimeInterface::ATOM),
            'score' => $score,
            'count' => $count,
            'snippets' => [] === $needles ? $this->opening($plain) : $this->snippetsOf($needles, $content, $plain, $properties, $people, $comments),
        ];
    }

    /**
     * How much a word found in a note is worth: in the title most, in the
     * text least, a few occurrences counting more than one.
     *
     * @param array<string, string> $fields
     * @param list<string>          $tags
     */
    private function weigh(string $word, array $fields, array $tags): float
    {
        $score = 0.0;
        if ($fields['title'] === $word) {
            $score += 40;
        } elseif (str_starts_with($fields['title'], $word)) {
            $score += 20;
        } elseif (1 === preg_match('/(^|[^\p{L}\p{N}])'.preg_quote($word, '/').'/u', $fields['title'])) {
            $score += 14;
        } elseif (str_contains($fields['title'], $word)) {
            $score += 10;
        }

        if (str_contains($fields['headings'], $word)) {
            $score += 6;
        }

        if (in_array($word, array_map(NoteSearchText::fold(...), $tags), true)) {
            $score += 8;
        } elseif (str_contains($fields['tags'], $word)) {
            $score += 4;
        }

        if (str_contains($fields['properties'], $word)) {
            $score += 4;
        }

        $score += min(8, mb_substr_count($fields['content'], $word));
        if (str_contains($fields['comments'], $word)) {
            $score += 2;
        }

        // A longer word is a more precise one.
        return $score * (1 + min(mb_strlen($word), 12) / 24);
    }

    /**
     * @param list<string>                                         $tags
     * @param list<array{key: string, type: string, value: mixed}> $properties
     * @param list<string>                                         $comments
     * @param array<int, string>                                   $people
     */
    private function passesFilters(MarkdownNoteInterface $note, NoteSearchQuery $query, string $content, array $tags, array $properties, array $comments, array $people): bool
    {
        $foldedTags = array_map(NoteSearchText::fold(...), $tags);
        foreach ($query->tags as $tag) {
            if (!in_array($tag, $foldedTags, true)) {
                return false;
            }
        }

        if (null !== $query->folder) {
            $folder = $note->getFolder();
            $names = [];
            for ($current = $folder; $current instanceof NoteFolderInterface; $current = $current->getParent()) {
                $names[] = NoteSearchText::fold((string) $current->getName());
            }

            if ([] === array_filter($names, static fn (string $name): bool => str_contains($name, $query->folder))) {
                return false;
            }
        }

        if (null !== $query->space && !str_contains(NoteSearchText::fold((string) $note->getSpace()->getName()), $query->space)) {
            return false;
        }

        if ($query->modifiedAfter instanceof DateTimeImmutable && $note->getUpdatedAt() < $query->modifiedAfter) {
            return false;
        }

        if ($query->modifiedBefore instanceof DateTimeImmutable && $note->getUpdatedAt() >= $query->modifiedBefore) {
            return false;
        }

        if (null !== $query->task) {
            $tasks = NoteTasks::tasksIn($content);
            $wanted = array_filter($tasks, static fn (array $task): bool => match ($query->task) {
                'done' => $task['done'],
                'any' => true,
                default => !$task['done'],
            });
            if ([] === $wanted) {
                return false;
            }
        }

        foreach ($query->has as $kind) {
            $present = match ($kind) {
                'comment' => [] !== $comments,
                'task' => [] !== NoteTasks::tasksIn($content),
                'image' => 1 === preg_match('/!\[[^\]]*\]\([^)]+\)/', $content),
                'link' => 1 === preg_match('/\[\[[^\]]+\]\]|\]\(https?:/', $content),
                'property' => [] !== $properties,
                default => true,
            };
            if (!$present) {
                return false;
            }
        }

        foreach ($query->properties as ['key' => $key, 'value' => $value]) {
            $found = false;
            foreach ($properties as $property) {
                if (!str_contains(NoteSearchText::fold((string) $property['key']), $key)) {
                    continue;
                }

                if (str_contains(NoteSearchText::fold($this->propertyValue($property, $people)), $value)) {
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                return false;
            }
        }

        return true;
    }

    /**
     * The passages to show: the headings, properties and comments that
     * matched, then the text around its matches.
     *
     * @param list<string>                                         $needles
     * @param list<array{key: string, type: string, value: mixed}> $properties
     * @param array<int, string>                                   $people
     * @param list<string>                                         $comments
     *
     * @return list<array{field: string, text: string, ranges: list<array{0: int, 1: int}>}>
     */
    private function snippetsOf(array $needles, string $content, string $plain, array $properties, array $people, array $comments): array
    {
        $snippets = [];
        foreach (NoteSearchText::headings($content) as $heading) {
            $ranges = NoteSearchText::ranges($heading, $needles);
            if ([] !== $ranges) {
                $snippets[] = ['field' => 'heading', 'text' => $heading, 'ranges' => $ranges];
            }
        }

        foreach ($this->propertyLines($properties, $people) as $line) {
            $ranges = NoteSearchText::ranges($line, $needles);
            if ([] !== $ranges) {
                $snippets[] = ['field' => 'property', 'text' => $line, 'ranges' => $ranges];
            }
        }

        foreach (NoteSearchText::snippets($plain, $needles) as $passage) {
            $snippets[] = ['field' => 'content', ...$passage];
        }

        foreach ($comments as $comment) {
            foreach (NoteSearchText::snippets($comment, $needles, 1, 50) as $passage) {
                $snippets[] = ['field' => 'comment', ...$passage];
            }
        }

        return array_slice($snippets, 0, 5);
    }

    /**
     * The start of the note, for a search by filters only.
     *
     * @return list<array{field: string, text: string, ranges: list<array{0: int, 1: int}>}>
     */
    private function opening(string $plain): array
    {
        $text = mb_trim($plain);
        if ('' === $text) {
            return [];
        }

        return [['field' => 'content', 'text' => mb_strlen($text) > 160 ? mb_substr($text, 0, 160).'…' : $text, 'ranges' => []]];
    }

    /**
     * @param list<array{key: string, type: string, value: mixed}> $properties
     * @param array<int, string>                                   $people
     *
     * @return list<string>
     */
    private function propertyLines(array $properties, array $people): array
    {
        $lines = [];
        foreach ($properties as $property) {
            $value = $this->propertyValue($property, $people);
            if ('' !== $value) {
                $lines[] = $property['key'].' : '.$value;
            }
        }

        return $lines;
    }

    /**
     * @param array{key: string, type: string, value: mixed} $property
     * @param array<int, string>                             $people
     */
    private function propertyValue(array $property, array $people): string
    {
        $value = $property['value'] ?? null;
        if ('person' === $property['type']) {
            return $people[(int) $value] ?? '';
        }

        if (is_bool($value)) {
            return $value ? $property['key'] : '';
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * The names of the people the notes' « person » properties point at.
     *
     * @param list<MarkdownNoteInterface> $notes
     *
     * @return array<int, string>
     */
    private function peopleNames(array $notes): array
    {
        $ids = [];
        foreach ($notes as $note) {
            foreach ($note->getProperties() as $property) {
                if ('person' === $property['type'] && is_scalar($property['value'])) {
                    $ids[] = (int) $property['value'];
                }
            }
        }

        $ids = array_values(array_unique($ids));
        if ([] === $ids) {
            return [];
        }

        $names = [];
        foreach ($this->userRepository->findBy(['id' => $ids]) as $person) {
            $names[(int) $person->getId()] = $person->getName();
        }

        return $names;
    }

    /**
     * How the matches spread over tags, folders and spaces, for the chips
     * that narrow a search.
     *
     * @param list<array<string, mixed>> $matches
     *
     * @return array{tags: array<string, int>, folders: list<array{id: int, name: string, count: int}>, spaces: list<array{id: int, name: ?string, count: int}>}
     */
    private function facets(array $matches): array
    {
        $tags = [];
        $folders = [];
        $spaces = [];
        foreach ($matches as $match) {
            foreach ($match['tags'] as $tag) {
                $tags[$tag] = ($tags[$tag] ?? 0) + 1;
            }

            if (null !== $match['folderId']) {
                $folders[$match['folderId']] ??= ['id' => $match['folderId'], 'name' => (string) $match['folderName'], 'count' => 0];
                ++$folders[$match['folderId']]['count'];
            }

            $spaces[$match['spaceId']] ??= ['id' => (int) $match['spaceId'], 'name' => $match['spacePersonal'] ? null : $match['spaceName'], 'count' => 0];
            ++$spaces[$match['spaceId']]['count'];
        }

        arsort($tags);
        $byCount = static fn (array $left, array $right): int => $right['count'] <=> $left['count'];
        $folders = array_values($folders);
        usort($folders, $byCount);
        $spaces = array_values($spaces);
        usort($spaces, $byCount);

        return ['tags' => array_slice($tags, 0, 12, true), 'folders' => array_slice($folders, 0, 8), 'spaces' => $spaces];
    }
}
