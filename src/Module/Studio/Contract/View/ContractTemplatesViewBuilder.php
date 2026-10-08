<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\View;

use Aurora\Core\Locale\Service\LocaleOptionsProviderInterface;
use Aurora\Core\Routing\PathTemplateGenerator;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateCategoryInterface;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateInterface;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateVersionInterface;
use Aurora\Module\Studio\Contract\Enum\ContractTemplateKindEnum;
use Aurora\Module\Studio\Contract\Repository\ContractRepository;
use Aurora\Module\Studio\Contract\Repository\ContractTemplateCategoryRepository;
use Aurora\Module\Studio\Contract\Repository\ContractTemplateRepository;
use Aurora\Module\Studio\Contract\Serializer\ContractTemplateSerializerInterface;
use Aurora\Module\Studio\Contract\Service\ContractVariableCatalogue;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use const DATE_ATOM;

final readonly class ContractTemplatesViewBuilder
{
    public function __construct(
        private ContractTemplateRepository $templateRepository,
        private ContractTemplateSerializerInterface $serializer,
        private ContractVariableCatalogue $variables,
        private LocaleOptionsProviderInterface $localeOptions,
        private PathTemplateGenerator $pathTemplateGenerator,
        private UrlGeneratorInterface $urlGenerator,
        private ContractRepository $contractRepository,
        private ContractTemplateCategoryRepository $categoryRepository,
    ) {}

    /** @return array<string, mixed> */
    public function indexView(): array
    {
        return [
            'templates' => $this->templates(),
            'kinds' => $this->kinds(),
            'categories' => $this->categories(),
            'contractsPath' => $this->urlGenerator->generate('suite_studio_contracts'),
            'templatesPath' => $this->urlGenerator->generate('suite_studio_contract_templates'),
            // The categories are managed from this screen, in the window the
            // deliverables use for theirs.
            'categoryCreatePath' => $this->urlGenerator->generate('suite_studio_contract_templates_category_create'),
            'categoryUpdatePath' => $this->pathTemplateGenerator->generate('suite_studio_contract_templates_category_update', ['id' => '__id__']),
            'categoryDeletePath' => $this->pathTemplateGenerator->generate('suite_studio_contract_templates_category_delete', ['id' => '__id__']),
            'categoryReorderPath' => $this->urlGenerator->generate('suite_studio_contract_templates_category_reorder'),
            'createPath' => $this->urlGenerator->generate('suite_studio_contract_templates_create'),
            'updatePath' => $this->pathTemplateGenerator->generate('suite_studio_contract_templates_update', ['id' => '__id__']),
            'archivePath' => $this->pathTemplateGenerator->generate('suite_studio_contract_templates_archive', ['id' => '__id__']),
            'restorePath' => $this->pathTemplateGenerator->generate('suite_studio_contract_templates_restore', ['id' => '__id__']),
            'deletePath' => $this->pathTemplateGenerator->generate('suite_studio_contract_templates_delete', ['id' => '__id__']),
            'openDraftPath' => $this->pathTemplateGenerator->generate('suite_studio_contract_templates_open_draft', ['id' => '__id__']),
            'duplicatePath' => $this->pathTemplateGenerator->generate('suite_studio_contract_templates_duplicate', ['id' => '__id__']),
            // Abandoning a draft is offered from the list too, not only from
            // inside the editor: somebody who opened one by mistake should not
            // have to walk into it to walk back out.
            'discardDraftPath' => $this->pathTemplateGenerator->generate('suite_studio_contract_templates_discard', ['id' => '__id__', 'versionId' => '__versionId__']),
            'editorPath' => $this->pathTemplateGenerator->generate('suite_studio_contract_templates_editor', ['id' => '__id__', 'versionId' => '__versionId__']),
        ];
    }

    /** @return array<string, mixed> */
    public function editorView(ContractTemplateInterface $template, ContractTemplateVersionInterface $version): array
    {
        return [
            'template' => $this->serializer->serialize($template),
            'version' => $this->serializer->serializeVersion($version),
            // Every version of the template, so the editor can show what came
            // before without a second request - and so a reader of a published
            // one can see it is not the only one.
            'versions' => array_map(
                fn (ContractTemplateVersionInterface $each): array => [
                    'id' => $each->getId(),
                    'number' => $each->getNumber(),
                    'isPublished' => $each->isPublished(),
                    // When, so the history reads as one: « version 2, publiée
                    // le … » rather than a list of numbers.
                    'publishedAt' => $each->getPublishedAt()?->format(DATE_ATOM),
                ],
                $template->getVersions()->toArray(),
            ),
            // The version in force, so the editor can name the three states -
            // draft, in force, replaced - instead of calling every published
            // version « Publiée ».
            'inForceVersionId' => $template->getLatestPublishedVersion()?->getId(),
            // The locales the application actually offers, never a hardcoded
            // fr/en pair: a document has to be writable in every language the
            // deployment turned on.
            'locales' => $this->localeOptions->getActiveOptions(),
            'variableGroups' => $this->variables->groups(),
            'savePath' => $this->urlGenerator->generate('suite_studio_contract_templates_save_draft', [
                'id' => $template->getId(),
                'versionId' => $version->getId(),
            ]),
            'publishPath' => $this->urlGenerator->generate('suite_studio_contract_templates_publish', [
                'id' => $template->getId(),
                'versionId' => $version->getId(),
            ]),
            'discardPath' => $this->urlGenerator->generate('suite_studio_contract_templates_discard', [
                'id' => $template->getId(),
                'versionId' => $version->getId(),
            ]),
            'previewPath' => $this->urlGenerator->generate('suite_studio_contract_templates_preview', [
                'id' => $template->getId(),
                'versionId' => $version->getId(),
            ]),
            'indexPath' => $this->urlGenerator->generate('suite_studio_contract_templates'),
            // « Modifier le texte » from the version in force opens the next
            // draft without a trip back to the list.
            'openDraftPath' => $this->urlGenerator->generate('suite_studio_contract_templates_open_draft', ['id' => $template->getId()]),
            'editorPath' => $this->urlGenerator->generate('suite_studio_contract_templates_editor', [
                'id' => $template->getId(),
                'versionId' => '__versionId__',
            ]),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function templates(): array
    {
        // How many contracts start from each: the list says it, and does not
        // offer to delete what the server would refuse.
        $counts = $this->contractRepository->countByTemplate();

        return array_map(
            fn (ContractTemplateInterface $template): array => [
                ...$this->serializer->serialize($template),
                'contractsCount' => $counts[(int) $template->getId()] ?? 0,
            ],
            $this->templateRepository->findAllForIndex(),
        );
    }

    /** @return array<string, mixed> */
    public function listPayload(): array
    {
        return ['success' => true, 'templates' => $this->templates()];
    }

    /** @return array{categories: list<array{id: int, value: string, name: string, label: string, color: ?string, position: int}>} */
    public function categoriesPayload(): array
    {
        return ['categories' => $this->categories()];
    }

    /**
     * The categories as both the list and the window read them: `value` and
     * `label` for the pickers, `id`, `name` and `color` for the management
     * window. The value is a string, like the filter in the address.
     *
     * @return list<array{id: int, value: string, name: string, label: string, color: ?string, position: int}>
     */
    private function categories(): array
    {
        return array_map(
            static fn (ContractTemplateCategoryInterface $category): array => [
                'id' => (int) $category->getId(),
                'value' => (string) $category->getId(),
                'name' => $category->getName(),
                'label' => $category->getName(),
                'color' => $category->getColor(),
                'position' => $category->getPosition(),
            ],
            $this->categoryRepository->findOrdered(),
        );
    }

    /** @return list<array{value: string, labelKey: string}> */
    private function kinds(): array
    {
        return array_map(
            static fn (ContractTemplateKindEnum $kind): array => [
                'value' => $kind->value,
                'labelKey' => $kind->getLabel(),
            ],
            ContractTemplateKindEnum::cases(),
        );
    }
}
