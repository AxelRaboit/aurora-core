<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Deliverable;

use Aurora\Module\Dev\Audit\Entity\AuditLog;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Deliverable\Entity\Deliverable;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableCategory;
use Aurora\Module\Studio\Deliverable\Enum\DeliverableFormatEnum;
use Aurora\Module\Studio\Deliverable\Enum\DeliverableScopeEnum;
use Aurora\Module\Studio\Deliverable\Slides\Entity\SlideInterface;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function array_map;
use function basename;
use function bin2hex;
use function json_decode;
use function random_bytes;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * A text written elsewhere, imported as a presentation from the deliverables
 * list.
 *
 * The rule fits in one sentence: a heading opens a slide, each block that
 * follows fills one, and several blocks under the same heading make several
 * slides that repeat it. Checked end to end rather than on the converter
 * alone, because the slides go through the manager's whitelist: a slot the
 * layout does not declare would be lost on the way, silently.
 */
final class DeliverableImportTest extends IntegrationTestCase
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
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', Deliverable::class))->execute();
        $this->entityManager->createQuery(sprintf("DELETE FROM %s c WHERE c.name = 'Réunions importées'", DeliverableCategory::class))->execute();

        $this->entityManager->createQuery(sprintf("DELETE FROM %s a WHERE a.entityType = 'Deliverable'", AuditLog::class))->execute();

        foreach ($this->users as $id) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s u WHERE u.id = :id', User::class))->setParameter('id', $id)->execute();
        }

        parent::tearDown();
    }

    public function testATextBecomesAPresentationInTheChosenScopeAndCategory(): void
    {
        $this->signIn();
        $category = new DeliverableCategory();
        $category->setName('Réunions importées')->setPosition(1);
        $this->entityManager->persist($category);
        $this->entityManager->flush();

        $deliverable = $this->import([
            ['type' => 'header', 'data' => ['text' => 'Ce qui bloque', 'level' => 2]],
            ['type' => 'list', 'data' => ['items' => [['content' => 'Aucun cache'], ['content' => 'Requêtes N+1']]]],
        ], ['scope' => 'shared', 'categoryId' => $category->getId()]);

        self::assertSame(DeliverableFormatEnum::Slides, $deliverable->getFormat());
        self::assertSame(DeliverableScopeEnum::Shared, $deliverable->getScope());
        self::assertSame('Réunions importées', $deliverable->getCategory()?->getName());
        self::assertNull($deliverable->getSpace(), 'an import lands in Studio');

        $slides = $this->slides($deliverable);
        self::assertCount(1, $slides);
        self::assertSame('bullets', $slides[0]->getLayout()->value);
        self::assertSame('Ce qui bloque', $slides[0]->getContent()['title']);
        self::assertSame(['Aucun cache', 'Requêtes N+1'], $slides[0]->getContent()['bullets']);
    }

    /**
     * Several blocks under a heading make several slides that all carry it; a
     * heading with nothing under it becomes a section divider.
     */
    public function testSeveralBlocksRepeatTheirHeadingAndAnEmptyHeadingIsASection(): void
    {
        $this->signIn();

        $deliverable = $this->import([
            ['type' => 'header', 'data' => ['text' => 'Deuxième partie']],
            ['type' => 'header', 'data' => ['text' => 'Les mesures']],
            ['type' => 'list', 'data' => ['items' => ['Un', 'Deux']]],
            ['type' => 'table', 'data' => ['content' => [['Page', 'Avant'], ['Accueil', '2,4 s']]]],
            ['type' => 'paragraph', 'data' => ['text' => 'Le <b>catalogue</b> met cinq secondes.']],
            ['type' => 'paragraph', 'data' => ['text' => 'Le panier en met trois.']],
        ]);

        $slides = $this->slides($deliverable);
        self::assertSame(['section', 'bullets', 'table', 'bullets'], array_map(static fn (SlideInterface $slide): string => $slide->getLayout()->value, $slides));
        self::assertSame('Deuxième partie', $slides[0]->getContent()['title']);
        self::assertSame('Les mesures', $slides[2]->getContent()['title']);
        self::assertSame(['Page | Avant', 'Accueil | 2,4 s'], $slides[2]->getContent()['rows']);
        self::assertSame(['Le **catalogue** met cinq secondes.', 'Le panier en met trois.'], $slides[3]->getContent()['bullets']);
    }

    /** A text nothing can be drawn from is refused, and no empty deliverable is left behind. */
    public function testATextWithNothingUsableIsRefusedWithoutLeavingAnything(): void
    {
        $this->signIn();

        $this->client->jsonRequest('POST', '/suite/studio/deliverables/import', [
            'title' => 'Rien dedans',
            'blocks' => [['type' => 'delimiter', 'data' => []]],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('suite.studio.deliverables.errors.import_empty', $this->json()['errors']['blocks'] ?? null);
        self::assertSame(0, $this->entityManager->getRepository(Deliverable::class)->count(['title' => 'Rien dedans']));
    }

    public function testATitleIsRequired(): void
    {
        $this->signIn();

        $this->client->jsonRequest('POST', '/suite/studio/deliverables/import', [
            'title' => '  ',
            'blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Un point.']]],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('suite.studio.deliverables.errors.title_required', $this->json()['errors']['title'] ?? null);
    }

    /** Importing is creating: without the right to create, refused. */
    public function testImportingNeedsTheRightToCreate(): void
    {
        $reader = new User();
        $reader
            ->setEmail('import-'.bin2hex(random_bytes(5)).'@aurora.app')
            ->setName('Lecteur')
            ->setType(UserTypeEnum::Suite)
            ->setRoles([UserRoleEnum::User->value])
            ->setPassword('irrelevant')
            ->setPrivileges(['studio.deliverables.view']);
        $this->entityManager->persist($reader);
        $this->entityManager->flush();
        $this->users[] = (int) $reader->getId();
        $this->client->loginUser($reader, 'admin');

        $this->client->jsonRequest('POST', '/suite/studio/deliverables/import', [
            'title' => 'Sans droit',
            'blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Un point.']]],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @param array<string, mixed>       $extra
     */
    private function import(array $blocks, array $extra = []): Deliverable
    {
        $this->client->jsonRequest('POST', '/suite/studio/deliverables/import', [
            'title' => 'Depuis un texte',
            'blocks' => $blocks,
            ...$extra,
        ]);
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $deliverable = $this->entityManager->find(Deliverable::class, (int) basename((string) $this->json()['editPath']));
        self::assertInstanceOf(Deliverable::class, $deliverable);

        return $deliverable;
    }

    /** @return list<SlideInterface> */
    private function slides(Deliverable $deliverable): array
    {
        return $deliverable->getSlides()->getValues();
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function signIn(): void
    {
        $admin = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');
    }
}
