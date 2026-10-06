<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Duplicate;

use Aurora\Module\Studio\Contract\Dto\ContractTemplateInput;
use Aurora\Module\Studio\Contract\Dto\ContractTemplateVersionInput;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateInterface;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateVersionInterface;
use Aurora\Module\Studio\Contract\Manager\ContractTemplateManagerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function mb_strlen;
use function mb_substr;

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
    /** What the name column holds. */
    private const int NAME_MAX = 180;

    public function __construct(
        private ContractTemplateManagerInterface $templates,
        private TranslatorInterface $translator,
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * In one transaction: the copy and its wording are written by two saves,
     * and a failure between them used to leave an empty template behind.
     */
    public function duplicate(ContractTemplateInterface $source): ContractTemplateInterface
    {
        return $this->entityManager->wrapInTransaction(fn (): ContractTemplateInterface => $this->copy($source));
    }

    private function copy(ContractTemplateInterface $source): ContractTemplateInterface
    {
        $copy = $this->templates->create(new ContractTemplateInput(
            name: $this->copyName($source),
            kind: $source->getKind(),
            // The category travels with the wording, like the kind: a copy made
            // to start from this trame is a document for the same business.
            categoryId: $source->getCategory()?->getId(),
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
        $suffix = ' '.$this->translator->trans('suite.studio.contract_templates.duplicate_suffix');

        // Cut to fit the column with its suffix: a name of 172 characters or
        // more plus « (copie) » passed 180 and came back as a 500.
        return mb_substr($source->getName(), 0, self::NAME_MAX - mb_strlen($suffix)).$suffix;
    }
}
