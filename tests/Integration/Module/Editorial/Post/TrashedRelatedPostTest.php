<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Dto\PostInputFactoryInterface;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Manager\PostManagerInterface;
use Aurora\Module\Editorial\Post\Serializer\PostSerializerInterface;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;

use function array_column;

/**
 * A related post that goes to the trash.
 *
 * Found on 28/09/2026: two projects trashed three weeks earlier were still
 * listed among the home page's related posts, like any other. The link is
 * kept on purpose - restoring the post puts it back where it was - so what
 * was missing is the editor saying the post is gone.
 */
final class TrashedRelatedPostTest extends IntegrationTestCase
{
    private PostManagerInterface $postManager;

    private PostInputFactoryInterface $inputFactory;

    private PostSerializerInterface $postSerializer;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $container = self::getContainer();
        $this->postManager = $container->get(PostManagerInterface::class);
        $this->inputFactory = $container->get(PostInputFactoryInterface::class);
        $this->postSerializer = $container->get(PostSerializerInterface::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    public function testTheEditorIsToldARelatedPostIsInTheTrash(): void
    {
        $related = $this->post('Onyx');
        $home = $this->post('Accueil', [(int) $related->getId()]);

        $this->postManager->delete($related);
        $this->entityManager->flush();
        $this->entityManager->refresh($home);

        $serialized = $this->postSerializer->serializeFull($home);

        self::assertSame([(int) $related->getId()], $serialized['relatedPostIds'], 'kept, so a restore finds its place again');
        self::assertSame([true], array_column($serialized['relatedPosts'], 'trashed'));
    }

    public function testRestoringTheRelatedPostClearsTheMark(): void
    {
        $related = $this->post('Spendly');
        $home = $this->post('Accueil', [(int) $related->getId()]);

        $this->postManager->delete($related);
        $this->postManager->restore($related);

        $this->entityManager->flush();
        $this->entityManager->refresh($home);

        self::assertSame([false], array_column($this->postSerializer->serializeFull($home)['relatedPosts'], 'trashed'));
    }

    /** @param list<int> $relatedPostIds */
    private function post(string $title, array $relatedPostIds = []): PostInterface
    {
        $type = $this->entityManager->getRepository(PostType::class)->findOneBy([]);
        self::assertInstanceOf(PostType::class, $type, 'the fixtures ship at least one post type');

        $post = $this->postManager->create($this->inputFactory->fromArray([
            'postTypeId' => $type->getId(),
            'status' => 'draft',
            'relatedPostIds' => $relatedPostIds,
            'translations' => ['fr' => ['title' => $title]],
        ]));
        $this->entityManager->flush();

        return $post;
    }
}
