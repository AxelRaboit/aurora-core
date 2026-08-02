<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Editorial\Entity;

use Aurora\Module\Editorial\Post\Entity\AbstractPost;
use Aurora\Module\Editorial\Post\Entity\AbstractPostTranslation;
use Aurora\Module\Editorial\Post\Entity\PostTranslationInterface;
use Aurora\Module\Editorial\Taxonomy\Entity\AbstractTaxonomy;
use Aurora\Module\Editorial\Taxonomy\Entity\AbstractTaxonomyTranslation;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTranslationInterface;
use PHPUnit\Framework\TestCase;

/**
 * `translate()` used to write `new TaxonomyTranslation()` straight into the
 * abstract class. resolve_target_entities rewrites Doctrine associations,
 * never a `new` in application code — so a client substituting the
 * translation entity got core's class back and had no way to intervene.
 *
 * These stand in for a client's own subclass. Nothing here would compile if
 * the hooks went back to returning concrete classes.
 */
final class SubstitutionHooksTest extends TestCase
{
    public function testATaxonomySubclassCanSupplyItsOwnTranslation(): void
    {
        $taxonomy = new class extends AbstractTaxonomy {
            public function getId(): ?int
            {
                return 1;
            }

            protected function createTranslation(): TaxonomyTranslationInterface
            {
                return new ClientTaxonomyTranslation();
            }
        };

        $translation = $taxonomy->translate('fr');

        self::assertInstanceOf(ClientTaxonomyTranslation::class, $translation);
        self::assertSame('fr', $translation->getLocale());
        self::assertSame($taxonomy, $translation->getTaxonomy());
    }

    public function testAPostSubclassCanSupplyItsOwnTranslation(): void
    {
        $post = new class extends AbstractPost {
            public function getId(): ?int
            {
                return 1;
            }

            protected function createTranslation(): PostTranslationInterface
            {
                return new ClientPostTranslation();
            }
        };

        $translation = $post->translate('en');

        self::assertInstanceOf(ClientPostTranslation::class, $translation);
        self::assertSame('en', $translation->getLocale());
    }

    public function testAskingTwiceForALocaleReturnsTheSameTranslation(): void
    {
        $taxonomy = new class extends AbstractTaxonomy {
            public function getId(): ?int
            {
                return 1;
            }
        };

        self::assertSame($taxonomy->translate('fr'), $taxonomy->translate('fr'));
        self::assertCount(1, $taxonomy->getTranslations());
    }
}

/** A client's own translation entity: extends the abstract, not core's concrete class. */
final class ClientTaxonomyTranslation extends AbstractTaxonomyTranslation
{
    public function getId(): ?int
    {
        return null;
    }
}

/** Likewise for posts. */
final class ClientPostTranslation extends AbstractPostTranslation
{
    public function getId(): ?int
    {
        return null;
    }
}
