<?php

declare(strict_types=1);

namespace Aurora\Core\Frontend\Service;

use Aurora\Core\Frontend\Contract\FrontendInterface;

final readonly class Registry
{
    /** @var list<FrontendInterface> */
    private array $fronts;

    /** @param iterable<FrontendInterface> $fronts */
    public function __construct(iterable $fronts)
    {
        $sorted = iterator_to_array($fronts, false);
        usort($sorted, static fn (FrontendInterface $left, FrontendInterface $right): int => $right->getPriority() <=> $left->getPriority());
        $this->fronts = $sorted;
    }

    /** @return list<FrontendInterface> */
    public function all(): array
    {
        return $this->fronts;
    }

    public function find(string $slug): ?FrontendInterface
    {
        foreach ($this->fronts as $front) {
            if ($front->getSlug() === $slug) {
                return $front;
            }
        }

        return null;
    }

    public function highest(): ?FrontendInterface
    {
        return $this->fronts[0] ?? null;
    }
}
