<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Menu\Dto;

use Aurora\Module\Editorial\Menu\Enum\MenuItemTargetTypeEnum;
use Aurora\Module\Editorial\Menu\Enum\MenuItemVisibilityEnum;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * What the target needs alongside it depends on the target: an id for the
 * three that point at content, a URL for the free-form one, nothing for the
 * rest. The callbacks below are what makes each of those a field error on
 * the right input rather than a 500 in the Manager.
 */
class MenuItemInput implements MenuItemInputInterface
{
    /**
     * @param array<string, array{label: ?string}> $translations
     */
    public function __construct(
        public readonly array $translations,
        public readonly MenuItemTargetTypeEnum $targetType,
        public readonly ?int $targetId = null,
        #[Assert\Url(message: 'backend.menus.errors.custom_url_invalid', requireTld: false)]
        #[Assert\Length(max: 1000)]
        public readonly ?string $customUrl = null,
        public readonly bool $openInNewTab = false,
        #[Assert\Length(max: 255)]
        public readonly ?string $cssClass = null,
        public readonly MenuItemVisibilityEnum $visibility = MenuItemVisibilityEnum::Always,
        public readonly ?int $parentId = null,
    ) {}

    /**
     * A Callback rather than `Assert\IsTrue` on a getter: the getter form
     * names the violation after the method, so the message lands on a field
     * the form does not have and the editor sees nothing. `atPath()` puts it
     * on the input they are actually looking at.
     */
    #[Assert\Callback]
    public function validateTarget(ExecutionContextInterface $context): void
    {
        if ($this->targetType->requiresTargetId() && null === $this->targetId) {
            $context->buildViolation('backend.menus.errors.target_required')
                ->atPath('targetId')
                ->addViolation();
        }

        if ($this->targetType->requiresCustomUrl() && (null === $this->customUrl || '' === $this->customUrl)) {
            $context->buildViolation('backend.menus.errors.custom_url_required')
                ->atPath('customUrl')
                ->addViolation();
        }
    }

    public function getTranslations(): array
    {
        return $this->translations;
    }

    public function getTargetType(): MenuItemTargetTypeEnum
    {
        return $this->targetType;
    }

    public function getTargetId(): ?int
    {
        return $this->targetId;
    }

    public function getCustomUrl(): ?string
    {
        return $this->customUrl;
    }

    public function isOpenInNewTab(): bool
    {
        return $this->openInNewTab;
    }

    public function getCssClass(): ?string
    {
        return $this->cssClass;
    }

    public function getVisibility(): MenuItemVisibilityEnum
    {
        return $this->visibility;
    }

    public function getParentId(): ?int
    {
        return $this->parentId;
    }
}
