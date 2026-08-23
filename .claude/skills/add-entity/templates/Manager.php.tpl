<?php

declare(strict_types=1);

namespace {{NAMESPACE}}\Manager;

use Aurora\Module\Dev\Audit\Service\AuditLogger;
use {{NAMESPACE}}\Dto\{{NAME}}InputInterface;
use {{NAMESPACE}}\Entity\{{NAME}};
use {{NAMESPACE}}\Entity\{{NAME}}Interface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias({{NAME}}ManagerInterface::class)]
class {{NAME}}Manager implements {{NAME}}ManagerInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly AuditLogger $auditLogger,
    ) {}

    public function create({{NAME}}InputInterface $input): {{NAME}}Interface
    {
        ${{NAME_CAMEL}} = $this->create{{NAME}}();
        $this->applyInput(${{NAME_CAMEL}}, $input);

        $this->entityManager->persist(${{NAME_CAMEL}});
        $this->entityManager->flush();

        $this->auditCreated(${{NAME_CAMEL}});

        return ${{NAME_CAMEL}};
    }

    public function update({{NAME}}Interface ${{NAME_CAMEL}}, {{NAME}}InputInterface $input): void
    {
        $this->applyInput(${{NAME_CAMEL}}, $input);
        $this->entityManager->flush();

        $this->auditUpdated(${{NAME_CAMEL}});
    }

    public function delete({{NAME}}Interface ${{NAME_CAMEL}}): void
    {
        $this->auditDeleted(${{NAME_CAMEL}});

        $this->entityManager->remove(${{NAME_CAMEL}});
        $this->entityManager->flush();
    }

    /**
     * Instantiates the concrete entity. Override in a subclass to return a
     * client-substituted class - `resolve_target_entities` only affects
     * Doctrine relation resolution, not direct `new` calls.
     */
    protected function create{{NAME}}(): {{NAME}}Interface
    {
        return new {{NAME}}();
    }

    /**
     * Hydrates the entity from the input DTO. Override in a subclass and
     * call `parent::applyInput()` FIRST so the base fields stay populated,
     * then read your own extra fields off the input.
     */
    protected function applyInput({{NAME}}Interface ${{NAME_CAMEL}}, {{NAME}}InputInterface $input): void
    {
        ${{NAME_CAMEL}}->setName($input->getName());
    }

    protected function auditCreated({{NAME}}Interface ${{NAME_CAMEL}}): void
    {
        $this->auditLogger->log('{{AUDIT_CHANNEL}}', '{{NAME_SNAKE}}.created', '{{NAME}}', ${{NAME_CAMEL}}->getId(), $this->auditPayload(${{NAME_CAMEL}}));
    }

    protected function auditUpdated({{NAME}}Interface ${{NAME_CAMEL}}): void
    {
        $this->auditLogger->log('{{AUDIT_CHANNEL}}', '{{NAME_SNAKE}}.updated', '{{NAME}}', ${{NAME_CAMEL}}->getId(), $this->auditPayload(${{NAME_CAMEL}}));
    }

    protected function auditDeleted({{NAME}}Interface ${{NAME_CAMEL}}): void
    {
        $this->auditLogger->log('{{AUDIT_CHANNEL}}', '{{NAME_SNAKE}}.deleted', '{{NAME}}', ${{NAME_CAMEL}}->getId(), $this->auditPayload(${{NAME_CAMEL}}));
    }

    /**
     * Structured payload logged with every audit entry. Override to add
     * extra fields: `[...parent::auditPayload(${{NAME_CAMEL}}), 'code' => ${{NAME_CAMEL}}->getCode()]`.
     */
    protected function auditPayload({{NAME}}Interface ${{NAME_CAMEL}}): array
    {
        return ['name' => ${{NAME_CAMEL}}->getName()];
    }
}
