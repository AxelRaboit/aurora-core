<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Link\Serializer;

use Aurora\Module\Planning\Link\Entity\PlanningShareLinkInterface;
use Aurora\Module\Planning\Planning\Entity\PlanningInterface;
use DateTimeImmutable;
use DateTimeInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A share link, as the screen that manages it draws it.
 *
 * **The token travels only in `url`, and only when the link still works.** A
 * revoked or expired address is shown so somebody can see what they closed and
 * when; handing its secret back with it would put a dead credential into a payload
 * that sits in a browser's memory and a proxy's logs for no purpose at all.
 */
final readonly class PlanningShareLinkSerializer
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
    ) {}

    /** @return array<string, mixed> */
    public function serialize(PlanningShareLinkInterface $link, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $usable = $link->isUsableAt($now);

        return [
            'id' => $link->getId(),
            'label' => $link->getLabel(),
            'mode' => $link->getMode()->value,
            'modeLabel' => $this->translator->trans($link->getMode()->getLabelKey()),
            'calendars' => array_map(
                static fn (PlanningInterface $planning): array => [
                    'id' => $planning->getId(),
                    'name' => $planning->getName(),
                    'colourSlot' => $planning->getColourSlot(),
                ],
                $link->getCalendars()->toArray(),
            ),
            'expiresAt' => $link->getExpiresAt()?->format(DateTimeInterface::ATOM),
            'revokedAt' => $link->getRevokedAt()?->format(DateTimeInterface::ATOM),
            'lastUsedAt' => $link->getLastUsedAt()?->format(DateTimeInterface::ATOM),
            'createdAt' => $link->getCreatedAt()->format(DateTimeInterface::ATOM),
            // One flag rather than leaving the screen to compare the two dates to
            // the clock. Expired and revoked are different facts with the same
            // consequence, and a client computing it would drift from the server
            // that enforces it.
            'usable' => $usable,
            'url' => $usable ? $this->url($link) : null,
        ];
    }

    /**
     * @param iterable<PlanningShareLinkInterface> $links
     *
     * @return list<array<string, mixed>>
     */
    public function serializeMany(iterable $links, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $rows = [];

        foreach ($links as $link) {
            $rows[] = $this->serialize($link, $now);
        }

        return $rows;
    }

    /**
     * Absolute, because it is meant to be pasted into a message or a phone.
     *
     * The two modes are two routes: an `.ics` token answers a file and a web token
     * answers a page, and the same secret must not serve both.
     */
    private function url(PlanningShareLinkInterface $link): string
    {
        return $this->urlGenerator->generate(
            $link->getMode()->routeName(),
            ['token' => $link->getToken()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }
}
