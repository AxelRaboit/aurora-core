<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Deliverable;

use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\CustomerSpace\Manager\CustomerSpaceManagerInterface;
use Aurora\Module\Studio\Deliverable\Entity\Deliverable;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableLink;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLink;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceAccess\Manager\SpaceAccessLinkManagerInterface;
use Aurora\Tests\Integration\Concern\ResetsRateLimiters;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function bin2hex;
use function json_decode;
use function parse_url;
use function random_bytes;
use function sprintf;

use const PHP_URL_PATH;

/**
 * Les livrables d'un espace client : des pages composées pour un client, à
 * part des publications du site.
 *
 * Quatre promesses sont vérifiées ici. Un livrable naît caché, préparé pour
 * le client par son nom, et toujours en grille. Ses couleurs ne gardent que
 * des couleurs : elles finissent dans un `<style>` servi au client. Le client
 * ne lit que ce qu'on lui a ouvert, et jamais le livrable d'un autre espace.
 * Et un lien de lecture ouvre le livrable tant qu'il n'est ni coupé ni
 * fermé par son mot de passe.
 */
final class SpaceDeliverablesTest extends IntegrationTestCase
{
    use ResetsRateLimiters;

    private KernelBrowser $client;

    /** @var list<int> */
    private array $documents = [];

    private EntityManagerInterface $entityManager;

    private string $suffix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->suffix = bin2hex(random_bytes(4));

        $admin = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');

