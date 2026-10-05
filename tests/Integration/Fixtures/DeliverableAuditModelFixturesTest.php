<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Fixtures;

use Aurora\Fixtures\Studio\DeliverableDemoFixtures;
use Aurora\Module\Editorial\Post\Grid\GridNormalizer;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\CustomerSpace\Repository\CustomerSpaceRepository;
use Aurora\Module\Studio\Deliverable\Entity\Deliverable;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableCategory;
use Aurora\Module\Studio\Deliverable\Repository\DeliverableCategoryRepository;
use Aurora\Module\Studio\Deliverable\Repository\DeliverableRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use ReflectionMethod;

use function json_encode;
use function sprintf;

/**
 * The two audit models the demo ships, as the fixtures build them.
 *
 * They are the fullest thing the grid does for a deliverable, and the tour's
 * captures point at them: a fixture that did not build them, or that left an
 * image pointing at a document id from somebody else's database, would show up
 * only as a 404 or a broken picture on a fresh demo. Their pictures are named,
 * not numbered, because ids change at every load.
 */
final class DeliverableAuditModelFixturesTest extends IntegrationTestCase
{
    private EntityManagerInterface $entityManager;

    /** @var list<int> */
    private array $documents = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', Deliverable::class))->execute();
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', DeliverableCategory::class))->execute();
        foreach ($this->documents as $id) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s d WHERE d.id = :id', Document::class))->setParameter('id', $id)->execute();
        }

        parent::tearDown();
    }

    public function testBothModelsAreBuiltSharedAndFiledUnderAudits(): void
    {
        $category = $this->category('Audits');
        $this->loadModels($category);

        $model = $this->deliverable('Modèle · Audit des réseaux sociaux');
        $presentation = $this->deliverable('Audit en présentation');

        foreach ([$model, $presentation] as $deliverable) {
            self::assertNull($deliverable->getSpace());
            self::assertSame('shared', $deliverable->getScope()->value);
            self::assertFalse($deliverable->isVisibleToClient());
            self::assertSame($category->getId(), $deliverable->getCategory()?->getId());
            self::assertGreaterThan(40, count($deliverable->getGridLayout()['zones']));
        }

        // The two looks the tour needs: a white page, and a presentation in the site's colours.
        self::assertSame('page', $model->getAppearance()['display']);
        self::assertSame('#ffffff', $model->getAppearance()['backgroundColor']);
        self::assertSame('slides', $presentation->getAppearance()['display']);
        self::assertSame('#130918', $presentation->getAppearance()['backgroundColor']);
    }

    public function testPicturesAreFoundByNameNotByTheNumberTheyHadWhenExported(): void
    {
        $photo = $this->document('Un appareil photo');
        $this->loadModels($this->category('Audits'));

        $ids = $this->mediaIds($this->deliverable('Modèle · Audit des réseaux sociaux'));

        // The named picture is wired to the id this database gave it; the ones this
        // database does not have are left empty rather than pointing at a stranger.
        self::assertContains($photo, $ids);
        foreach ($ids as $id) {
            self::assertNotNull($this->entityManager->find(Document::class, $id), sprintf('Document #%d does not exist.', $id));
        }
    }

    public function testReloadingLeavesThemAloneAndFilesTheUnfiledOnes(): void
    {
        $this->loadModels(null);
        self::assertNull($this->deliverable('Audit en présentation')->getCategory());

        $category = $this->category('Audits');
        $this->loadModels($category);

        $this->entityManager->clear();
        self::assertCount(1, $this->entityManager->getRepository(Deliverable::class)->findBy(['title' => 'Audit en présentation']));
        // Filed on the reload: a category added after the first load used to file nothing.
        self::assertSame($category->getId(), $this->deliverable('Audit en présentation')->getCategory()?->getId());

        // And a hand-made filing is not undone by the next reload.
        $otherId = $this->category('Autres')->getId();
        $presentation = $this->deliverable('Audit en présentation');
        $presentation->setCategory($this->entityManager->getReference(DeliverableCategory::class, $otherId));
        $this->entityManager->flush();
        $this->loadModels($category);
        self::assertSame($otherId, $this->deliverable('Audit en présentation')->getCategory()?->getId());
    }

    private function loadModels(?DeliverableCategory $category): void
    {
        // Each call clears the unit of work: a category kept from before is
        // detached, so it is picked up again by its id.
        $category = $category instanceof DeliverableCategory ? $this->entityManager->getReference(DeliverableCategory::class, $category->getId()) : null;

        $container = self::getContainer();
        $fixtures = new DeliverableDemoFixtures(
            $container->get(CustomerSpaceRepository::class),
            $container->get(DeliverableRepository::class),
            $container->get(GridNormalizer::class),
            $container->get(UserRepository::class),
            $container->get(DeliverableCategoryRepository::class),
            $container->get(DocumentRepository::class),
        );

        $model = new ReflectionMethod($fixtures, 'model');
        foreach (['deliverable-audit-model.json', 'deliverable-audit-presentation.json'] as $file) {
            // The fixtures file only ever files a model into a category it has: here it is
            // handed one directly, or none for the unfiled case.
            $model->invoke($fixtures, $this->entityManager, $file, null, $category ?? $this->category('__unfiled__'));
        }

        $this->entityManager->flush();

        if (null === $category) {
            // Simulates the first load before any category existed.
            $this->entityManager->createQuery(sprintf('UPDATE %s d SET d.category = NULL', Deliverable::class))->execute();
        }

        $this->entityManager->clear();
    }

    private function category(string $name): DeliverableCategory
    {
        $existing = $this->entityManager->getRepository(DeliverableCategory::class)->findOneBy(['name' => $name]);
        if ($existing instanceof DeliverableCategory) {
            return $existing;
        }

        $category = new DeliverableCategory();
        $category->setName($name)->setColor('#bd4a55')->setPosition(1);
        $this->entityManager->persist($category);
        $this->entityManager->flush();

        return $category;
    }

    private function document(string $originalName): int
    {
        $document = new Document();
        $document
            ->setTitle($originalName)->setFilePath('ged/2026/10/'.$originalName)->setFileName($originalName)->setOriginalName($originalName)
            ->setMimeType('image/jpeg')->setSize(1)->setStatus(DocumentStatusEnum::Published);
        $this->entityManager->persist($document);
        $this->entityManager->flush();
        $this->documents[] = (int) $document->getId();

        return (int) $document->getId();
    }

    private function deliverable(string $title): Deliverable
    {
        $this->entityManager->clear();
        $found = $this->entityManager->getRepository(Deliverable::class)->findOneBy(['title' => $title]);
        self::assertInstanceOf(Deliverable::class, $found, sprintf('« %s » was not built.', $title));

        return $found;
    }

    /** @return list<int> every picture id the layout holds, wherever a zone keeps one */
    private function mediaIds(Deliverable $deliverable): array
    {
        $json = (string) json_encode($deliverable->getGridLayout());
        preg_match_all('/"(?:mediaId|videoId)":(\d+)/', $json, $matches);

        return array_map('intval', array_values(array_unique($matches[1])));
    }
}
