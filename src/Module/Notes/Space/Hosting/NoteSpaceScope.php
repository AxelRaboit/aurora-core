<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Space\Hosting;

use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
use Aurora\Module\Notes\Space\Enum\NoteSpaceScopeEnum;
use Aurora\Module\Notes\Space\Repository\NoteSpaceRepository;
use Aurora\Module\Notes\Space\Service\NoteSpaceAccess;
use Doctrine\ORM\QueryBuilder;
use LogicException;
use Symfony\Contracts\Service\ResetInterface;

use function sprintf;

/**
 * The note spaces the current request works with.
 *
 * **One rule, read in two places.** Every list of the engine filters on the
 * spaces a person reads ({@see NoteSpaceRepository::readableSubquery()}),
 * and every note opened by its id goes through
 * {@see NoteSpaceAccess}. The scope narrows
 * both, so a screen cannot list one set of spaces and open another:
 *
 * - **All**, the default: every space the person reads. Commands, the general
 *   trash, the search palette.
 * - **Module**: the Notes module's own screens. The lists leave out the
 *   spaces another module hosts; a hosted note opened by its id still answers,
 *   so a restore from the trash or an old notification keeps working - the
 *   Notes controllers send the reader on to the host's page.
 * - **Hosts**: the hosted spaces only, lists and ids alike. What an image
 *   route serves to somebody who reads hosted notes without the module.
 * - **Hosted**: one space, the one its host opened for this request. Nothing
 *   else exists, lists and ids alike: the request was let in for that space.
 *
 * Set per request by {@see NoteSpaceScopeSubscriber}, or for the length of a
 * piece of work with {@see self::within()}.
 */
final class NoteSpaceScope implements ResetInterface
{
    /**
     * The query parameter a request names its host space with:
     * `<host key>:<host reference>`, see {@see NoteSpaceHosts::enter()}.
     */
    public const string HOST_PARAMETER = 'notesHost';

    private NoteSpaceScopeEnum $mode = NoteSpaceScopeEnum::All;

    private ?HostedNoteSpace $hosted = null;

    /**
     * The DQL condition a list puts on a space alias. Its two parameters are
     * set by {@see self::bind()}; they are always both named, so always both
     * set.
     */
    public static function clause(string $spaceAlias): string
    {
        return sprintf(
            "(:spaceScope = '%2\$s' OR (:spaceScope = '%3\$s' AND %1\$s.managedBy IS NULL) "
            ."OR (:spaceScope = '%4\$s' AND %1\$s.managedBy IS NOT NULL) "
            ."OR (:spaceScope = '%5\$s' AND %1\$s.id = :spaceScopeId))",
            $spaceAlias,
            NoteSpaceScopeEnum::All->value,
            NoteSpaceScopeEnum::Module->value,
            NoteSpaceScopeEnum::Hosts->value,
            NoteSpaceScopeEnum::Hosted->value,
        );
    }

    public function bind(QueryBuilder $queryBuilder): QueryBuilder
    {
        return $queryBuilder
            ->setParameter('spaceScope', $this->mode->value)
            ->setParameter('spaceScopeId', $this->hosted?->space->getId() ?? 0);
    }

    public function mode(): NoteSpaceScopeEnum
    {
        return $this->mode;
    }

    public function restrictToModule(): void
    {
        $this->mode = NoteSpaceScopeEnum::Module;
        $this->hosted = null;
    }

    public function restrictToHosts(): void
    {
        $this->mode = NoteSpaceScopeEnum::Hosts;
        $this->hosted = null;
    }

    public function confineTo(HostedNoteSpace $hosted): void
    {
        $this->mode = NoteSpaceScopeEnum::Hosted;
        $this->hosted = $hosted;
    }

    /**
     * Whether a host vouched for this request: one hosted space, or the
     * hosted spaces only. The engine then answers without the module's
     * privilege, see {@see HostedNoteSpaceVoter}.
     */
    public function isHosted(): bool
    {
        return NoteSpaceScopeEnum::Hosted === $this->mode || NoteSpaceScopeEnum::Hosts === $this->mode;
    }

    /** The space a host opened for this request, null outside one. */
    public function hosted(): ?HostedNoteSpace
    {
        return $this->hosted;
    }

    /**
     * Whether a space opened by its id belongs to this request.
     *
     * The Module scope admits every space here, hosted ones included: it only
     * thins the lists. See the class comment.
     */
    public function admits(NoteSpaceInterface $space): bool
    {
        return match ($this->mode) {
            NoteSpaceScopeEnum::All, NoteSpaceScopeEnum::Module => true,
            NoteSpaceScopeEnum::Hosts => $space->isManaged(),
            NoteSpaceScopeEnum::Hosted => null !== $space->getId() && $space->getId() === $this->hosted?->space->getId(),
        };
    }

    /**
     * What every engine route generated for this request carries, so the
     * next request is let into the same space: empty outside a hosted space.
     *
     * @return array<string, string>
     */
    public function routeParameters(): array
    {
        if (!$this->hosted instanceof HostedNoteSpace) {
            return [];
        }

        return [self::HOST_PARAMETER => $this->hosted->host->getKey().':'.$this->hosted->reference];
    }

    /**
     * Runs a piece of work in another scope, then puts the request's own
     * back: a host building its screen inside a request that is not the
     * engine's, the search palette reading hosted notes for somebody without
     * the module.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function within(NoteSpaceScopeEnum $mode, callable $work, ?HostedNoteSpace $hosted = null): mixed
    {
        if (NoteSpaceScopeEnum::Hosted === $mode && !$hosted instanceof HostedNoteSpace) {
            throw new LogicException('The hosted scope needs the space its host opened.');
        }

        $previousMode = $this->mode;
        $previousHosted = $this->hosted;
        $this->mode = $mode;
        $this->hosted = NoteSpaceScopeEnum::Hosted === $mode ? $hosted : null;

        try {
            return $work();
        } finally {
            $this->mode = $previousMode;
            $this->hosted = $previousHosted;
        }
    }

    public function reset(): void
    {
        $this->mode = NoteSpaceScopeEnum::All;
        $this->hosted = null;
    }
}
