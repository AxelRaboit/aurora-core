<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Contract;

use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Studio\Contract\Dto\ContractTemplateInput;
use Aurora\Module\Studio\Contract\Dto\ContractTemplateVersionInput;
use Aurora\Module\Studio\Contract\Entity\ContractTemplate;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateInterface;
use Aurora\Module\Studio\Contract\Enum\ContractTemplateKindEnum;
use Aurora\Module\Studio\Contract\Exception\PublishedVersionIsImmutableException;
use Aurora\Module\Studio\Contract\Manager\ContractTemplateManager;
use Aurora\Module\Studio\Contract\Manager\ContractTemplateManagerInterface;
use Aurora\Module\Studio\Contract\Repository\ContractRepository;
use Aurora\Module\Studio\Contract\Repository\ContractTemplateRepository;
use Aurora\Module\Studio\Contract\Repository\ContractTemplateVersionRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function sprintf;

/**
 * The versioning rules, against a real database.
 *
 * The unit tests next to the entity prove it refuses writes. These prove the
 * workflow the refusal exists to support: that published wording is edited by
 * opening a new version seeded from the one in force, that only one version is
 * ever open, and that a number is never handed out twice.
 */
final class ContractTemplateVersioningTest extends IntegrationTestCase
{
    private ContractTemplateManagerInterface $manager;

    private ContractTemplateRepository $templates;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        // No HTTP client here: these tests exercise the manager, and asking
        // for the container is what boots the kernel for them.
        $container = static::getContainer();

        $this->templates = $container->get(ContractTemplateRepository::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);

