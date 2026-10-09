<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Search;

use DateTimeImmutable;

use function in_array;
use function mb_ltrim;
use function mb_substr;
use function mb_trim;
use function preg_match;
use function preg_match_all;

/**
 * What was typed in a notes search, read (10/10/2026).
 *
 * - words: each one must be found somewhere in the note;
 * - `"an exact phrase"`: found as it is written;
 * - `-word`: the note must not contain it;
 * - filters, `key:value`, French or English: `tag:client`, `dossier:Clients`,
 *   `espace:…`, `tâche:à-faire|faite|toutes`, `a:commentaire|tâche|image|lien|propriété`,
 *   `modifiée:>2026-09-01` (or `<`), `titre:mot`; any other key is a
 *   property of the note: `statut:"En cours"`.
 *
 * Everything is folded (no accents, no case) on the way in.
 */
final readonly class NoteSearchQuery
{
    private const array TAG_KEYS = ['tag', 'etiquette', 'tags'];

    private const array FOLDER_KEYS = ['dossier', 'folder', 'in', 'dans'];

    private const array SPACE_KEYS = ['espace', 'space'];

    private const array TASK_KEYS = ['tache', 'task', 'taches', 'tasks'];

    private const array HAS_KEYS = ['a', 'has', 'avec', 'with'];

    private const array MODIFIED_KEYS = ['modifiee', 'modified', 'modif', 'date'];

    private const array TITLE_KEYS = ['titre', 'title'];

    /**
     * @param list<string>                            $terms
     * @param list<string>                            $phrases
     * @param list<string>                            $excluded
     * @param list<string>                            $tags
     * @param list<string>                            $titleTerms
     * @param list<string>                            $has
     * @param list<array{key: string, value: string}> $properties
     */
    public function __construct(
        public array $terms = [],
        public array $phrases = [],
        public array $excluded = [],
        public array $tags = [],
        public ?string $folder = null,
        public ?string $space = null,
        public ?string $task = null,
        public array $has = [],
        public ?DateTimeImmutable $modifiedAfter = null,
        public ?DateTimeImmutable $modifiedBefore = null,
        public array $titleTerms = [],
        public array $properties = [],
    ) {}

    public static function parse(string $raw): self
    {
        $terms = [];
        $phrases = [];
        $excluded = [];
        $tags = [];
        $folder = null;
        $space = null;
        $task = null;
        $has = [];
        $after = null;
        $before = null;
        $titleTerms = [];
        $properties = [];

        preg_match_all('/(-?)(?:([\p{L}\p{N}_-]+):)?(?:"([^"]*)"|(\S+))/u', mb_substr($raw, 0, 500), $tokens, PREG_SET_ORDER);

        foreach ($tokens as $token) {
            $negated = '-' === $token[1];
            $key = '' === ($token[2] ?? '') ? null : NoteSearchText::fold($token[2]);
            $quoted = isset($token[3]) && '' !== $token[3];
            $value = mb_trim($quoted ? $token[3] : ($token[4] ?? ''));
            if ('' === $value) {
                continue;
            }

            $folded = NoteSearchText::fold($value);

            if (null === $key) {
                if ($negated) {
                    $excluded[] = $folded;
                } elseif ($quoted) {
                    $phrases[] = $folded;
                } else {
                    $terms[] = $folded;
                }

                continue;
            }

            if (in_array($key, self::TAG_KEYS, true)) {
                $tags[] = mb_ltrim($folded, '#');
            } elseif (in_array($key, self::FOLDER_KEYS, true)) {
                $folder = $folded;
            } elseif (in_array($key, self::SPACE_KEYS, true)) {
                $space = $folded;
            } elseif (in_array($key, self::TASK_KEYS, true)) {
                $task = match (true) {
                    in_array($folded, ['faite', 'faites', 'done', 'fait', 'cochee', 'cochees'], true) => 'done',
                    in_array($folded, ['toutes', 'all', 'tout'], true) => 'any',
                    default => 'todo',
                };
            } elseif (in_array($key, self::HAS_KEYS, true)) {
                $has[] = self::hasKind($folded);
            } elseif (in_array($key, self::MODIFIED_KEYS, true)) {
                if (1 === preg_match('/^([<>])?=?(\d{4}-\d{2}-\d{2})$/', $folded, $match)) {
                    $day = DateTimeImmutable::createFromFormat('!Y-m-d', $match[2]);
                    if ($day instanceof DateTimeImmutable) {
                        if ('<' === $match[1]) {
                            $before = $day;
                        } else {
                            $after = $day;
                        }
                    }
                }
            } elseif (in_array($key, self::TITLE_KEYS, true)) {
                $titleTerms[] = $folded;
            } else {
                $properties[] = ['key' => $key, 'value' => $folded];
            }
        }

        return new self($terms, $phrases, $excluded, $tags, $folder, $space, $task, $has, $after, $before, $titleTerms, $properties);
    }

    /** Whether anything at all was asked. */
    public function isEmpty(): bool
    {
        return [] === $this->terms && [] === $this->phrases && [] === $this->excluded && [] === $this->tags
            && null === $this->folder && null === $this->space && null === $this->task && [] === $this->has
            && !$this->modifiedAfter instanceof DateTimeImmutable && !$this->modifiedBefore instanceof DateTimeImmutable && [] === $this->titleTerms
            && [] === $this->properties;
    }

    /** Whether there are words to look for, rather than filters only. */
    public function hasText(): bool
    {
        return [] !== $this->terms || [] !== $this->phrases || [] !== $this->titleTerms;
    }

    /** @return list<string> what to highlight */
    public function needles(): array
    {
        return [...$this->terms, ...$this->phrases, ...$this->titleTerms];
    }

    private static function hasKind(string $folded): string
    {
        return match (true) {
            in_array($folded, ['commentaire', 'commentaires', 'comment', 'comments'], true) => 'comment',
            in_array($folded, ['tache', 'taches', 'task', 'tasks', 'todo'], true) => 'task',
            in_array($folded, ['image', 'images', 'photo', 'photos'], true) => 'image',
            in_array($folded, ['lien', 'liens', 'link', 'links'], true) => 'link',
            in_array($folded, ['propriete', 'proprietes', 'property', 'properties'], true) => 'property',
            default => $folded,
        };
    }
}
