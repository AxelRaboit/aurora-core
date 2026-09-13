<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\View;

use Aurora\Core\Locale\Service\LocaleOptionsProviderInterface;
use Aurora\Core\Routing\PathTemplateGenerator;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateInterface;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateVersionInterface;
use Aurora\Module\Studio\Contract\Enum\ContractTemplateCategoryEnum;
use Aurora\Module\Studio\Contract\Enum\ContractTemplateKindEnum;
use Aurora\Module\Studio\Contract\Repository\ContractTemplateRepository;
use Aurora\Module\Studio\Contract\Serializer\ContractTemplateSerializerInterface;
use Aurora\Module\Studio\Contract\Service\ContractVariableCatalogue;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class ContractTemplatesViewBuilder
{
    public function __construct(
        private ContractTemplateRepository $templateRepository,
        private ContractTemplateSerializerInterface $serializer,
        private ContractVariableCatalogue $variables,
        private LocaleOptionsProviderInterface $localeOptions,
        private PathTemplateGenerator $pathTemplates,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    /** @return array<string, mixed> */
    public function indexView(): array
    {
        return [
            'templates' => $this->templates(),
            'kinds' => $this->kinds(),
            'categories' => $this->categories(),
            'createPath' => $this->urlGenerator->generate('backend_studio_contract_templates_create'),
            'updatePath' => $this->pathTemplates->generate('backend_studio_contract_templates_update', ['id' => '__id__']),
            'archivePath' => $this->pathTemplates->generate('backend_studio_contract_templates_archive', ['id' => '__id__']),
            'restorePath' => $this->pathTemplates->generate('backend_studio_contract_templates_restore', ['id' => '__id__']),
            'deletePath' => $this->pathTemplates->generate('backend_studio_contract_templates_delete', ['id' => '__id__']),
            'openDraftPath' => $this->pathTemplates->generate('backend_studio_contract_templates_open_draft', ['id' => '__id__']),
            'duplicatePath' => $this->pathTemplates->generate('backend_studio_contract_templates_duplicate', ['id' => '__id__']),
            // Abandoning a draft is offered from the list too, not only from
            // inside the editor: somebody who opened one by mistake should not
            // have to walk into it to walk back out.
            'discardDraftPath' => $this->pathTemplates->generate('backend_studio_contract_templates_discard', ['id' => '__id__', 'versionId' => '__versionId__']),
            'editorPath' => $this->pathTemplates->generate('backend_studio_contract_templates_editor', ['id' => '__id__', 'versionId' => '__versionId__']),
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
                ],
                $template->getVersions()->toArray(),
            ),
            // The locales the application actually offers, never a hardcoded
            // fr/en pair: a document has to be writable in every language the
            // deployment turned on.
            'locales' => $this->localeOptions->getActiveOptions(),
            'variableGroups' => $this->variables->groups(),
            'savePath' => $this->urlGenerator->generate('backend_studio_contract_templates_save_draft', [
                'id' => $template->getId(),
                'versionId' => $version->getId(),
            ]),
            'publishPath' => $this->urlGenerator->generate('backend_studio_contract_templates_publish', [
                'id' => $template->getId(),
                'versionId' => $version->getId(),
            ]),
            'discardPath' => $this->urlGenerator->generate('backend_studio_contract_templates_discard', [
                'id' => $template->getId(),
                'versionId' => $version->getId(),
            ]),
            'previewPath' => $this->urlGenerator->generate('backend_studio_contract_templates_preview', [
                'id' => $template->getId(),
                'versionId' => $version->getId(),
            ]),
            'indexPath' => $this->urlGenerator->generate('backend_studio_contract_templates'),
            'editorPath' => $this->urlGenerator->generate('backend_studio_contract_templates_editor', [
                'id' => $template->getId(),
                'versionId' => '__versionId__',
            ]),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function templates(): array
    {
        return array_map(
            $this->serializer->serialize(...),
            $this->templateRepository->findAllForIndex(),
        );
    }

    /** @return array<string, mixed> */
    public function listPayload(): array
    {
        return ['success' => true, 'templates' => $this->templates()];
    }

    /** @return list<array{value: string, labelKey: string}> */
    private function categories(): array
    {
        return array_map(
            static fn (ContractTemplateCategoryEnum $category): array => [
                'value' => $category->value,
                'labelKey' => $category->getLabel(),
            ],
            ContractTemplateCategoryEnum::cases(),
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
