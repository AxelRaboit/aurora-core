<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Dto\PostInputFactoryInterface;
use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Manager\PostManagerInterface;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Les couleurs qu'une publication pose pour elle seule, jusqu'à la page servie.
 *
 * Testé ici plutôt qu'en unitaire parce que le trou était précisément entre les
 * deux : la colonne, le DTO et l'écran d'édition étaient branchés, et rien ne
 * lisait la couleur au rendu - la fonctionnalité était complète en base et
 * invisible sur le site. Ce qui se vérifie donc : que la couleur traverse la
 * chaîne entière, du tableau d'entrée au CSS de la réponse.
 *
 * La résolution elle-même - précédence, héritage, contraste - est couverte à
 * l'unité par ThemeContextSurfacesTest, qui n'a pas besoin d'une base.
 */
final class PostSurfaceColorsTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    /** @var list<array{class-string, int}> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as [$class, $id]) {
            $entity = $this->entityManager->find($class, $id);
            if (null !== $entity) {
                $this->entityManager->remove($entity);
            }
        }
        $this->entityManager->flush();
        $this->created = [];

        parent::tearDown();
    }

    /** Sans couleur choisie, la page sort exactement comme avant. */
    public function testAPublicationWithoutColoursEmitsNoSurfaceRule(): void
    {
        $this->published('Page ordinaire');

        $this->client->request('GET', '/fr/surface-type/page-ordinaire');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(
            '.aurora-surface-header{',
            (string) $this->client->getResponse()->getContent(),
        );
    }

    /**
     * Le cœur de la fonctionnalité : la couleur posée sur la publication arrive
     * dans le CSS de sa page, et emporte son jeu de jetons contrasté.
     */
    public function testTheTopbarColourReachesTheServedPage(): void
    {
        $this->published('Page repeinte', ['headerColor' => '#0f172a']);

        $this->client->request('GET', '/fr/surface-type/page-repeinte');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('html[data-theme] .aurora-surface-header{', $html);
        self::assertStringContainsString('--th-surface-bg: #0f172a;', $html);
        // Sans ce jeton, les libellés de la topbar resteraient sombres sur
        // sombre - c'est ce qui sépare « repeindre » de « rendre illisible ».
        self::assertStringContainsString('--th-primary: rgb(243 244 246);', $html);
    }

    /** Les trois surfaces sont indépendantes jusque dans la page servie. */
    public function testTheThreeSurfacesArePaintedIndependently(): void
    {
        $this->published('Page tricolore', [
            'backgroundColor' => '#fef9c3',
            'headerColor' => '#0f172a',
            'footerColor' => '#1f2937',
        ]);

        $this->client->request('GET', '/fr/surface-type/page-tricolore');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('--th-surface-bg: #fef9c3;', $html);
        self::assertStringContainsString('--th-surface-bg: #0f172a;', $html);
        self::assertStringContainsString('--th-surface-bg: #1f2937;', $html);
    }

    /**
     * La couleur d'une publication ne fuit pas sur les autres.
     *
     * Le CSS est posé dans le `<head>` de la page rendue, donc rien ne le
     * partage ; le test existe parce qu'une implémentation par variable de
     * thème l'aurait fait, et que rien ne l'aurait signalé.
     */
    public function testTheColourStaysOnItsOwnPublication(): void
    {
        $this->published('Page peinte', ['headerColor' => '#0f172a']);
        $this->published('Page voisine');

        $this->client->request('GET', '/fr/surface-type/page-voisine');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('#0f172a', (string) $this->client->getResponse()->getContent());
    }

    /** Une couleur qui n'est pas un `#rrggbb` n'atteint pas la page. */
    public function testAMalformedColourIsRefusedAtTheWriteBoundary(): void
    {
        $post = $this->published('Page douteuse', ['headerColor' => 'red; background: url(x)']);

        self::assertNull($post->getHeaderColor());

        $this->client->request('GET', '/fr/surface-type/page-douteuse');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('.aurora-surface-header{', (string) $this->client->getResponse()->getContent());
    }

    /**
     * @param array<string, string> $colours
     */
    private function published(string $title, array $colours = []): PostInterface
    {
        $postType = $this->entityManager->getRepository(PostType::class)->findOneBy(['slug' => 'surface-type']);

        if (!$postType instanceof PostType) {
            $postType = new PostType();
            $postType->setSlug('surface-type');
            $postType->setLabel('Surface type');
            $this->entityManager->persist($postType);
            $this->entityManager->flush();
            $this->created[] = [PostType::class, (int) $postType->getId()];
        }

        $post = static::getContainer()->get(PostManagerInterface::class)->create(
            static::getContainer()->get(PostInputFactoryInterface::class)->fromArray([
                'postTypeId' => $postType->getId(),
                'status' => 'published',
                'translations' => ['fr' => ['title' => $title]],
                ...$colours,
            ]),
        );
        $this->entityManager->flush();
        $this->created[] = [Post::class, (int) $post->getId()];

        return $post;
    }
}
