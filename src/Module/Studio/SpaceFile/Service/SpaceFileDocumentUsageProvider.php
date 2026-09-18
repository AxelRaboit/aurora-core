<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceFile\Service;

use Aurora\Module\Ged\Document\Contract\DocumentUsageProviderInterface;
use Aurora\Module\Studio\SpaceContent\Service\SpaceAttachmentDocumentUsageProvider;
use Aurora\Module\Studio\SpaceFile\Repository\SpaceFileRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Dit quels espaces portent un fichier, avant qu'on ne le supprime.
 *
 * Le même silence coûteux que {@see SpaceAttachmentDocumentUsageProvider}
 * évitait pour les fiches, et pour la même raison : la ligne cascade, donc
 * supprimer le document ne noircit pas une vignette, il retire le fichier de
 * l'espace sans rien laisser derrière.
 */
final readonly class SpaceFileDocumentUsageProvider implements DocumentUsageProviderInterface
{
    public function __construct(
        private SpaceFileRepository $files,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
    ) {}

    /** @return list<array{type: string, label: string, detail?: ?string, href?: ?string}> */
    public function findUsages(int $documentId): array
    {
        $usages = [];

        foreach ($this->files->findUsingDocument($documentId) as $file) {
            $space = $file->getSpace();

            $usages[] = [
                'type' => 'studio.space_file',
                // Le nom de l'espace, pas celui du document : l'écran de
                // suppression dit déjà quel fichier part, et ce qu'il faut
                // savoir avant de valider, c'est chez qui il sert.
                'label' => $space->getName(),
                'detail' => $this->translator->trans('backend.studio.space_files.usage_detail'),
                'href' => $this->urlGenerator->generate(
                    'workspace_space_content',
                    ['id' => $space->getId()],
                ),
            ];
        }

        return $usages;
    }
}