        // Built here rather than fetched. The manager has no consumer yet -
        // its controller lands with the editor - so the container removes it
        // as an unused private service. Its dependencies are real, and the
        // database behind them is real, which is what these tests are about;
        // the day a route injects it, this becomes a plain container lookup.
        $this->manager = new ContractTemplateManager(
            $this->entityManager,
            $container->get(AuditLogger::class),
            $container->get(ContractTemplateVersionRepository::class),
            $container->get(TranslatorInterface::class),
            $container->get(ContractRepository::class),
        );
    }

    protected function tearDown(): void
    {
        $this->entityManager->createQuery(
            sprintf('DELETE FROM %s', ContractTemplate::class),
        )->execute();

        parent::tearDown();
    }

    public function testANewTemplateArrivesWithItsFirstDraftOpen(): void
    {
        $template = $this->createTemplate('Contrat mensuel');

        self::assertNotNull($template->getDraft());
        self::assertSame(1, $template->getDraft()?->getNumber());
        // Nothing is publishable yet, which is the honest state of a template
        // nobody has written into.
        self::assertNull($template->getLatestPublishedVersion());
    }

    public function testPublishedWordingIsEditedByOpeningTheNextVersion(): void
    {
        $template = $this->createTemplate('Contrat mensuel');
        $first = $template->getDraft();

        $this->manager->updateDraft($first, new ContractTemplateVersionInput([
            'fr' => ['title' => 'Contrat de prestation de services', 'content' => ['blocks' => ['article 1']]],
        ]));
        $this->manager->publish($first);

        $second = $this->manager->openDraft($template);

        self::assertSame(2, $second->getNumber());
        // Seeded, not blank: opening a draft to amend one clause must not
        // start from an empty document.
        self::assertSame('Contrat de prestation de services', $second->getTranslation('fr')?->getTitle());
        self::assertSame(['blocks' => ['article 1']], $second->getTranslation('fr')?->getContent());

        // And the published one is untouched by the editing of its successor.
        $this->manager->updateDraft($second, new ContractTemplateVersionInput([
            'fr' => ['title' => 'Contrat de prestation de services', 'content' => ['blocks' => ['article 1 amendé']]],
        ]));

        self::assertSame(['blocks' => ['article 1']], $first->getTranslation('fr')?->getContent());
        self::assertSame(['blocks' => ['article 1 amendé']], $second->getTranslation('fr')?->getContent());
    }

    public function testOnlyOneDraftCanBeOpenAtATime(): void
    {
        $template = $this->createTemplate('Contrat mensuel');

        try {
            $this->manager->openDraft($template);
            self::fail('A second draft should have been refused.');
        } catch (FieldException $exception) {
            self::assertSame('draft', $exception->getField());
            // The message names the version already open, so the reader knows
            // what to finish rather than only that they cannot proceed.
            self::assertStringContainsString('version 1', $exception->getMessage());
            self::assertNotNull($template->getDraft());
        }
    }

    public function testANumberIsNeverHandedOutTwice(): void
    {
        $template = $this->createTemplate('Contrat mensuel');
        $first = $template->getDraft();

        $this->manager->updateDraft($first, new ContractTemplateVersionInput([
            'fr' => ['title' => 'Contrat', 'content' => []],
        ]));
        $this->manager->publish($first);

        $second = $this->manager->openDraft($template);
        $this->manager->discardDraft($second);

        $third = $this->manager->openDraft($template);

        // 3, not 2: "version 2" was quoted while it existed, and the name has
        // to mean one document over the life of the template.
        self::assertSame(3, $third->getNumber());
    }

    public function testAPublishedVersionCannotBeDiscarded(): void
    {
        $template = $this->createTemplate('Contrat mensuel');
        $version = $template->getDraft();

        $this->manager->updateDraft($version, new ContractTemplateVersionInput([
            'fr' => ['title' => 'Contrat', 'content' => []],
        ]));
        $this->manager->publish($version);

        $this->expectException(PublishedVersionIsImmutableException::class);

        $this->manager->discardDraft($version);
    }

    public function testAnEmptyDraftCannotBePublished(): void
    {
        $template = $this->createTemplate('Contrat mensuel');

        try {
            $this->manager->publish($template->getDraft());
            self::fail('Publishing wording that does not exist should have been refused.');
        } catch (FieldException $exception) {
            self::assertSame('translations', $exception->getField());
        }
    }

    public function testALanguageTheEditorStopsSendingIsDropped(): void
    {
        $template = $this->createTemplate('Contrat mensuel');
        $version = $template->getDraft();

        $this->manager->updateDraft($version, new ContractTemplateVersionInput([
            'fr' => ['title' => 'Contrat', 'content' => []],
            'en' => ['title' => 'Contract', 'content' => []],
            'es' => ['title' => 'Contrato', 'content' => []],
        ]));

        self::assertCount(3, $version->getTranslations());

        // Two languages dropped in one save: the removal has to survive being
        // done while walking the collection.
        $this->manager->updateDraft($version, new ContractTemplateVersionInput([
            'fr' => ['title' => 'Contrat', 'content' => []],
        ]));

        self::assertCount(1, $version->getTranslations());
        self::assertNotNull($version->getTranslation('fr'));
        self::assertNull($version->getTranslation('en'));
        self::assertNull($version->getTranslation('es'));
    }

    public function testOnlyPublishedAndLiveTemplatesAreSelectable(): void
    {
        $draftOnly = $this->createTemplate('Trame jamais publiée');

        $published = $this->createTemplate('Contrat mensuel');
        $this->manager->updateDraft($published->getDraft(), new ContractTemplateVersionInput([
            'fr' => ['title' => 'Contrat', 'content' => []],
        ]));
        $this->manager->publish($published->getDraft());

        $archived = $this->createTemplate('Ancien contrat');
        $this->manager->updateDraft($archived->getDraft(), new ContractTemplateVersionInput([
            'fr' => ['title' => 'Ancien', 'content' => []],
        ]));
        $this->manager->publish($archived->getDraft());
        $this->manager->archive($archived);

        $selectable = $this->templates->findSelectable(ContractTemplateKindEnum::Body);
        $names = array_map(static fn (ContractTemplateInterface $t): string => $t->getName(), $selectable);

        self::assertContains('Contrat mensuel', $names);
        // A trame nobody published cannot be sent, and an archived one was
        // retired on purpose. Offering either means offering a dead end.
        self::assertNotContains($draftOnly->getName(), $names);
        self::assertNotContains($archived->getName(), $names);
    }

    private function createTemplate(string $name): ContractTemplateInterface
    {
        return $this->manager->create(new ContractTemplateInput(
            name: $name,
            kind: ContractTemplateKindEnum::Body,
        ));
    }
}
