<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Manager;

use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Studio\Contract\Dto\ContractTemplateInputInterface;
use Aurora\Module\Studio\Contract\Dto\ContractTemplateVersionInputInterface;
use Aurora\Module\Studio\Contract\Entity\ContractTemplate;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateInterface;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateVersion;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateVersionInterface;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateVersionTranslation;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateVersionTranslationInterface;
use Aurora\Module\Studio\Contract\Repository\ContractRepository;
use Aurora\Module\Studio\Contract\Repository\ContractTemplateVersionRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_key_exists;

/**
 * Templates and their versions.
 *
 * Three invariants live here, and they are the reason this class is worth
 * reading before the rest of the module:
 *
 * 1. **A published version is never written to.** The entity refuses it, so
 *    this class does not have to be trusted for it - but every method here is
 *    written so the refusal never has to fire.
 * 2. **One draft at a time.** Two open drafts would make "the version being
 *    edited" ambiguous, and the answer would be decided by whichever screen
 *    saved last.
 * 3. **Published wording is edited by opening a new version**, seeded from the
 *    one in force, never by reopening it.
 */
#[AsAlias(ContractTemplateManagerInterface::class)]
class ContractTemplateManager implements ContractTemplateManagerInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly AuditLogger $auditLogger,
        protected readonly ContractTemplateVersionRepository $versionRepository,
        protected readonly TranslatorInterface $translator,
        protected readonly ContractRepository $contractRepository,
    ) {}

    public function create(ContractTemplateInputInterface $input): ContractTemplateInterface
    {
        $template = $this->createTemplate();
        $this->applyInput($template, $input);

        // A template with no version cannot be worked on, and the first thing
        // anybody does after creating one is open its first draft. Created
        // here so that never has to be a second click that can be forgotten.
        $draft = $this->createVersion();
        $draft->setNumber($template->claimNextVersionNumber());

        $template->addVersion($draft);

        $this->entityManager->persist($template);
        $this->entityManager->persist($draft);
        $this->entityManager->flush();

        $this->auditCreated($template);

        return $template;
    }

    public function update(ContractTemplateInterface $template, ContractTemplateInputInterface $input): void
    {
        $this->applyInput($template, $input);
        $this->entityManager->flush();

        $this->auditUpdated($template);
    }

    public function archive(ContractTemplateInterface $template): void
    {
        $template->archive(new DateTimeImmutable());
        $this->entityManager->flush();

        $this->auditLogger->log('studio', 'contract_template.archived', 'ContractTemplate', $template->getId(), $this->auditPayload($template));
    }

    public function restore(ContractTemplateInterface $template): void
    {
        $template->restore();
        $this->entityManager->flush();

        $this->auditLogger->log('studio', 'contract_template.restored', 'ContractTemplate', $template->getId(), $this->auditPayload($template));
    }

    /**
     * Removes the template and every version of it, unless a contract that
     * went out was made from one.
     *
     * Nothing of the wording is lost either way: a contract carries its own
     * frozen copy and reads nothing back from here. What is lost is the trail
     * from a signed contract to the trame it came from, and that trail is part
     * of the record - it is what answers "which version of our terms did they
     * sign". Archiving keeps the trame out of the way and keeps the trail.
     *
     * Drafts do not count. A contract still being written can be rebuilt from
     * another trame, and holding a template hostage to an abandoned draft
     * would make the rule impossible to satisfy.
     */
    public function delete(ContractTemplateInterface $template): void
    {
        $frozen = $this->contractRepository->countFrozenUsingTemplate($template);
        if ($frozen > 0) {
            throw new FieldException('template', $this->translator->trans('backend.studio.contract_templates.errors.used_by_contracts', ['{count}' => (string) $frozen]));
        }

        $this->auditLogger->log('studio', 'contract_template.deleted', 'ContractTemplate', $template->getId(), $this->auditPayload($template));

        $this->entityManager->remove($template);
        $this->entityManager->flush();
    }

    public function openDraft(ContractTemplateInterface $template): ContractTemplateVersionInterface
    {
        // Asked of the database, not of the loaded collection: the rule is
        // about what exists, and a template hydrated without its versions
        // would happily report none.
        $existing = $this->versionRepository->findDraftFor($template);

        if ($existing instanceof ContractTemplateVersionInterface) {
            throw new FieldException('draft', $this->translator->trans('backend.studio.contract_templates.errors.draft_already_open', ['{number}' => (string) $existing->getNumber()]));
        }

        $source = $template->getLatestPublishedVersion();

        $draft = $this->createVersion();
        $draft->setNumber($template->claimNextVersionNumber());

        $template->addVersion($draft);

        // Seeded from the wording in force, so opening a draft to change one
        // clause does not start from a blank document. A first version has
        // nothing to copy and starts empty.
        if ($source instanceof ContractTemplateVersionInterface) {
            // The clause travels with the wording it belongs to. A draft opened
            // to change one article should not silently drop which language
            // prevails, and re-answering it every time is how it ends up
            // answered differently.
            $draft->setGoverningLocale($source->getGoverningLocale());

            foreach ($source->getTranslations() as $translation) {
                $copy = $this->createTranslation();
                $copy
                    ->setLocale($translation->getLocale())
                    ->setTitle($translation->getTitle())
                    ->setContent($translation->getContent());

                $draft->addTranslation($copy);
                $this->entityManager->persist($copy);
            }
        }

        $this->entityManager->persist($draft);
        $this->entityManager->flush();

        $this->auditLogger->log('studio', 'contract_template_version.opened', 'ContractTemplateVersion', $draft->getId(), [
            'template' => $template->getName(),
            'number' => $draft->getNumber(),
            'seededFrom' => $source?->getNumber(),
        ]);

        return $draft;
    }

    public function updateDraft(ContractTemplateVersionInterface $version, ContractTemplateVersionInputInterface $input): void
    {
        // Checked before anything is touched, so a refused write leaves the
        // draft exactly as it was rather than half applied.
        $version->assertEditable();

        $incoming = $input->getTranslations();
        $governing = $input->getGoverningLocale();

        // Refused before a single wording is written, so a draft turned down
        // for this is left exactly as it was. A language that prevails over
        // the others has to be one of the others: pointing the clause at a
        // Spanish version this draft does not contain would print a promise
        // about a document nobody can read.
        if (null !== $governing && !array_key_exists($governing, $incoming)) {
            throw new FieldException('governingLocale', $this->translator->trans('backend.studio.contract_templates.errors.governing_locale_not_written', ['{locale}' => $governing]));
        }

        $version->setGoverningLocale($governing);

        foreach ($incoming as $locale => $wording) {
            $existing = $version->getTranslation($locale);

            if ($existing instanceof ContractTemplateVersionTranslationInterface) {
                $version->updateTranslation($locale, $wording['title'], $wording['content']);

                continue;
            }

            $translation = $this->createTranslation();
            $translation
                ->setLocale($locale)
                ->setTitle($wording['title'])
                ->setContent($wording['content']);

            $version->addTranslation($translation);
            $this->entityManager->persist($translation);
        }

        // A locale the editor stopped sending is a locale it dropped. Removed
        // rather than left behind: a stale Spanish document nobody is looking
        // at any more would still be published with the version.
        //
        // The locales are listed before any of them is removed. Removing from
        // a collection while iterating it is how the second of two dropped
        // languages survives.
        $dropped = array_diff(
            array_keys($version->getTranslations()->toArray()),
            array_keys($incoming),
        );

        foreach ($dropped as $locale) {
            $version->removeTranslation((string) $locale);
        }

        $this->entityManager->flush();

        $this->auditLogger->log('studio', 'contract_template_version.updated', 'ContractTemplateVersion', $version->getId(), [
            'template' => $version->getTemplate()->getName(),
            'number' => $version->getNumber(),
            'locales' => array_keys($incoming),
            'governingLocale' => $governing,
        ]);
    }

    public function publish(ContractTemplateVersionInterface $version): void
    {
        $version->assertEditable();

        if (0 === $version->getTranslations()->count()) {
            throw new FieldException('translations', $this->translator->trans('backend.studio.contract_templates.errors.nothing_to_publish'));
        }

        // A multilingual version has to say which language prevails, and
        // publication is the last moment to ask: from here the wording is
        // immutable, and a contract sealed against it would carry two
        // documents with equal authority and no way to settle a divergence.
        // One language has nothing to arbitrate, so null stays legitimate.
        if ($version->getTranslations()->count() > 1 && null === $version->getGoverningLocale()) {
            throw new FieldException('governingLocale', $this->translator->trans('backend.studio.contract_templates.errors.governing_locale_required'));
        }

        $version->publish(new DateTimeImmutable());
        $this->entityManager->flush();

        $this->auditLogger->log('studio', 'contract_template_version.published', 'ContractTemplateVersion', $version->getId(), [
            'template' => $version->getTemplate()->getName(),
            'number' => $version->getNumber(),
            'locales' => array_keys($version->getTranslations()->toArray()),
            'governingLocale' => $version->getGoverningLocale(),
        ]);
    }

    public function discardDraft(ContractTemplateVersionInterface $version): void
    {
        // Guarded even though the caller should know: `discard` on a published
        // version would be a request to delete wording contracts were signed
        // against, and the entity is the one place that cannot be talked out
        // of refusing it.
        $version->assertEditable();

        $this->auditLogger->log('studio', 'contract_template_version.discarded', 'ContractTemplateVersion', $version->getId(), [
            'template' => $version->getTemplate()->getName(),
            'number' => $version->getNumber(),
        ]);

        $version->getTemplate()->removeVersion($version);
        $this->entityManager->remove($version);
        $this->entityManager->flush();
    }

    /**
     * Instantiates the concrete template. Override in a subclass to return a
     * client-substituted class - `resolve_target_entities` only affects
     * Doctrine relation resolution, not direct `new` calls.
     */
    protected function createTemplate(): ContractTemplateInterface
    {
        return new ContractTemplate();
    }

    protected function createVersion(): ContractTemplateVersionInterface
    {
        return new ContractTemplateVersion();
    }

    protected function createTranslation(): ContractTemplateVersionTranslationInterface
    {
        return new ContractTemplateVersionTranslation();
    }

    /**
     * Hydrates the template from the input DTO. Override in a subclass and
     * call `parent::applyInput()` FIRST so the base fields stay populated,
     * then read your own extra fields off the input.
     */
    protected function applyInput(ContractTemplateInterface $template, ContractTemplateInputInterface $input): void
    {
        $template
            ->setName($input->getName())
            ->setKind($input->getKind());
    }

    protected function auditCreated(ContractTemplateInterface $template): void
    {
        $this->auditLogger->log('studio', 'contract_template.created', 'ContractTemplate', $template->getId(), $this->auditPayload($template));
    }

    protected function auditUpdated(ContractTemplateInterface $template): void
    {
        $this->auditLogger->log('studio', 'contract_template.updated', 'ContractTemplate', $template->getId(), $this->auditPayload($template));
    }

    /**
     * Structured payload logged with every audit entry. Override to add
     * extra fields: `[...parent::auditPayload($template), 'code' => $template->getCode()]`.
     *
     * @return array<string, mixed>
     */
    protected function auditPayload(ContractTemplateInterface $template): array
    {
        return [
            'name' => $template->getName(),
            'kind' => $template->getKind()->value,
        ];
    }
}
