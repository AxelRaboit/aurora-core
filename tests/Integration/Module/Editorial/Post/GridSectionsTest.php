<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Entity\GridSection;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function array_column;
use function bin2hex;
use function json_decode;
use function json_encode;
use function random_bytes;
use function sprintf;

/**
 * The grid sections a person keeps: theirs to list, save and remove, and
 * nobody else's to see.
 */
final class GridSectionsTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    /** @var list<int> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->users as $id) {
            foreach ($this->entityManager->getRepository(GridSection::class)->findBy(['owner' => $id]) as $section) {
                $this->entityManager->remove($section);
            }

            $user = $this->entityManager->find(User::class, $id);
            if (null !== $user) {
                $this->entityManager->remove($user);
            }
        }

        $this->entityManager->flush();

        parent::tearDown();
    }

    public function testASavedSectionComesBackToItsAuthorNormalised(): void
    {
        $this->client->loginUser($this->account(), 'admin');

        $sections = $this->save('Mon bloc SWOT', [
            ['id' => 'a1', 'type' => 'text', 'surface' => 'raised', 'options' => ['valign' => 'sideways']],
            ['id' => 'a2', 'type' => 'no-such-type'],
        ], ['a1' => ['blocks' => [['type' => 'header', 'data' => ['text' => '[Forces]', 'level' => 3]]]]]);

        self::assertSame(['Mon bloc SWOT'], array_column($sections, 'name'));
        $zone = $sections[0]['zones'][0];
        self::assertSame('raised', $zone['surface']);
        // Through the grid normaliser: an unknown alignment falls back.
        self::assertSame('stretch', $zone['options']['valign']);
        self::assertSame('[Forces]', $sections[0]['content']['a1']['blocks'][0]['data']['text']);
    }

    public function testASectionIsItsAuthorsAlone(): void
    {
        $this->client->loginUser($this->account(), 'admin');
        $id = $this->save('À moi', [['id' => 'z', 'type' => 'text']], [])[0]['id'];

        $this->client->loginUser($this->account(), 'admin');
        $this->client->request('GET', '/suite/grid-sections');
        self::assertSame([], $this->json()['sections']);

        $this->client->request('POST', sprintf('/suite/grid-sections/%d/delete', $id));
        self::assertResponseStatusCodeSame(404);
    }

    public function testANamelessSectionIsRefused(): void
    {
        $this->client->loginUser($this->account(), 'admin');
        $this->post('/suite/grid-sections/create', ['name' => '  ', 'zones' => [['id' => 'z', 'type' => 'text']]]);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * @param list<array<string, mixed>> $zones
     * @param array<string, mixed>       $content
     *
     * @return list<array<string, mixed>>
     */
    private function save(string $name, array $zones, array $content): array
    {
        $this->post('/suite/grid-sections/create', ['name' => $name, 'zones' => $zones, 'content' => $content]);
        self::assertResponseIsSuccessful();

        return $this->json()['sections'];
    }

    /** @param array<string, mixed> $body */
    private function post(string $url, array $body): void
    {
        $this->client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], (string) json_encode($body));
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function account(): User
    {
        $user = new User();
        $user
            ->setEmail('sections-'.bin2hex(random_bytes(5)).'@aurora.app')
            ->setName('Auteur '.bin2hex(random_bytes(2)))
            ->setType(UserTypeEnum::Suite)
            ->setRoles([UserRoleEnum::User->value])
            ->setPassword('irrelevant');
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $this->users[] = (int) $user->getId();

        return $user;
    }
}
