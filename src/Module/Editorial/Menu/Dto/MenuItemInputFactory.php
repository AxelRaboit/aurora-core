<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Menu\Dto;

use Aurora\Core\Support\Str;
use Aurora\Module\Editorial\Menu\Enum\MenuItemTargetTypeEnum;
use Aurora\Module\Editorial\Menu\Enum\MenuItemVisibilityEnum;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsAlias(MenuItemInputFactoryInterface::class)]
class MenuItemInputFactory implements MenuItemInputFactoryInterface
{
    public function __construct(protected readonly TranslatorInterface $translator) {}

    /** @param array<string, mixed> $data */
    public function fromArray(array $data): MenuItemInputInterface
    {
        $targetId = (int) ($data['targetId'] ?? 0);
        $parentId = (int) ($data['parentId'] ?? 0);

        return new MenuItemInput(
            translations: $this->translations($data['translations'] ?? null),
            targetType: $this->targetType($data['targetType'] ?? null),
            targetId: $targetId > 0 ? $targetId : null,
            customUrl: Str::trimOrNull((string) ($data['customUrl'] ?? '')),
            openInNewTab: (bool) ($data['openInNewTab'] ?? false),
            cssClass: Str::trimOrNull((string) ($data['cssClass'] ?? '')),
            visibility: $this->visibility($data['visibility'] ?? null),
            parentId: $parentId > 0 ? $parentId : null,
        );
    }

    /**
     * The label stays nullable all the way down: blank is a meaningful
     * choice here, telling the renderer to follow the target's own title.
     *
     * @return array<string, array{label: ?string}>
     */
    private function translations(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $translations = [];
        foreach ($raw as $locale => $payload) {
            if (!is_array($payload)) {
                continue;
            }

            $translations[(string) $locale] = ['label' => Str::trimOrNull((string) ($payload['label'] ?? ''))];
        }

        return $translations;
    }

    /**
     * An unknown target type is rejected here rather than defaulted: guessing
     * would silently turn a typo into a "custom URL" entry pointing nowhere.
     */
    private function targetType(mixed $raw): MenuItemTargetTypeEnum
    {
        if (!is_string($raw) || '' === $raw) {
            throw new InvalidArgumentException($this->translator->trans('backend.menus.errors.target_type_required'));
        }

        return MenuItemTargetTypeEnum::tryFrom($raw)
            ?? throw new InvalidArgumentException($this->translator->trans('backend.menus.errors.target_type_invalid'));
    }

    private function visibility(mixed $raw): MenuItemVisibilityEnum
    {
        if (!is_string($raw) || '' === $raw) {
            return MenuItemVisibilityEnum::Always;
        }

        return MenuItemVisibilityEnum::tryFrom($raw)
            ?? throw new InvalidArgumentException($this->translator->trans('backend.menus.errors.visibility_invalid'));
    }
}
