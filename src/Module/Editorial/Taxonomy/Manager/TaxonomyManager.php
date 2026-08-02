<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Taxonomy\Manager;

use Aurora\Core\Sequence\SequenceGenerator;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Editorial\PostType\Repository\PostTypeRepository;
use Aurora\Module\Editorial\Setting\EditorialSettingEnum;
use Aurora\Module\Editorial\Taxonomy\Dto\TaxonomyInputInterface;
use Aurora\Module\Editorial\Taxonomy\Dto\TaxonomyTermInputInterface;
use Aurora\Module\Editorial\Taxonomy\Entity\Taxonomy;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyInterface;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTerm;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTermInterface;
use Aurora\Module\Editorial\Taxonomy\Repository\TaxonomyRepository;
use Aurora\Module\Editorial\Taxonomy\Repository\TaxonomyTermRepository;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsAlias(TaxonomyManagerInterface::class)]
class TaxonomyManager implements TaxonomyManagerInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly TaxonomyRepository $taxonomyRepository,
        protected readonly TaxonomyTermRepository $termRepository,
        protected readonly PostTypeRepository $postTypeRepository,
        protected readonly SluggerInterface $slugger,
        protected readonly TranslatorInterface $translator,
        protected readonly AuditLogger $auditLogger,
        protected readonly SequenceGenerator $sequenceGenerator,
        protected readonly SettingRepository $settingRepository,
    ) {}

    public function create(TaxonomyInputInterface $input): TaxonomyInterface
    {
        $this->assertSlugIsFree($input->getSlug());

        $taxonomy = $this->createTaxonomy();
        $taxonomy->setIsBuiltIn(false);
        $this->applyInput($taxonomy, $input);

        $this->entityManager->persist($taxonomy);
        $this->entityManager->flush();

        $this->auditTaxonomyCreated($taxonomy);

        return $taxonomy;
    }

    public function update(TaxonomyInterface $taxonomy, TaxonomyInputInterface $input): void
    {
        if (!$taxonomy->isBuiltIn() && $input->getSlug() !== $taxonomy->getSlug()) {
            $this->assertSlugIsFree($input->getSlug());
        }

        $this->applyInput($taxonomy, $input);

        $this->entityManager->flush();

        $this->auditTaxonomyUpdated($taxonomy);
    }

    public function delete(TaxonomyInterface $taxonomy): void
    {
        if ($taxonomy->isBuiltIn()) {
            throw new RuntimeException($this->translator->trans('backend.taxonomies.errors.builtin_protected'));
        }

        $this->auditTaxonomyDeleted($taxonomy);

        $this->entityManager->remove($taxonomy);
        $this->entityManager->flush();
    }

    public function createTerm(TaxonomyInterface $taxonomy, TaxonomyTermInputInterface $input): TaxonomyTermInterface
    {
        $term = $this->createTaxonomyTerm();
        $term->setTaxonomy($taxonomy);

        $this->applyTermInput($term, $input);
        $term->setPosition($this->nextPositionFor($taxonomy, $term->getParent()));

        $this->entityManager->persist($term);
        $this->entityManager->flush();

        // The reference needs the row to exist first: the generator hands out
        // one number per prefix and we do not want to burn one on a rollback.
        $term->setReference($this->sequenceGenerator->next(
            $this->settingRepository->getOrDefault(EditorialSettingEnum::TaxonomyTermPrefix),
        ));
        $this->entityManager->flush();

        $this->auditTermCreated($term);

        return $term;
    }

    public function updateTerm(TaxonomyTermInterface $term, TaxonomyTermInputInterface $input): void
    {
        $parent = $this->resolveParent($term->getTaxonomy(), $input->getParentId());

        if ($parent instanceof TaxonomyTermInterface && ($parent === $term || $parent->isDescendantOf($term))) {
            throw new InvalidArgumentException($this->translator->trans('backend.taxonomies.errors.term_self_nested'));
        }

        $this->applyTermInput($term, $input, $parent);

        $this->entityManager->flush();

        $this->auditTermUpdated($term);
    }

    public function deleteTerm(TaxonomyTermInterface $term): void
    {
        // Promote the children rather than cascade: losing a branch because
        // its middle node went is never what the editor meant.
        foreach ($term->getChildren() as $child) {
            $child->setParent($term->getParent());
        }

        $this->auditTermDeleted($term);

        $this->entityManager->remove($term);
        $this->entityManager->flush();
    }

    public function reorderTerms(TaxonomyInterface $taxonomy, array $entries): void
    {
        $termsById = [];
        foreach ($this->termRepository->findByTaxonomyOrdered($taxonomy) as $term) {
            $termsById[$term->getId()] = $term;
        }

        // Read the whole intended tree first and reject a cycle before any
        // entity moves. Checking as we go would compare against a tree that
        // is half-old and half-new, and miss the cycle.
        $parentMap = [];
        foreach ($entries as $entry) {
            $id = $entry['id'];
            if (!isset($termsById[$id])) {
                continue;
            }

            $parentId = $entry['parentId'] ?? null;
            $parentMap[$id] = null !== $parentId && $parentId > 0 ? $parentId : null;
        }

        $this->assertNoCycle($parentMap);

        foreach ($entries as $entry) {
            $term = $termsById[$entry['id']] ?? null;
            if (null === $term) {
                continue;
            }

            $parentId = $parentMap[$entry['id']] ?? null;
            $term->setParent(null !== $parentId ? ($termsById[$parentId] ?? null) : null);
            $term->setPosition($entry['position']);
        }

        $this->entityManager->flush();
    }

    // ── Hooks: instanciation ──────────────────────────────────────────────────

    protected function createTaxonomy(): TaxonomyInterface
    {
        return new Taxonomy();
    }

    protected function createTaxonomyTerm(): TaxonomyTermInterface
    {
        return new TaxonomyTerm();
    }

    // ── Hooks: hydratation ────────────────────────────────────────────────────

    protected function applyInput(TaxonomyInterface $taxonomy, TaxonomyInputInterface $input): void
    {
        // A built-in taxonomy keeps its slug and its shape; only labels and
        // which post types it applies to are the editor's to change.
        if (!$taxonomy->isBuiltIn()) {
            $taxonomy->setSlug($input->getSlug());
            $taxonomy->setHierarchical($input->isHierarchical());
        }

        foreach ($input->getTranslations() as $locale => $payload) {
            $translation = $taxonomy->translate((string) $locale);
            $translation->setLabel((string) ($payload['label'] ?? ''));
            $translation->setDescription($payload['description'] ?? null);
        }

        $this->syncPostTypes($taxonomy, $input->getPostTypeIds());
    }

    protected function applyTermInput(TaxonomyTermInterface $term, TaxonomyTermInputInterface $input, ?TaxonomyTermInterface $parent = null): void
    {
        $term->setParent($parent ?? $this->resolveParent($term->getTaxonomy(), $input->getParentId()));

        foreach ($input->getTranslations() as $locale => $payload) {
            $translation = $term->translate((string) $locale);
            $translation->setName($payload['name']);
            $translation->setSlug($this->slugFor($payload['name'], $payload['slug'] ?? null));
            $translation->setDescription($payload['description'] ?? null);
        }
    }

    // ── Hooks: audit ──────────────────────────────────────────────────────────

    protected function auditTaxonomyCreated(TaxonomyInterface $taxonomy): void
    {
        $this->auditLogger->log('editorial', 'taxonomy.created', 'Taxonomy', $taxonomy->getId(), $this->auditTaxonomyPayload($taxonomy));
    }

    protected function auditTaxonomyUpdated(TaxonomyInterface $taxonomy): void
    {
        $this->auditLogger->log('editorial', 'taxonomy.updated', 'Taxonomy', $taxonomy->getId(), $this->auditTaxonomyPayload($taxonomy));
    }

    protected function auditTaxonomyDeleted(TaxonomyInterface $taxonomy): void
    {
        $this->auditLogger->log('editorial', 'taxonomy.deleted', 'Taxonomy', $taxonomy->getId(), $this->auditTaxonomyPayload($taxonomy));
    }

    protected function auditTermCreated(TaxonomyTermInterface $term): void
    {
        $this->auditLogger->log('editorial', 'taxonomy.term.created', 'TaxonomyTerm', $term->getId(), $this->auditTermPayload($term));
    }

    protected function auditTermUpdated(TaxonomyTermInterface $term): void
    {
        $this->auditLogger->log('editorial', 'taxonomy.term.updated', 'TaxonomyTerm', $term->getId(), $this->auditTermPayload($term));
    }

    protected function auditTermDeleted(TaxonomyTermInterface $term): void
    {
        $this->auditLogger->log('editorial', 'taxonomy.term.deleted', 'TaxonomyTerm', $term->getId(), $this->auditTermPayload($term));
    }

    /** @return array<string, mixed> */
    protected function auditTaxonomyPayload(TaxonomyInterface $taxonomy): array
    {
        return ['slug' => $taxonomy->getSlug()];
    }

    /** @return array<string, mixed> */
    protected function auditTermPayload(TaxonomyTermInterface $term): array
    {
        return ['taxonomySlug' => $term->getTaxonomy()->getSlug(), 'reference' => $term->getReference()];
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function assertSlugIsFree(string $slug): void
    {
        if ($this->taxonomyRepository->findOneBySlug($slug) instanceof TaxonomyInterface) {
            throw new InvalidArgumentException($this->translator->trans('backend.taxonomies.errors.slug_taken', ['{slug}' => $slug]));
        }
    }

    private function slugFor(string $name, ?string $slug): string
    {
        if (null !== $slug && '' !== $slug) {
            return $slug;
        }

        return '' !== $name ? $this->slugger->slug($name)->lower()->toString() : '';
    }

    /** @param array<int, ?int> $parentMap */
    private function assertNoCycle(array $parentMap): void
    {
        foreach (array_keys($parentMap) as $id) {
            $seen = [$id => true];
            $current = $parentMap[$id];
            while (null !== $current) {
                if (isset($seen[$current])) {
                    throw new InvalidArgumentException($this->translator->trans('backend.taxonomies.errors.reorder_cycle', ['{id}' => $id]));
                }

                $seen[$current] = true;
                $current = $parentMap[$current] ?? null;
            }
        }
    }

    /** @param list<int> $postTypeIds */
    private function syncPostTypes(TaxonomyInterface $taxonomy, array $postTypeIds): void
    {
        foreach ($taxonomy->getPostTypes() as $existing) {
            if (!in_array($existing->getId(), $postTypeIds, true)) {
                $existing->removeTaxonomy($taxonomy);
            }
        }

        if ([] === $postTypeIds) {
            return;
        }

        foreach ($this->postTypeRepository->findBy(['id' => $postTypeIds]) as $postType) {
            $postType->addTaxonomy($taxonomy);
        }
    }

    private function resolveParent(TaxonomyInterface $taxonomy, ?int $parentId): ?TaxonomyTermInterface
    {
        if (null === $parentId || !$taxonomy->isHierarchical()) {
            return null;
        }

        $parent = $this->termRepository->find($parentId);
        if (!$parent instanceof TaxonomyTermInterface || $parent->getTaxonomy()->getId() !== $taxonomy->getId()) {
            throw new InvalidArgumentException($this->translator->trans('backend.taxonomies.errors.parent_wrong_taxonomy', ['{parentId}' => $parentId, '{taxonomy}' => $taxonomy->getSlug()]));
        }

        return $parent;
    }

    private function nextPositionFor(TaxonomyInterface $taxonomy, ?TaxonomyTermInterface $parent): int
    {
        $max = -1;
        foreach ($this->termRepository->findBy(['taxonomy' => $taxonomy, 'parent' => $parent]) as $sibling) {
            $max = max($max, $sibling->getPosition());
        }

        return $max + 1;
    }
}
