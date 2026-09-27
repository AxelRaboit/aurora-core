<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\Twig;

use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Security\SpaceVisibility;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

use function array_filter;
use function array_map;
use function array_values;

/**
 * The other spaces, for the switcher in a space's header.
 *
 * Going from one client to the next meant going back to the list every time.
 * The header now lists the spaces the reader may open - the same rule as the
 * list, archived ones left out - on the same tab they are on.
 */
final class SpaceSwitchExtension extends AbstractExtension
{
    /** Tab of a space → the route that opens it. */
    private const array TAB_ROUTES = [
        'content' => 'workspace_space_content',
        'access' => 'workspace_space_access',
    ];

    public function __construct(
        private readonly SpaceVisibility $visibility,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public function getFunctions(): array
    {
        return [new TwigFunction('studio_other_spaces', $this->otherSpaces(...))];
    }

    /** @return list<array{name: string, customerName: string, colourSlot: int|null, url: string}> */
    public function otherSpaces(int $currentId, string $tab = 'content'): array
    {
        $route = self::TAB_ROUTES[$tab] ?? self::TAB_ROUTES['content'];

        return array_values(array_map(
            fn (CustomerSpaceInterface $space): array => [
                'name' => $space->getName(),
                'customerName' => $space->getCustomer()->getLegalName(),
                'colourSlot' => $space->getColourSlot(),
                'url' => $this->urlGenerator->generate($route, ['id' => $space->getId()]),
            ],
            array_filter(
                $this->visibility->visibleSpaces(),
                static fn (CustomerSpaceInterface $space): bool => !$space->isArchived() && $space->getId() !== $currentId,
            ),
        ));
    }
}
