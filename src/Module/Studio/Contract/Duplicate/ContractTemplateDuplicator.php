<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Duplicate;

use Aurora\Module\Studio\Contract\Dto\ContractTemplateInput;
use Aurora\Module\Studio\Contract\Dto\ContractTemplateVersionInput;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateInterface;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateVersionInterface;
use Aurora\Module\Studio\Contract\Manager\ContractTemplateManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function sprintf;

/**
 * Copies a trame into a new one whose first version is a draft.
 *
 * Run through the manager rather than field by field, like Editorial's post
 * duplicator and for the same reason: the manager is the one place that knows
 * how a template and its first draft are wired together, and a second
 * implementation would drift the first time somebody adds a field - silently,
 * the copy simply missing something.
 *
 * What is deliberately not carried over is the point of the class:
 *
 * - **The copy is never published.** `create()` opens a draft, and the wording
 *   is written into it. A trame that published itself would be immediately
 *   usable for a contract nobody has read.
 * - **The archive flag stays behind.** Duplicating an archived trame is a way
 *   of reviving its wording, so the copy starts live.
 * - **The version history stays behind.** The copy starts at version 1: the
 *   numbers describe the original's life, not this one's.
 *
 * The wording copied is **the version in force**, falling back to the open
 * draft when nothing is published yet. That is what somebody means by "start
 * from this trame": the text that would be used today.
 */
final readonly class ContractTemplateDuplicator
{
    public function __construct(
        private ContractTemplateManagerInterface $templates,
        private TranslatorInterface $translator,
    ) {}

    public function duplicate(ContractTemplateInterface $source): ContractTemplateInterface
    {
        $copy = $this->templates->create(new ContractTemplateInput(
            name: $this->copyName($source),
            kind: $source->getKind(),
            // The trade travels with the wording, like the kind: a copy made to
            // start from this trame is a document for the same business.
            category: $source->getCategory(),
        ));

        $version = $this->sourceVersion($source);
        $draft = $copy->getDraft();

        if (!$version instanceof ContractTemplateVersionInterface || !$draft instanceof ContractTemplateVersionInterface) {
            return $copy;
        }

        $this->templates->updateDraft($draft, new ContractTemplateVersionInput(
            translations: $this->wording($version),
            // The clause travels with the wording: a copy of a trame written in
            // three languages needs the same answer about which one prevails.
            governingLocale: $version->getGoverningLocale(),
        ));

        return $copy;
    }

    /**
     * The wording to start from: what is in force, or the draft if nothing is.
     */
    private function sourceVersion(ContractTemplateInterface $source): ?ContractTemplateVersionInterface
    {
        return $source->getLatestPublishedVersion() ?? $source->getDraft();
    }

    /**
     * @return array<string, array{title: string, content: array<string, mixed>}>
     */
    private function wording(ContractTemplateVersionInterface $version): array
    {
        $wording = [];

        foreach ($version->getTranslations() as $locale => $translation) {
            $wording[(string) $locale] = [
                'title' => $translation->getTitle(),
                'content' => $translation->getContent(),
            ];
        }

        return $wording;
    }

    /**
     * The name, suffixed so the two are told apart.
     *
     * Not decoration: a list showing two rows with the same name is a list
     * where somebody opens the wrong one and edits wording that is in force.
     */
    private function copyName(ContractTemplateInterface $source): string
    {
        return sprintf(
            '%s %s',
            $source->getName(),
            $this->translator->trans('backend.studio.contract_templates.duplicate_suffix'),
        );
    }
}
