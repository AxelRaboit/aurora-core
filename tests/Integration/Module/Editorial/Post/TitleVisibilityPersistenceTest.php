<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Dto\PostInputFactoryInterface;
use Aurora\Module\Editorial\Post\Manager\PostManagerInterface;
use Aurora\Module\Editorial\Post\Serializer\PostSerializerInterface;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Whether a page prints its own title and summary - and the wiring that carries
 * that decision from the form to the column and back.
 *
 * Written as an integration test rather than a unit one for the reason the
 * banner and grid ones exist: a flag can be declared on the entity, accepted by
 * the DTO and still never reach the column, because the one line that copies it
 * in the manager was not written. Nothing fails when that happens; the checkbox
 * simply does not stick, and the author is the one who finds out.
 */
final class TitleVisibilityPersistenceTest extends IntegrationTestCase
{
    private PostManagerInterface $postManager;

    private PostInputFactoryInterface $inputFactory;

    private PostSerializerInterface $postSerializer;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        static::bootKernel();

        $container = static::getContainer();
        $this->postManager = $container->get(PostManagerInterface::class);
        $this->inputFactory = $container->get(PostInputFactoryInterface::class);
        $this->postSerializer = $container->get(PostSerializerInterface::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    /**
     * The default has to be "shown". Every publication that exists predates the
     * column, and a default of false would have blanked the heading of every
     * page at once on deploy.
     */
    public function testAPublicationThatSaysNothingKeepsItsHeading(): void
    {
        $post = $this->postManager->create($this->inputFactory->fromArray([
            'postTypeId' => $this->postType()->getId(),
            'status' => 'draft',
            'translations' => ['fr' => ['title' => 'Bienvenue']],
        ]));

        $this->entityManager->flush();

        self::assertTrue($post->isTitleVisible());
    }

    public function testTheChoiceReachesTheColumnAndComesBackToTheEditor(): void
    {
        $post = $this->postManager->create($this->inputFactory->fromArray([
            'postTypeId' => $this->postType()->getId(),
            'status' => 'draft',
            'titleVisible' => false,
            'translations' => ['fr' => ['title' => 'Bienvenue']],
        ]));

        $this->entityManager->flush();

        self::assertFalse($post->isTitleVisible());
        self::assertFalse($this->postSerializer->serialize($post)['titleVisible']);
    }

    /**
     * Shared, not per translation: a page with a heading in French and none in
     * German would be two different pages. There is one flag for the post, and
     * writing one language's form does not give the other a different one.
     */
    public function testTheChoiceIsSharedByEveryLanguage(): void
    {
        $post = $this->postManager->create($this->inputFactory->fromArray([
            'postTypeId' => $this->postType()->getId(),
            'status' => 'draft',
            'titleVisible' => false,
            'translations' => [
                'fr' => ['title' => 'Bienvenue'],
                'en' => ['title' => 'Welcome'],
            ],
        ]));

        $this->entityManager->flush();

        self::assertFalse($post->isTitleVisible());
        self::assertCount(2, $post->getTranslations());
    }

    private function postType(): PostType
    {
        $type = $this->entityManager->getRepository(PostType::class)->findOneBy([]);

        self::assertInstanceOf(PostType::class, $type, 'the fixtures ship at least one post type');

        return $type;
    }
}
