<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\View;

use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Markdown\Enum\NotePropertyTypeEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;

/**
 * How a note shows itself where it is read rather than written (09/10/2026):
 * the reader in the suite, a share page, a published space.
 *
 * One description for the three, so they cannot drift: the emoji, the
 * properties - a person's id becomes their name here, since a guest has no
 * way of asking for it -, and the reading settings.
 */
final readonly class MarkdownNoteDisplay
{
    public function __construct(private UserRepository $userRepository) {}

    /**
     * @return array{icon: string|null, properties: list<array{key: string, type: string, value: bool|float|int|string|null, label: string|null}>, fullWidth: bool, smallText: bool, font: string}
     */
    public function describe(MarkdownNoteInterface $note): array
    {
        $personIds = [];
        foreach ($note->getProperties() as $property) {
            if (NotePropertyTypeEnum::Person->value === $property['type'] && is_int($property['value'])) {
                $personIds[] = $property['value'];
            }
        }

        $names = [];
        if ([] !== $personIds) {
            foreach ($this->userRepository->findBy(['id' => array_values(array_unique($personIds))]) as $person) {
                $names[(int) $person->getId()] = $person->getName();
            }
        }

        return [
            'icon' => $note->getIcon(),
            'properties' => array_map(
                static fn (array $property): array => [
                    ...$property,
                    'label' => NotePropertyTypeEnum::Person->value === $property['type'] && is_int($property['value'])
                        ? ($names[$property['value']] ?? null)
                        : null,
                ],
                $note->getProperties(),
            ),
            'fullWidth' => $note->isFullWidth(),
            'smallText' => $note->isSmallText(),
            'font' => $note->getFont()->value,
        ];
    }
}
