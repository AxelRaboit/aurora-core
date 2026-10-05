<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Dto;

use Aurora\Core\Locale\Enum\LocaleEnum;
use Symfony\Component\Validator\Constraints as Assert;

class ContractTemplateVersionInput implements ContractTemplateVersionInputInterface
{
    /**
     * @param array<string, array{title: string, content: array<string, mixed>}> $translations
     */
    public function __construct(
        // At least one language, because a version with no wording is not a
        // draft of anything. Which languages are expected is the locale
        // layer's business, not this DTO's.
        #[Assert\Count(min: 1, minMessage: 'suite.studio.contract_templates.errors.translations_required')]
        public readonly array $translations = [],
        // Checked against the application's languages here, and against this
        // version's own translations in the manager: a language nobody wrote
        // cannot be the one that prevails, and only the manager knows which
        // ones the draft is about to keep.
        #[Assert\Choice(callback: [LocaleEnum::class, 'values'], message: 'suite.studio.contract_templates.errors.governing_locale_unknown')]
        public readonly ?string $governingLocale = null,
    ) {}

    public function getTranslations(): array
    {
        return $this->translations;
    }

    public function getGoverningLocale(): ?string
    {
        return $this->governingLocale;
    }
}
