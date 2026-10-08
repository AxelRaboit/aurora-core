<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Live\Service;

use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Psr\Cache\CacheItemPoolInterface;

use function sprintf;
use function time;

/**
 * Who is on a note right now.
 *
 * **In the cache, never in the database.** Presence is true for forty
 * seconds; a table of it would be a row written every twenty seconds per
 * reader, and a crash would leave people standing in a room they left. The
 * cache forgetting is the feature, not a limitation.
 *
 * Each page says it is here, and the answer is the list. A reader who closes
 * their tab says nothing more, and drops out of it on its own - which is why
 * there is no "leave" call to get wrong.
 *
 * **Two heartbeats landing together can lose one of them.** The item is read,
 * changed and written back, so simultaneous beats race. The loser reappears on
 * their next beat twenty seconds later, which for "who is looking at this
 * page" is a flicker nobody sees - and the price of avoiding it would be a
 * lock around something that is allowed to be approximate.
 */
final readonly class NotePresence
{
    /**
     * How long a page stays in the list without saying anything.
     *
     * Twice the beat plus a margin: a page that misses one beat to a slow
     * network must not vanish and come back, which reads as somebody leaving
     * the room.
     */
    public const int STALE_AFTER_SECONDS = 50;

    /** How often a page is expected to say it is still here. */
    public const int BEAT_SECONDS = 20;

    public function __construct(private CacheItemPoolInterface $cache) {}

    /**
     * Records that this person is on the note, and returns who else is.
     *
     * `$editing` tells the others whether the page is open in the editor or
     * in the reader: "Marie is writing" and "Marie is reading" are not the
     * same news, and the whole point of showing presence is to stop two
     * people rewriting the same paragraph.
     *
     * @return list<array{userId: int, name: ?string, editing: bool}>
     */
    public function beat(MarkdownNoteInterface $note, CoreUserInterface $user, bool $editing): array
    {
        $item = $this->cache->getItem($this->keyFor($note));
        $people = $this->fresh($this->read($item->get()));

        $people[(int) $user->getId()] = [
            'name' => $user->getName(),
            'editing' => $editing,
            'at' => time(),
        ];

        // Longer than the staleness window, so the item outlives the entries
        // it holds and the pruning is this class's business rather than the
        // cache backend's.
        $item->set($people)->expiresAfter(self::STALE_AFTER_SECONDS * 3);
        $this->cache->save($item);

        return $this->listWithout($people, (int) $user->getId());
    }

    /**
     * Who is on the note, without saying anything oneself.
     *
     * @return list<array{userId: int, name: ?string, editing: bool}>
     */
    public function on(MarkdownNoteInterface $note, ?CoreUserInterface $except = null): array
    {
        $people = $this->fresh($this->read($this->cache->getItem($this->keyFor($note))->get()));

        return $this->listWithout($people, $except instanceof CoreUserInterface ? (int) $except->getId() : null);
    }

    /**
     * What came back from the cache, as a shape this class can rely on.
     *
     * **The one place the value is untrusted.** A cache outlives a deploy, so
     * what is in there was written by whatever version wrote it; anything that
     * does not look like an entry is dropped rather than carried forward with
     * a default, because somebody with no timestamp is not somebody who is
     * here. Past this method the shape is known, which is why nothing below it
     * guards again.
     *
     * @return array<int, array{name: ?string, editing: bool, at: int}>
     */
    private function read(mixed $stored): array
    {
        if (!is_array($stored)) {
            return [];
        }

        $people = [];
        foreach ($stored as $userId => $one) {
            if (!is_int($userId)) {
                continue;
            }

            if (!is_array($one)) {
                continue;
            }

            if (!is_int($one['at'] ?? null)) {
                continue;
            }

            $name = $one['name'] ?? null;

            $people[$userId] = [
                'name' => is_string($name) ? $name : null,
                'editing' => true === ($one['editing'] ?? null),
                'at' => $one['at'],
            ];
        }

        return $people;
    }

    /**
     * @param array<int, array{name: ?string, editing: bool, at: int}> $people
     *
     * @return array<int, array{name: ?string, editing: bool, at: int}>
     */
    private function fresh(array $people): array
    {
        $cutoff = time() - self::STALE_AFTER_SECONDS;

        return array_filter($people, static fn (array $one): bool => $one['at'] >= $cutoff);
    }

    /**
     * @param array<int, array{name: ?string, editing: bool, at: int}> $people
     *
     * @return list<array{userId: int, name: ?string, editing: bool}>
     */
    private function listWithout(array $people, ?int $exceptId): array
    {
        $list = [];
        foreach ($people as $userId => $one) {
            if ($userId === $exceptId) {
                continue;
            }

            $list[] = ['userId' => $userId, 'name' => $one['name'], 'editing' => $one['editing']];
        }

        return $list;
    }

    private function keyFor(MarkdownNoteInterface $note): string
    {
        return sprintf('aurora.notes.presence.%d', (int) $note->getId());
    }
}