        $this->resetRateLimiter('deliverable_password');
    }

    protected function tearDown(): void
    {
        foreach ([DeliverableLink::class, Deliverable::class, SpaceAccessLink::class, CustomerSpace::class, Customer::class] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s', $class))->execute();
        }

        foreach ($this->documents as $id) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s d WHERE d.id = :id', Document::class))->setParameter('id', $id)->execute();
        }

        parent::tearDown();
    }

    public function testADeliverableIsBornHiddenPreparedForTheClientAndInAGrid(): void
    {
        $space = $this->givenSpace();

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/deliverables/create', $space->getId()), [
            'title' => 'Audit '.$this->suffix,
        ]);
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertCount(1, $data['deliverables']);
        self::assertStringContainsString(sprintf('/workspace/%d/deliverables/', $space->getId()), $data['editPath']);

        $deliverable = $this->find((int) $data['deliverables'][0]['id']);
        self::assertFalse($deliverable->isVisibleToClient());
        self::assertSame('Client Boulangerie', $deliverable->getReadingHeader()['preparedFor']);
        self::assertTrue($deliverable->getGridLayout()['enabled']);

        // L'éditeur s'ouvre, sur la page même du livrable.
        $this->client->request('GET', $data['editPath']);
        self::assertResponseIsSuccessful();
    }

    public function testADeliverableNeedsATitle(): void
    {
        $space = $this->givenSpace();

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/deliverables/create', $space->getId()), ['title' => '  ']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->entityManager->getRepository(Deliverable::class)->findAll());
    }

    /**
     * Une couleur qui n'en est pas une n'est jamais gardée.
     *
     * Les couleurs d'un livrable sont écrites telles quelles dans la feuille
     * de style de sa page : ce que la validation laisse passer, le client le
     * reçoit dans un `<style>`.
     */
    public function testTheAppearanceKeepsOnlyColours(): void
    {
        $space = $this->givenSpace();
        $deliverable = $this->givenDeliverable($space, 'Bilan '.$this->suffix);

        $this->update($space, $deliverable, [
            'appearance' => [
                'backgroundColor' => '#fbf7f1',
                'headerColor' => 'red;}</style><script>alert(1)</script>',
                'accentColor' => 'javascript:alert(1)',
                'highlight' => 'custom',
                'highlightColor' => null,
                'titleVisible' => false,
            ],
            // Une grille éteinte rendrait une page vide : elle reste allumée.
            'gridLayout' => ['enabled' => false],
        ]);

        $saved = $this->find($deliverable);
        $appearance = $saved->getAppearance();
        self::assertSame('#fbf7f1', $appearance['backgroundColor']);
        self::assertNull($appearance['headerColor']);
        self::assertNull($appearance['accentColor']);
        self::assertNull($appearance['highlight'], 'Personnalisé sans couleur revient au thème.');
        self::assertFalse($appearance['titleVisible']);
        self::assertTrue($saved->getGridLayout()['enabled']);

        $this->client->request('GET', sprintf('/workspace/%d/deliverables/%d/preview', $space->getId(), $deliverable));
        self::assertResponseIsSuccessful();
        $page = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('#fbf7f1', $page);
        self::assertStringNotContainsString('alert(1)', $page);
    }

    /** Bold capitals for the section headings, or the theme's; nothing else passes. */
    public function testTheHeadingStyleIsOneOfTwoAndReachesThePage(): void
    {
        $space = $this->givenSpace();
        $deliverable = $this->givenDeliverable($space, 'Audit '.$this->suffix);

        $this->update($space, $deliverable, ['appearance' => ['headingStyle' => 'display']]);
        self::assertSame('display', $this->find($deliverable)->getAppearance()['headingStyle']);

        $this->client->request('GET', sprintf('/workspace/%d/deliverables/%d/preview', $space->getId(), $deliverable));
        self::assertStringContainsString('aurora-headings-display', (string) $this->client->getResponse()->getContent());

        $this->update($space, $deliverable, ['appearance' => ['headingStyle' => 'comic-sans']]);
        self::assertSame('theme', $this->find($deliverable)->getAppearance()['headingStyle']);
    }

    public function testAnEmptyTitleIsRefusedOnSave(): void
    {
        $space = $this->givenSpace();
        $deliverable = $this->givenDeliverable($space, 'Bilan '.$this->suffix);

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/deliverables/%d/update', $space->getId(), $deliverable), [
            ...$this->payload($deliverable),
            'title' => '',
        ]);

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('title', $data['errors']);
    }

    /** La case décide, dans la liste comme à l'adresse du livrable. */
    public function testTheClientReadsWhatWasOpenedToThemAndNothingElse(): void
    {
        $space = $this->givenSpace();
        $open = $this->givenDeliverable($space, 'Audit ouvert '.$this->suffix);
        $closed = $this->givenDeliverable($space, 'Strategie fermee '.$this->suffix);

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/deliverables/%d/visibility', $space->getId(), $open), ['visible' => true]);
        self::assertResponseIsSuccessful();

        $link = $this->givenAccessLink($space);
        $base = sprintf('/spaces/%s/%s', $link->getSelector(), (string) $link->getPlainToken());

        $this->client->request('GET', $base);
        self::assertResponseIsSuccessful();
        $page = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Audit ouvert '.$this->suffix, $page);
        self::assertStringNotContainsString('Strategie fermee '.$this->suffix, $page);

        $this->client->request('GET', sprintf('%s/deliverables/%d', $base, $open));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('noindex', (string) $this->client->getResponse()->headers->get('X-Robots-Tag'));

        $this->client->request('GET', sprintf('%s/deliverables/%d', $base, $closed));
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Le livrable d'un autre espace n'existe pas sous celui-ci, ni côté
     * studio ni côté client.
     */
    /**
     * Le client voit l'image d'un livrable quand elle est publiée dans la
     * médiathèque ; une image privée ne lui est pas envoyée, elle ne
     * s'afficherait pas chez lui.
     */
    public function testTheClientSeesAPublishedImageOnly(): void
    {
        $space = $this->givenSpace();
        $withPublished = $this->givenDeliverable($space, 'Audit illustre '.$this->suffix);
        $withPrivate = $this->givenDeliverable($space, 'Strategie illustree '.$this->suffix);
        $published = $this->givenImage('publie-'.$this->suffix.'.jpg', DocumentStatusEnum::Published);
        $private = $this->givenImage('prive-'.$this->suffix.'.jpg', DocumentStatusEnum::Draft);

        $this->update($space, $withPublished, ['thumbnailId' => $published, 'visibleToClient' => true]);
        $this->update($space, $withPrivate, ['thumbnailId' => $private, 'visibleToClient' => true]);
        self::assertSame($private, $this->find($withPrivate)->getThumbnail()?->getId(), 'the studio keeps a private image');

        $link = $this->givenAccessLink($space);
        $this->client->request('GET', sprintf('/spaces/%s/%s', $link->getSelector(), (string) $link->getPlainToken()));
        self::assertResponseIsSuccessful();
        $page = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('publie-'.$this->suffix.'.jpg', $page);
        self::assertStringNotContainsString('prive-'.$this->suffix.'.jpg', $page);
    }

    public function testADeliverableOfAnotherSpaceIsNotFoundUnderThisOne(): void
    {
        $mine = $this->givenSpace();
        $theirs = $this->givenSpace('Espace du voisin', 'voisin@example.test');
        $foreign = $this->givenDeliverable($theirs, 'Audit du voisin '.$this->suffix);

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/deliverables/%d/visibility', $theirs->getId(), $foreign), ['visible' => true]);
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/deliverables/%d/delete', $mine->getId(), $foreign));
        self::assertResponseStatusCodeSame(404);

        $link = $this->givenAccessLink($mine);
        $this->client->request('GET', sprintf('/spaces/%s/%s/deliverables/%d', $link->getSelector(), (string) $link->getPlainToken(), $foreign));
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Un lien de lecture est un envoi à part : il ouvre le livrable même
     * caché dans l'espace, et plus rien une fois coupé.
     */
    public function testAReadingLinkOpensTheDeliverableUntilItIsRevoked(): void
    {
        $space = $this->givenSpace();
        $deliverable = $this->givenDeliverable($space, 'Proposition '.$this->suffix);

        $links = $this->createReadingLink($space, $deliverable, ['label' => 'Pour Jean']);
        $path = (string) parse_url($links['links'][0]['url'], PHP_URL_PATH);

        $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Proposition '.$this->suffix, (string) $this->client->getResponse()->getContent());

        $this->client->jsonRequest('POST', sprintf(
            '/workspace/%d/deliverables/%d/links/%d/revoke',
            $space->getId(),
            $deliverable,
            $links['links'][0]['id'],
        ));
        self::assertResponseIsSuccessful();

        $this->client->request('GET', $path);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAPasswordKeepsTheDeliverableClosedUntilItIsGiven(): void
    {
        $space = $this->givenSpace();
        $deliverable = $this->givenDeliverable($space, 'Proposition '.$this->suffix);

        // Tirés au hasard : une phrase écrite en dur passe pour un secret
        // aux yeux de l'analyse des secrets de la CI.
        $secret = bin2hex(random_bytes(6));
        $wrong = bin2hex(random_bytes(6));

        $links = $this->createReadingLink($space, $deliverable, ['label' => 'Pour Jean', 'password' => $secret]);
        $path = (string) parse_url($links['links'][0]['url'], PHP_URL_PATH);

        $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Proposition '.$this->suffix, (string) $this->client->getResponse()->getContent());

        $this->client->request('POST', $path.'/unlock', ['password' => $wrong]);
        self::assertStringNotContainsString('Proposition '.$this->suffix, (string) $this->client->getResponse()->getContent());

        $this->client->request('POST', $path.'/unlock', ['password' => $secret]);
        self::assertResponseRedirects($path);

        $this->client->request('GET', $path);
        self::assertStringContainsString('Proposition '.$this->suffix, (string) $this->client->getResponse()->getContent());
    }

    /** Une copie se reprend avant d'être montrée, et ne part pas avec les liens. */
    public function testACopyIsHiddenAndCarriesNoLink(): void
    {
        $space = $this->givenSpace();
        $deliverable = $this->givenDeliverable($space, 'Audit '.$this->suffix);
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/deliverables/%d/visibility', $space->getId(), $deliverable), ['visible' => true]);
        $this->createReadingLink($space, $deliverable, ['label' => 'Pour Marie']);

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/deliverables/%d/duplicate', $space->getId(), $deliverable));
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertCount(2, $data['deliverables']);

        $copy = null;
        foreach ($data['deliverables'] as $row) {
            if ($row['id'] !== $deliverable) {
                $copy = $this->find((int) $row['id']);
            }
        }

        self::assertInstanceOf(Deliverable::class, $copy);
        self::assertSame('Audit '.$this->suffix.' (copie)', $copy->getTitle());
        self::assertFalse($copy->isVisibleToClient());
        self::assertSame([], $this->entityManager->getRepository(DeliverableLink::class)->findBy(['deliverable' => $copy]));
    }

    /** Un livrable n'a de sens que pour son client : il part avec l'espace. */
    public function testDeletingTheSpaceDeletesItsDeliverables(): void
    {
        $space = $this->givenSpace();
        $deliverable = $this->givenDeliverable($space, 'Audit '.$this->suffix);
        $this->createReadingLink($space, $deliverable, ['label' => 'Pour Marie']);

        $live = $this->entityManager->getRepository(CustomerSpace::class)->find($space->getId());
        self::assertInstanceOf(CustomerSpace::class, $live);
        self::getContainer()->get(CustomerSpaceManagerInterface::class)->delete($live);

        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Deliverable::class, $deliverable));
        self::assertSame([], $this->entityManager->getRepository(DeliverableLink::class)->findAll());
    }

    private function givenSpace(string $name = 'Boulangerie', string $email = 'boulangerie@example.test'): CustomerSpace
    {
        $customer = new Customer();
        $customer->setLegalName('Client '.$name)->setContractualEmail($email);

        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', '/backend/studio/spaces/create', [
            'name' => $name,
            'customerId' => $customer->getId(),
            'timezone' => 'Europe/Paris',
        ]);
        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        $space = $this->entityManager->getRepository(CustomerSpace::class)->find($payload['space']['id']);
        self::assertInstanceOf(CustomerSpace::class, $space);

        return $space;
    }

    private function givenDeliverable(CustomerSpace $space, string $title): int
    {
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/deliverables/create', $space->getId()), ['title' => $title]);
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        foreach ($data['deliverables'] as $row) {
            if ($row['title'] === $title) {
                return (int) $row['id'];
            }
        }

        self::fail(sprintf('Le livrable « %s » n\'est pas revenu dans la liste.', $title));
    }

    private function givenImage(string $fileName, DocumentStatusEnum $status): int
    {
        $document = new Document();
        $document->setTitle($fileName)->setFilePath('ged/2026/10/'.$fileName)->setFileName($fileName)->setOriginalName($fileName)->setMimeType('image/jpeg')->setSize(1024)->setStatus($status);
        $this->entityManager->persist($document);
        $this->entityManager->flush();
        $this->documents[] = (int) $document->getId();

        return (int) $document->getId();
    }

    private function givenAccessLink(CustomerSpace $space): SpaceAccessLinkInterface
    {
        $fresh = $this->entityManager->getRepository(CustomerSpace::class)->find($space->getId());
        self::assertInstanceOf(CustomerSpace::class, $fresh);

        return self::getContainer()->get(SpaceAccessLinkManagerInterface::class)
            ->issue($fresh, 'client@example.test', 'Le client', 30, true, true);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function createReadingLink(CustomerSpace $space, int $deliverable, array $payload): array
    {
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/deliverables/%d/links/create', $space->getId(), $deliverable), $payload);
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /** @param array<string, mixed> $changes */
    private function update(CustomerSpace $space, int $deliverable, array $changes): void
    {
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/deliverables/%d/update', $space->getId(), $deliverable), [
            ...$this->payload($deliverable),
            ...$changes,
        ]);
        self::assertResponseIsSuccessful();
    }

    /** @return array<string, mixed> ce que l'éditeur enverrait, sans rien changer */
    private function payload(int $deliverable): array
    {
        $entity = $this->find($deliverable);

        return [
            'title' => $entity->getTitle(),
            'summary' => $entity->getSummary(),
            'locale' => $entity->getLocale(),
            'gridLayout' => $entity->getGridLayout(),
            'gridContent' => $entity->getGridContent(),
            'appearance' => $entity->getAppearance(),
            'readingHeader' => $entity->getReadingHeader(),
            'visibleToClient' => $entity->isVisibleToClient(),
        ];
    }

    private function find(int $id): Deliverable
    {
        $this->entityManager->clear();
        $deliverable = $this->entityManager->find(Deliverable::class, $id);
        self::assertInstanceOf(Deliverable::class, $deliverable);

        return $deliverable;
    }
}
