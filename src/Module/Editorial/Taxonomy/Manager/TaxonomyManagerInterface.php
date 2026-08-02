<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Taxonomy\Manager;

use Aurora\Module\Editorial\Taxonomy\Dto\TaxonomyInputInterface;
use Aurora\Module\Editorial\Taxonomy\Dto\TaxonomyTermInputInterface;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyInterface;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTermInterface;

interface TaxonomyManagerInterface
{
    public function create(TaxonomyInputInterface $input): TaxonomyInterface;

    public function update(TaxonomyInterface $taxonomy, TaxonomyInputInterface $input): void;

    public function delete(TaxonomyInterface $taxonomy): void;

    public function createTerm(TaxonomyInterface $taxonomy, TaxonomyTermInputInterface $input): TaxonomyTermInterface;

    public function updateTerm(TaxonomyTermInterface $term, TaxonomyTermInputInterface $input): void;

    public function deleteTerm(TaxonomyTermInterface $term): void;

    /** @param list<array{id: int, parentId: ?int, position: int}> $entries */
    public function reorderTerms(TaxonomyInterface $taxonomy, array $entries): void;
}
