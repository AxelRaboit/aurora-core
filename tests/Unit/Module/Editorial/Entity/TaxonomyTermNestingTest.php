<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Editorial\Entity;

use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTerm;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTermInterface;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * isDescendantOf() guards the move that would make a term its own parent.
 * It compared with `===` alone, and walking up a ManyToOne hands back
 * Doctrine proxies - so an ancestor loaded as a proxy never matched the
 * candidate loaded as a real entity, and the guard passed a move it was
 * there to stop.
 */
final class TaxonomyTermNestingTest extends TestCase
{
    public function testFindsAnAncestorByIdentity(): void
    {
        $root = $this->term(1);
        $child = $this->term(2)->setParent($root);

        self::assertTrue($child->isDescendantOf($root));
        self::assertFalse($root->isDescendantOf($child));
    }

    public function testFindsAnAncestorSeveralLevelsUp(): void
    {
        $root = $this->term(1);
        $middle = $this->term(2)->setParent($root);
        $leaf = $this->term(3)->setParent($middle);

        self::assertTrue($leaf->isDescendantOf($root));
        self::assertSame([$root, $middle], $leaf->getAncestors());
    }

    /**
     * The regression itself: two distinct objects for the same row, which is
     * exactly what a proxy and its loaded entity are.
     */
    public function testFindsAnAncestorThatIsADifferentObjectForTheSameRow(): void
    {
        $root = $this->term(1);
        $child = $this->term(2)->setParent($root);

        $sameRowOtherInstance = $this->term(1);

        self::assertNotSame($root, $sameRowOtherInstance);
        self::assertTrue($child->isDescendantOf($sameRowOtherInstance));
    }

    /** An unsaved term has no id, so identity is all there is to go on. */
    public function testDoesNotMatchTwoUnsavedTermsOnTheirNullIds(): void
    {
        $root = new TaxonomyTerm();
        $child = (new TaxonomyTerm())->setParent($root);

        self::assertTrue($child->isDescendantOf($root));
        self::assertFalse($child->isDescendantOf(new TaxonomyTerm()));
    }

    private function term(int $id): TaxonomyTermInterface
    {
        $term = new TaxonomyTerm();
        $property = new ReflectionProperty(TaxonomyTerm::class, 'id');
        $property->setValue($term, $id);

        return $term;
    }
}
