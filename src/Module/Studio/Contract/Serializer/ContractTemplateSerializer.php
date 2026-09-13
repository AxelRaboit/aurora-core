<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Serializer;

use Aurora\Module\Studio\Contract\Entity\ContractTemplateInterface;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateVersionInterface;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateVersionTranslationInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

use const DATE_ATOM;

#[AsAlias(ContractTemplateSerializerInterface::class)]
class ContractTemplateSerializer implements ContractTemplateSerializerInterface
{
    /** @return array<string, mixed> */
    public function serialize(ContractTemplateInterface $template): array
    {
        $published = $template->getLatestPublishedVersion();
        $draft = $template->getDraft();

        return [
            'id' => $template->getId(),
            'name' => $template->getName(),
            'kind' => $template->getKind()->value,
            'archivedAt' => $template->getArchivedAt()?->format(DATE_ATOM),
            'isArchived' => $template->isArchived(),
            // The two numbers a reader of the list actually needs: what a
            // contract would be built from today, and whether something is
            // being written. Either can be absent, and the absences mean
            // different things - never published, versus nothing open.
            'publishedVersion' => $published?->getNumber(),
            // Its id as well as its number: the number is what a reader goes
            // by, the id is what a link to it needs. Without it the version in
            // force was the one thing on this screen with no way in.
            'publishedVersionId' => $published?->getId(),
            'publishedAt' => $published?->getPublishedAt()?->format(DATE_ATOM),
            'draftVersion' => $draft?->getNumber(),
            'draftId' => $draft?->getId(),
            'locales' => $this->locales($published ?? $draft),
            'createdAt' => $template->getCreatedAt()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function serializeVersion(ContractTemplateVersionInterface $version): array
    {
        $translations = [];

        foreach ($version->getTranslations() as $locale => $translation) {
            $translations[(string) $locale] = [
                'title' => $translation->getTitle(),
                'content' => $translation->getContent(),
            ];
        }

        return [
            'id' => $version->getId(),
            'templateId' => $version->getTemplate()->getId(),
            'number' => $version->getNumber(),
            'isPublished' => $version->isPublished(),
            'publishedAt' => $version->getPublishedAt()?->format(DATE_ATOM),
            'governingLocale' => $version->getGoverningLocale(),
            'translations' => $translations,
        ];
    }

    /** @return list<string> */
    private function locales(?ContractTemplateVersionInterface $version): array
    {
        if (!$version instanceof ContractTemplateVersionInterface) {
            return [];
        }

        return array_values(array_map(
            static fn (ContractTemplateVersionTranslationInterface $translation): string => $translation->getLocale(),
            $version->getTranslations()->toArray(),
        ));
    }
}
