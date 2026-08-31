<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Ged;

use Aurora\Module\Ged\DocumentFolder\Entity\DocumentFolder;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The GED's second menu, checked on the response the browser actually gets.
 *
 * The unit test proves `GedModule` returns the right structure, which is not
 * the same claim: between the module and the reader sit the resolver that has
 * to pick GED for a GED route, the Twig call that hands the view to the menu,
 * and a panel name that is written twice in two languages. Each of those can be
 * wrong while every unit test stays green - and every one of them fails
 * silently, because a menu that never switches views looks exactly like a menu
 * that was never asked to.
 */
final class GedModuleNavViewTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();

        $userRepository = static::getContainer()->get(UserRepository::class);
        $user = $userRepository->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        self::assertInstanceOf(User::class, $user);

        $this->client->loginUser($user, 'admin');
    }

    /**
     * The payload, not the pixels: the documents page is handed the view and
     * the panel name like every other GED page, and the panel then declines to
     * draw itself there because that page owns a better tree of its own. The
     * declining is a client-side decision, covered by `FolderTreePanel.test.js`
     * - the server has no reason to know about it.
     */
    public function testTheDocumentsPageCarriesTheGedViewAndItsPanel(): void
    {
        $view = $this->moduleNavViewOn('/backend/ged/documents');

        self::assertNotNull($view);
        self::assertSame('ged', $view['moduleId']);
        self::assertSame('ged/backend/documents/FolderTreePanel', $view['panelComponent']);
        self::assertCount(1, $view['groups']);

        $routes = array_column($view['groups'][0]['items'], 'route');
        self::assertSame([
            'backend_ged_documents',
            'backend_ged_categories',
            'backend_ged_tags',
            'backend_ged_folders',
        ], $routes);
    }

    /**
     * The reason the panel is worth having: it follows the reader onto the GED
     * pages that have no folder tree of their own. The tags page had no way to
     * reach a folder before this.
     */
    public function testTheTagsPageCarriesItToo(): void
    {
        $view = $this->moduleNavViewOn('/backend/ged/tags');

        self::assertNotNull($view);
        self::assertSame('ged/backend/documents/FolderTreePanel', $view['panelComponent']);
    }

    /**
     * A page outside the GED must not get the GED's column.
     *
     * The resolver picks the longest matching prefix, and `backend_ged_*` is a
     * narrow one - but a rule that only ever says yes has not been tested.
     */
    public function testAPageOutsideTheGedDoesNotGetItsPanel(): void
    {
        $view = $this->moduleNavViewOn('/backend/general/profile');

        self::assertNotSame('ged', $view['moduleId'] ?? null);
    }

    /**
     * The menu's props, read back off the served HTML.
     *
     * Decoded rather than grepped: Twig writes the payload as HTML-escaped JSON
     * with escaped slashes, so `ged/backend/...` is not in the response as
     * written, and a substring assertion for it fails while the feature works.
     *
     * @return ?array<string, mixed>
     */
    private function moduleNavViewOn(string $path): ?array
    {
        $this->client->request('GET', $path);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $matched = preg_match(
            '/vue-component-value="core\/backend\/sidemenu\/AppSidemenu" data-symfony--ux-vue--vue-props-value="([^"]*)"/',
            (string) $this->client->getResponse()->getContent(),
            $matches,
        );
        self::assertSame(1, $matched, 'The side menu was not mounted on '.$path);

        $props = json_decode(
            html_entity_decode($matches[1], ENT_QUOTES),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return $props['moduleNavView'] ?? null;
    }

    public function testTheFolderEndpointServesTheTreeThePanelDraws(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $parent = (new DocumentFolder())->setName('Contrats');
        $child = (new DocumentFolder())->setName('2026')->setParent($parent);
        $entityManager->persist($parent);
        $entityManager->persist($child);
        $entityManager->flush();

        $this->client->request('GET', '/backend/ged/documents/folders');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($payload['success']);

        $byName = [];
        foreach ($payload['folders'] as $folder) {
            $byName[$folder['name']] = $folder;
        }

        self::assertArrayHasKey('Contrats', $byName);
        self::assertArrayHasKey('2026', $byName);
        // The parent link is what makes it a tree rather than a list, and it is
        // the one field the panel cannot do without.
        self::assertSame($byName['Contrats']['id'], $byName['2026']['parentId']);
        self::assertArrayHasKey('documentCount', $byName['Contrats']);
    }
}
