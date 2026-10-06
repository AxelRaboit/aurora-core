<?php

declare(strict_types=1);

namespace Aurora\Core\Frontend\Service;

use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * When the front-end assets were last built.
 *
 * A rendered page is not only its content: it also names the stylesheet and
 * the scripts it needs, and those filenames carry a hash that changes with
 * every build. So a page whose content has not moved can still be stale -
 * the copy a visitor kept points at files the last deploy deleted, and the
 * page comes back with no styling at all until a hard refresh.
 *
 * Reading the Vite manifest's modification time is enough to notice: it is
 * rewritten by every build, it is already on disk, and it costs one `stat`
 * per request at worst. Memoised because the answer cannot change within a
 * request, and a missing manifest is memoised too - a dev environment
 * serving from the Vite server has none, and asking again on every call
 * would be a `stat` per page for nothing.
 */
final class AssetBuildStamp
{
    private const string MANIFEST = '/public/build/.vite/manifest.json';

    private ?DateTimeImmutable $builtAt = null;

    private bool $resolved = false;

    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDirectory,
    ) {}

    /** Null when there is no built manifest, which is the dev-server case. */
    public function builtAt(): ?DateTimeImmutable
    {
        if ($this->resolved) {
            return $this->builtAt;
        }

        $this->resolved = true;

        $manifest = $this->projectDirectory.self::MANIFEST;
        $modifiedAt = is_file($manifest) ? filemtime($manifest) : false;

        if (false !== $modifiedAt) {
            $this->builtAt = new DateTimeImmutable()->setTimestamp($modifiedAt);
        }

        return $this->builtAt;
    }
}
