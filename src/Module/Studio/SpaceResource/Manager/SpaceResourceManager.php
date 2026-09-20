<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceResource\Manager;

use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceResource\Dto\SpaceResourceInputInterface;
use Aurora\Module\Studio\SpaceResource\Entity\SpaceResource;
use Aurora\Module\Studio\SpaceResource\Entity\SpaceResourceInterface;
use Aurora\Module\Studio\SpaceResource\Repository\SpaceResourceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Épingler, ranger et retirer les ressources d'un espace.
 *
 * **Chaque changement de visibilité est journalisé.** C'est le geste qui
 * publie : il décide qu'une ligne rangée dans un espace devient une ligne que
 * le client lit. Savoir après coup quand une ressource s'est ouverte, et qui
 * l'a ouverte, est ce qui fait la différence entre une erreur qu'on corrige et
 * une erreur qu'on découvre.
 */
#[AsAlias(SpaceResourceManagerInterface::class)]
class SpaceResourceManager implements SpaceResourceManagerInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly SpaceResourceRepository $resources,
        protected readonly AuditLogger $auditLogger,
    ) {}

    public function create(CustomerSpaceInterface $space, SpaceResourceInputInterface $input): SpaceResourceInterface
    {
        $resource = $this->createResource();
        $resource->setSpace($space)->setPosition($this->resources->nextPosition($space));

        $this->applyInput($resource, $input);

        $this->entityManager->persist($resource);
        $this->entityManager->flush();

        $this->auditLogger->log('studio', 'space_resource.created', 'SpaceResource', $resource->getId(), $this->auditPayload($resource));

        return $resource;
    }

    public function update(SpaceResourceInterface $resource, SpaceResourceInputInterface $input): void
    {
        $this->applyInput($resource, $input);
        $this->entityManager->flush();

        $this->auditLogger->log('studio', 'space_resource.updated', 'SpaceResource', $resource->getId(), $this->auditPayload($resource));
    }

    public function toggleVisibility(SpaceResourceInterface $resource): void
    {
        $resource->setVisibleToClient(!$resource->isVisibleToClient());
        $this->entityManager->flush();

        // Deux branches et deux littéraux plutôt qu'un ternaire dans
        // l'appel : le contrôle de dérive du journal lit les arguments dans
        // le code, et une valeur calculée est un angle mort pour lui.
        if ($resource->isVisibleToClient()) {
            $this->auditLogger->log('studio', 'space_resource.shown', 'SpaceResource', $resource->getId(), $this->auditPayload($resource));

            return;
        }

        $this->auditLogger->log('studio', 'space_resource.hidden', 'SpaceResource', $resource->getId(), $this->auditPayload($resource));
    }

    /**
     * L'ordre voulu, appliqué aux seules ressources de cet espace.
     *
     * Les identifiants viennent du navigateur, donc rien n'empêche une requête
     * d'en glisser un qui appartient ailleurs. La liste de l'espace est relue
     * ici et indexée : ce qui n'y figure pas est ignoré, et ne change donc pas
     * de place chez quelqu'un d'autre.
     *
     * @param list<int> $orderedIds
     */
    public function reorder(CustomerSpaceInterface $space, array $orderedIds): void
    {
        $own = [];

        foreach ($this->resources->findForSpace($space) as $resource) {
            $own[(int) $resource->getId()] = $resource;
        }

        $position = 0;

        foreach ($orderedIds as $id) {
            $resource = $own[$id] ?? null;

            if (!$resource instanceof SpaceResourceInterface) {
                continue;
            }

            $resource->setPosition($position);
            ++$position;
        }

        $this->entityManager->flush();
    }

    public function delete(SpaceResourceInterface $resource): void
    {
        $this->auditLogger->log('studio', 'space_resource.deleted', 'SpaceResource', $resource->getId(), $this->auditPayload($resource));

        $this->entityManager->remove($resource);
        $this->entityManager->flush();
    }

    protected function createResource(): SpaceResourceInterface
    {
        return new SpaceResource();
    }

    protected function applyInput(SpaceResourceInterface $resource, SpaceResourceInputInterface $input): void
    {
        $resource
            ->setKind($input->getKind())
            ->setLabel($input->getLabel())
            ->setUrl($input->getUrl())
            ->setBody($input->getBody())
            ->setEmail($input->getEmail())
            ->setPhone($input->getPhone())
            ->setVisibleToClient($input->isVisibleToClient());
    }

    /**
     * Ce que le journal garde.
     *
     * Pas le corps : une ressource peut porter un paragraphe entier, et un
     * journal d'audit n'est pas une seconde copie du contenu. Le genre, le
     * libellé et la visibilité suffisent à reconstituer ce qui s'est passé.
     *
     * @return array<string, mixed>
     */
    protected function auditPayload(SpaceResourceInterface $resource): array
    {
        return [
            'space' => $resource->getSpace()->getId(),
            'kind' => $resource->getKind()->value,
            'label' => $resource->getLabel(),
            'visibleToClient' => $resource->isVisibleToClient(),
        ];
    }
}
