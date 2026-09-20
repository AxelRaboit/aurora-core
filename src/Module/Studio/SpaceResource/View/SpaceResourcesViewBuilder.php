<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceResource\View;

use Aurora\Core\Routing\PathTemplateGenerator;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceResource\Repository\SpaceResourceRepository;
use Aurora\Module\Studio\SpaceResource\Serializer\SpaceResourceSerializerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class SpaceResourcesViewBuilder
{
    public function __construct(
        private SpaceResourceRepository $resources,
        private SpaceResourceSerializerInterface $serializer,
        private UrlGeneratorInterface $urlGenerator,
        private PathTemplateGenerator $pathTemplates,
    ) {}

    /** @return array<string, mixed> */
    public function view(CustomerSpaceInterface $space): array
    {
        return [
            'resources' => $this->resources($space),
            'resourceCreatePath' => $this->urlGenerator->generate('workspace_space_resources_create', ['id' => $space->getId()]),
            'resourceUpdatePath' => $this->pathTemplates->generate('workspace_space_resources_update', ['id' => $space->getId(), 'resourceId' => '__id__']),
            'resourceVisibilityPath' => $this->pathTemplates->generate('workspace_space_resources_visibility', ['id' => $space->getId(), 'resourceId' => '__id__']),
            'resourceDeletePath' => $this->pathTemplates->generate('workspace_space_resources_delete', ['id' => $space->getId(), 'resourceId' => '__id__']),
            'resourceReorderPath' => $this->urlGenerator->generate('workspace_space_resources_reorder', ['id' => $space->getId()]),
        ];
    }

    /**
     * Ce que renvoie chaque écriture : la liste entière.
     *
     * La même raison qu'ailleurs : ajouter pose en fin de liste, ranger
     * renumérote tout, et une page qui rafistolerait sa copie s'écarterait du
     * serveur en trois gestes. Une liste de ressources est courte.
     *
     * @return array<string, mixed>
     */
    public function payload(CustomerSpaceInterface $space): array
    {
        return ['success' => true, 'resources' => $this->resources($space)];
    }

    /** @return list<array<string, mixed>> */
    public function resources(CustomerSpaceInterface $space): array
    {
        return array_map(
            $this->serializer->serialize(...),
            $this->resources->findForSpace($space),
        );
    }
}
