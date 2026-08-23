<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Editorial\Entity;

use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Module\Editorial\Taxonomy\Entity\Taxonomy;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTerm;
use PHPUnit\Framework\TestCase;

/**
 * Only the owning side of these many-to-manys was being updated. The row
 * reached the database, but the inverse collection - already loaded in the
 * same request - stayed as it was, so the response serialized right after
 * the write said the link had not been made. On screen the change undid
 * itself until a reload.
 */
final class AssociationSyncTest extends TestCase
{
    public function testLinkingAPostTypeToATaxonomyShowsOnBothSides(): void
    {
        $postType = new PostType();
        $taxonomy = new Taxonomy();

        $postType->addTaxonomy($taxonomy);

        self::assertTrue($postType->getTaxonomies()->contains($taxonomy));
        self::assertTrue($taxonomy->getPostTypes()->contains($postType));
    }

    public function testUnlinkingClearsBothSides(): void
    {
        $postType = new PostType();
        $taxonomy = new Taxonomy();
        $postType->addTaxonomy($taxonomy);

        $postType->removeTaxonomy($taxonomy);

        self::assertFalse($postType->getTaxonomies()->contains($taxonomy));
        self::assertFalse($taxonomy->getPostTypes()->contains($postType));
    }

    public function testLinkingFromTheInverseSideAlsoShowsOnBoth(): void
    {
        $postType = new PostType();
        $taxonomy = new Taxonomy();

        $taxonomy->addPostType($postType);

        self::assertTrue($taxonomy->getPostTypes()->contains($postType));
        self::assertTrue($postType->getTaxonomies()->contains($taxonomy));
    }

    public function testTaggingAPostShowsOnBothSides(): void
    {
        $post = new Post();
        $term = new TaxonomyTerm();

        $post->addTerm($term);

        self::assertTrue($post->getTerms()->contains($term));
        self::assertTrue($term->getPosts()->contains($post));

        $post->removeTerm($term);

        self::assertFalse($post->getTerms()->contains($term));
        self::assertFalse($term->getPosts()->contains($post));
    }

    /** The mutual calls must settle rather than bounce between the two sides. */
    public function testAddingTwiceDoesNotDuplicateOrRecurse(): void
    {
        $post = new Post();
        $term = new TaxonomyTerm();

        $post->addTerm($term);
        $post->addTerm($term);
        $term->addPost($post);

        self::assertCount(1, $post->getTerms());
        self::assertCount(1, $term->getPosts());
    }

    /** A post is not its own related post, however the ids line up. */
    public function testAPostCannotRelateToItself(): void
    {
        $post = new Post();

        $post->addRelatedPost($post);

        self::assertCount(0, $post->getRelatedPosts());
    }
}
