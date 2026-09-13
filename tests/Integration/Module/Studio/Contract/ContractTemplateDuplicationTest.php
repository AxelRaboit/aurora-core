<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Contract;

use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Studio\Contract\Dto\ContractTemplateInput;
use Aurora\Module\Studio\Contract\Dto\ContractTemplateVersionInput;
use Aurora\Module\Studio\Contract\Duplicate\ContractTemplateDuplicator;
use Aurora\Module\Studio\Contract\Entity\ContractTemplate;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateInterface;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateVersionInterface;
use Aurora\Module\Studio\Contract\Enum\ContractTemplateKindEnum;
use Aurora\Module\Studio\Contract\Manager\ContractTemplateManager;
use Aurora\Module\Studio\Contract\Repository\ContractRepository;
use Aurora\Module\Studio\Contract\Repository\ContractTemplateVersionRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function sprintf;

/**
 * Duplicating a trame.
 *
 * The interesting half is what a copy must *not* inherit. A trame that copied
 * its publication would be immediately usable for a contract nobody has read,
 * and one that copied its version numbers would claim a history it does not
 * have.
 */
final class ContractTemplateDuplicationTest extends IntegrationTestCase
{
    private ContractTemplateDuplicator $duplicator;

    private ContractTemplateManager $templates;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);

        $this->templates = new ContractTemplateManager(
            $this->entityManager,
            $container->get(AuditLogger::class),
            $container->get(ContractTemplateVersionRepository::class),
            $container->get(TranslatorInterface::class),
            $container->get(ContractRepository::class),
        );

        $this->duplicator = new ContractTemplateDuplicator(
            $this->templates,
            $container->get(TranslatorInterface::class),
        );
    }

    protected function tearDown(): void
    {
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', ContractTemplate::class))->execute();

        parent::tearDown();
    }

    public function testTheCopyCarriesTheWordingInForce(): void
    {
        $source = $this->publishedTemplate();

        $copy = $this->duplicator->duplicate($source);

        $draft = $copy->getDraft();

        self::assertInstanceOf(ContractTemplateVersionInterface::class, $draft);
        self::assertSame(
            'CONTRAT DE PRESTATION DE SERVICES',
            $draft->getTranslation('fr')?->getTitle(),
        );
        self::assertSame(
            'Le prestataire fournit les services convenus.',
            $draft->getTranslation('fr')?->getContent()['blocks'][1]['data']['text'] ?? null,
        );
        // Every language, and the clause that arbitrates between them.
        self::assertSame(['fr', 'en'], array_keys($draft->getTranslations()->toArray()));
        self::assertSame('fr', $draft->getGoverningLocale());
    }

    /**
     * The point of the class. A copy that published itself would be usable for
     * a contract the moment it appeared.
     */
    public function testTheCopyIsNeverPublished(): void
    {
        $copy = $this->duplicator->duplicate($this->publishedTemplate());

        self::assertNull($copy->getLatestPublishedVersion());
        self::assertNotNull($copy->getDraft());
        self::assertFalse($copy->getDraft()->isPublished());
    }

    /** The numbers describe the original's life, not the copy's. */
    public function testTheCopyStartsAtVersionOne(): void
    {
        $source = $this->publishedTemplate();
        $this->templates->openDraft($source);

        $copy = $this->duplicator->duplicate($source);

        self::assertSame(1, $copy->getDraft()?->getNumber());
    }

    /** Two rows with one name is a list where somebody edits the wrong one. */
    public function testTheCopyIsNamedApart(): void
    {
        $source = $this->publishedTemplate();

        $copy = $this->duplicator->duplicate($source);

        self::assertNotSame($source->getName(), $copy->getName());
        self::assertStringStartsWith($source->getName(), $copy->getName());
    }

    public function testTheCopyKeepsTheKind(): void
    {
        $source = $this->publishedTemplate(ContractTemplateKindEnum::Annex);

        $copy = $this->duplicator->duplicate($source);

        self::assertSame(ContractTemplateKindEnum::Annex, $copy->getKind());
    }

    /**
     * Duplicating an archived trame is how its wording is revived, so the copy
     * has to start live or the gesture achieves nothing.
     */
    public function testTheCopyOfAnArchivedTrameIsLive(): void
    {
        $source = $this->publishedTemplate();
        $this->templates->archive($source);

        $copy = $this->duplicator->duplicate($source);

        self::assertTrue($source->isArchived());
        self::assertFalse($copy->isArchived());
    }

    /** Nothing published yet: the open draft is what "this trame" means. */
    public function testAnUnpublishedTrameIsCopiedFromItsDraft(): void
    {
        $template = $this->templates->create(new ContractTemplateInput('Contrat mensuel', ContractTemplateKindEnum::Body));
        $draft = $template->getDraft();

        self::assertInstanceOf(ContractTemplateVersionInterface::class, $draft);

        $this->templates->updateDraft($draft, new ContractTemplateVersionInput([
            'fr' => ['title' => 'BROUILLON', 'content' => ['blocks' => []]],
        ]));

        $copy = $this->duplicator->duplicate($template);

        self::assertSame('BROUILLON', $copy->getDraft()?->getTranslation('fr')?->getTitle());
    }

    private function publishedTemplate(ContractTemplateKindEnum $kind = ContractTemplateKindEnum::Body): ContractTemplateInterface
    {
        $template = $this->templates->create(new ContractTemplateInput('Contrat mensuel', $kind));
        $version = $template->getDraft();

        self::assertInstanceOf(ContractTemplateVersionInterface::class, $version);

        $this->templates->updateDraft($version, new ContractTemplateVersionInput(
            translations: [
                'fr' => [
                    'title' => 'CONTRAT DE PRESTATION DE SERVICES',
                    'content' => ['blocks' => [
                        ['type' => 'header', 'data' => ['text' => 'ARTICLE 1', 'level' => 2]],
                        ['type' => 'paragraph', 'data' => ['text' => 'Le prestataire fournit les services convenus.']],
                    ]],
                ],
                'en' => [
                    'title' => 'SERVICES AGREEMENT',
                    'content' => ['blocks' => [
                        ['type' => 'paragraph', 'data' => ['text' => 'The provider delivers the agreed services.']],
                    ]],
                ],
            ],
            governingLocale: 'fr',
        ));

        $this->templates->publish($version);

        return $template;
    }
}
