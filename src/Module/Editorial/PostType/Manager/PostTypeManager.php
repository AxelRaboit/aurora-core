<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\PostType\Manager;

use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Editorial\PostType\Dto\PostTypeFieldInputInterface;
use Aurora\Module\Editorial\PostType\Dto\PostTypeInputInterface;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Module\Editorial\PostType\Entity\PostTypeField;
use Aurora\Module\Editorial\PostType\Entity\PostTypeFieldInterface;
use Aurora\Module\Editorial\PostType\Entity\PostTypeInterface;
use Aurora\Module\Editorial\PostType\Repository\PostTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsAlias(PostTypeManagerInterface::class)]
class PostTypeManager implements PostTypeManagerInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly PostTypeRepository $postTypeRepository,
        protected readonly TranslatorInterface $translator,
        protected readonly AuditLogger $auditLogger,
    ) {}

    public function create(PostTypeInputInterface $input): PostTypeInterface
    {
        $this->assertSlugIsFree($input->getSlug());

        $postType = $this->createPostType();
        $this->applyInput($postType, $input);
        $postType->setIsBuiltIn(false);

        $this->entityManager->persist($postType);
        $this->entityManager->flush();

        $this->auditCreated($postType);

        return $postType;
    }

    public function update(PostTypeInterface $postType, PostTypeInputInterface $input): void
    {
        // A built-in type keeps its slug: routes and existing content point at
        // it. The form disables the field, so a differing slug here is a client
        // that ignored that - silently keeping the old one beats failing.
        if (!$postType->isBuiltIn() && $input->getSlug() !== $postType->getSlug()) {
            $this->assertSlugIsFree($input->getSlug());
        }

        $this->applyInput($postType, $input);

        $this->entityManager->flush();

        $this->auditUpdated($postType);
    }

    public function delete(PostTypeInterface $postType): void
    {
        if ($postType->isBuiltIn()) {
            throw new RuntimeException($this->translator->trans('backend.post_types.errors.builtin_protected'));
        }

        // Posts point at their type with a non-nullable column, so letting
        // this through means a foreign key violation and a 500 rather than
        // an answer the editor can act on. Trashed posts still count: they
        // are recoverable, and would come back to a type that no longer is.
        if ($postType->getPosts()->count() > 0) {
            throw new RuntimeException($this->translator->trans('backend.post_types.errors.has_posts'));
        }

        $this->auditDeleted($postType);

        $this->entityManager->remove($postType);
        $this->entityManager->flush();
    }

    public function createField(PostTypeInterface $postType, PostTypeFieldInputInterface $input): PostTypeFieldInterface
    {
        $this->assertFieldNameIsFree($postType, $input->getName());

        $field = $this->createPostTypeField();
        $this->applyFieldInput($field, $input);
        $field->setPosition($this->nextPosition($postType));

        $postType->addField($field);

        $this->entityManager->persist($field);
        $this->entityManager->flush();

        return $field;
    }

    public function updateField(PostTypeFieldInterface $field, PostTypeFieldInputInterface $input): void
    {
        if ($input->getName() !== $field->getName()) {
            $this->assertFieldNameIsFree($field->getPostType(), $input->getName(), $field);
        }

        $this->applyFieldInput($field, $input);

        $this->entityManager->flush();
    }

    public function deleteField(PostTypeFieldInterface $field): void
    {
        $this->entityManager->remove($field);
        $this->entityManager->flush();
    }

    public function reorderFields(PostTypeInterface $postType, array $orderedFieldIds): void
    {
        $position = 0;
        foreach ($orderedFieldIds as $fieldId) {
            $field = $postType->findFieldById($fieldId);
            if (!$field instanceof PostTypeFieldInterface) {
                continue;
            }

            $field->setPosition($position++);
        }

        $this->entityManager->flush();
    }

    // ── Hooks: instanciation ──────────────────────────────────────────────────

    protected function createPostType(): PostTypeInterface
    {
        return new PostType();
    }

    protected function createPostTypeField(): PostTypeFieldInterface
    {
        return new PostTypeField();
    }

    // ── Hooks: hydratation ────────────────────────────────────────────────────

    protected function applyInput(PostTypeInterface $postType, PostTypeInputInterface $input): void
    {
        if (!$postType->isBuiltIn()) {
            $postType->setSlug($input->getSlug());
        }

        $postType->setLabel($input->getLabel());
        $postType->setIcon($input->getIcon());
        $postType->setHasArchive($input->hasArchive());
        $postType->setSupports($input->getSupports());
    }

    protected function applyFieldInput(PostTypeFieldInterface $field, PostTypeFieldInputInterface $input): void
    {
        $field->setName($input->getName());
        $field->setLabel($input->getLabel());
        $field->setType($input->getType());
        $field->setRequired($input->isRequired());
        $field->setTranslatable($input->isTranslatable());
        $field->setOptions($input->getOptions());
    }

    // ── Hooks: audit ──────────────────────────────────────────────────────────

    protected function auditCreated(PostTypeInterface $postType): void
    {
        $this->auditLogger->log('editorial', 'post_type.created', 'PostType', $postType->getId(), $this->auditPayload($postType));
    }

    protected function auditUpdated(PostTypeInterface $postType): void
    {
        $this->auditLogger->log('editorial', 'post_type.updated', 'PostType', $postType->getId(), $this->auditPayload($postType));
    }

    protected function auditDeleted(PostTypeInterface $postType): void
    {
        $this->auditLogger->log('editorial', 'post_type.deleted', 'PostType', $postType->getId(), $this->auditPayload($postType));
    }

    /** @return array<string, mixed> */
    protected function auditPayload(PostTypeInterface $postType): array
    {
        return ['slug' => $postType->getSlug()];
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function assertSlugIsFree(string $slug): void
    {
        if ($this->postTypeRepository->findOneBySlug($slug) instanceof PostTypeInterface) {
            throw new InvalidArgumentException($this->translator->trans('backend.post_types.errors.slug_taken', ['{slug}' => $slug]));
        }
    }

    private function assertFieldNameIsFree(PostTypeInterface $postType, string $name, ?PostTypeFieldInterface $ignore = null): void
    {
        foreach ($postType->getFields() as $field) {
            if ($field !== $ignore && $field->getName() === $name) {
                throw new InvalidArgumentException($this->translator->trans('backend.post_types.errors.field_name_taken', ['{name}' => $name]));
            }
        }
    }

    private function nextPosition(PostTypeInterface $postType): int
    {
        $max = -1;
        foreach ($postType->getFields() as $field) {
            $max = max($max, $field->getPosition());
        }

        return $max + 1;
    }
}
