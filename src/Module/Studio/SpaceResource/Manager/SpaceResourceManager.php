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
 * Pin, reorder and remove the resources of a space.
 *
 * **Every visibility change is logged.** It is the gesture that publishes:
 * it decides that a row filed in a space becomes a row the client reads.
 * Knowing after the fact when a resource was opened, and who opened it, is
 * what makes the difference between an error you fix and an error you
 * discover.
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

        // Two branches and two literals rather than a ternary in the call: the log
        // drift check reads the arguments in the code, and a computed value is a
        // blind spot for it.
        if ($resource->isVisibleToClient()) {
            $this->auditLogger->log('studio', 'space_resource.shown', 'SpaceResource', $resource->getId(), $this->auditPayload($resource));

            return;
        }

        $this->auditLogger->log('studio', 'space_resource.hidden', 'SpaceResource', $resource->getId(), $this->auditPayload($resource));
    }

    /**
     * The wanted order, applied to the resources of this space only.
     *
     * The identifiers come from the browser, so nothing prevents a request from
     * slipping in one that belongs elsewhere. The list of the space is read back
     * here and indexed: what is not in it is ignored, and so does not change
     * place for someone else.
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
     * What the log keeps.
     *
     * Not the body: a resource can carry a whole paragraph, and an audit log is
     * not a second copy of the content. The kind, the label and the visibility
     * are enough to reconstruct what happened.
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
