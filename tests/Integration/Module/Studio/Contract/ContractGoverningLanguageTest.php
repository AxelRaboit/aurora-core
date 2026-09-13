<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Contract;

use Aurora\Core\Content\BlockHtmlSanitizer;
use Aurora\Core\Sequence\SequenceGenerator;
use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Studio\Contract\Dto\ContractInput;
use Aurora\Module\Studio\Contract\Dto\ContractTemplateInput;
use Aurora\Module\Studio\Contract\Dto\ContractTemplateVersionInput;
use Aurora\Module\Studio\Contract\Entity\Contract;
use Aurora\Module\Studio\Contract\Entity\ContractInterface;
use Aurora\Module\Studio\Contract\Entity\ContractTemplate;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateInterface;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateVersionInterface;
use Aurora\Module\Studio\Contract\Enum\ContractTemplateKindEnum;
use Aurora\Module\Studio\Contract\Manager\ContractManager;
use Aurora\Module\Studio\Contract\Manager\ContractTemplateManager;
use Aurora\Module\Studio\Contract\Repository\ContractRepository;
use Aurora\Module\Studio\Contract\Repository\ContractTemplateRepository;
use Aurora\Module\Studio\Contract\Repository\ContractTemplateVersionRepository;
use Aurora\Module\Studio\Contract\Service\ContractCanonicalizer;
use Aurora\Module\Studio\Contract\Service\ContractCustomFieldScanner;
use Aurora\Module\Studio\Contract\Service\ContractDocumentRenderer;
use Aurora\Module\Studio\Contract\Service\ContractRetentionPolicy;
use Aurora\Module\Studio\Contract\Service\ContractSeal;
use Aurora\Module\Studio\Contract\Service\ContractVariableCatalogue;
use Aurora\Module\Studio\Contract\Service\ContractVariableResolver;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\Customer\Repository\CustomerRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function sprintf;
use function str_contains;

/**
 * Which language prevails, and where the clause ends up.
 *
 * A trame written in three languages is three documents, and a translator
 * makes choices. So the wording says which language is the agreement, and this
 * covers the two halves of that promise: the answer is required before a
 * multilingual version can be published, and the clause is sealed inside the
 * document rather than printed around it.
 *
 * The second half is the one worth a test of its own. A notice added by the
 * page template would be true today and absent from the PDF somebody keeps for
 * ten years, and it would sit outside the hash - which is the one place a
 * clause about authority must never be.
 */
final class ContractGoverningLanguageTest extends IntegrationTestCase
{
    private ContractManager $contracts;

    private ContractTemplateManager $templates;

    private ContractSeal $seal;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);

        // Built by hand, like the freeze test: these managers have no HTTP
        // consumer for this path, so the container removes them as unused
        // private services. Their dependencies and the database are real.
        $canonicalizer = new ContractCanonicalizer();
        $this->seal = new ContractSeal($canonicalizer);

        $this->templates = new ContractTemplateManager(
            $this->entityManager,
            $container->get(AuditLogger::class),
            $container->get(ContractTemplateVersionRepository::class),
            $container->get(TranslatorInterface::class),
            $container->get(ContractRepository::class),
        );

        $this->contracts = new ContractManager(
            $this->entityManager,
            $container->get(AuditLogger::class),
            new ContractVariableResolver(new ContractVariableCatalogue(), $container->get(SettingRepository::class)),
            new ContractDocumentRenderer(new BlockHtmlSanitizer()),
            $canonicalizer,
            $this->seal,
            $container->get(SequenceGenerator::class),
            $container->get(SettingRepository::class),
            $container->get(CustomerRepository::class),
            $container->get(ContractTemplateRepository::class),
            $container->get(TranslatorInterface::class),
            new ContractCustomFieldScanner(),
            new ContractRetentionPolicy($container->get(SettingRepository::class)),
            $container->get(ContractRepository::class),
        );
    }

    protected function tearDown(): void
    {
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', Contract::class))->execute();
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', ContractTemplate::class))->execute();
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', Customer::class))->execute();

        parent::tearDown();
    }

    /** One language has nothing to arbitrate, so nothing is asked. */
    public function testASingleLanguageVersionPublishesWithoutAnAnswer(): void
    {
        $version = $this->draftVersion(['fr']);

        $this->templates->publish($version);

        self::assertTrue($version->isPublished());
        self::assertNull($version->getGoverningLocale());
    }

    public function testAMultilingualVersionCannotBePublishedWithoutOne(): void
    {
        $version = $this->draftVersion(['fr', 'en']);

        $this->expectException(FieldException::class);

        try {
            $this->templates->publish($version);
        } catch (FieldException $fieldException) {
            self::assertSame('governingLocale', $fieldException->getField());

            throw $fieldException;
        }
    }

    public function testAnsweringItLetsThePublicationThrough(): void
    {
        $version = $this->draftVersion(['fr', 'en'], 'fr');

        $this->templates->publish($version);

        self::assertTrue($version->isPublished());
        self::assertSame('fr', $version->getGoverningLocale());
    }

    /**
     * A language nobody wrote cannot be the one that prevails: the clause would
     * point at a document that does not exist.
     */
    public function testTheGoverningLanguageHasToBeWritten(): void
    {
        $template = $this->templates->create(new ContractTemplateInput('Contrat mensuel', ContractTemplateKindEnum::Body));
        $version = $template->getDraft();

        $this->expectException(FieldException::class);

        try {
            $this->templates->updateDraft($version, new ContractTemplateVersionInput(
                translations: $this->wording(['fr', 'en']),
                governingLocale: 'es',
            ));
        } catch (FieldException $fieldException) {
            self::assertSame('governingLocale', $fieldException->getField());
            // Nothing was written either: the check runs before the wordings.
            self::assertNull($version->getGoverningLocale());

            throw $fieldException;
        }
    }

    /** The clause travels with the wording it belongs to. */
    public function testOpeningADraftKeepsTheAnswer(): void
    {
        $version = $this->draftVersion(['fr', 'en'], 'fr');
        $this->templates->publish($version);

        $draft = $this->templates->openDraft($version->getTemplate());

        self::assertSame('fr', $draft->getGoverningLocale());
    }

    /**
     * Inside the sealed HTML, and therefore inside the hash. If this ever moves
     * to the page template, this assertion is the one that says so.
     */
    public function testTheClauseIsSealedIntoTheDocument(): void
    {
        $version = $this->draftVersion(['fr', 'en'], 'fr');
        $this->templates->publish($version);

        $contract = $this->contractFor($version, 'fr');
        $this->contracts->freeze($contract);

        $html = (string) $contract->getRenderedHtml();

        self::assertStringContainsString('contract-language', $html);
        self::assertStringContainsString('Langue du contrat', $html);
        self::assertStringContainsString('seule la version française fait foi', $html);
        // A contract read in the language that prevails is told which one it
        // is, and nothing more: it is not holding a translation.
        self::assertStringNotContainsString('traduction fournie', $html);

        self::assertSame('fr', $contract->getContentSnapshot()['governingLocale']);
        self::assertTrue($this->seal->verify($contract));
    }

    /**
     * Somebody signing the Spanish version of a French contract is entitled to
     * be told, in Spanish, that the French text is the one that counts.
     */
    public function testATranslationSaysSoInItsOwnLanguage(): void
    {
        $version = $this->draftVersion(['fr', 'es'], 'fr');
        $this->templates->publish($version);

        $contract = $this->contractFor($version, 'es');
        $this->contracts->freeze($contract);

        $html = (string) $contract->getRenderedHtml();

        self::assertStringContainsString('Idioma del contrato', $html);
        self::assertStringContainsString('solo prevalece la versión francesa', $html);
        self::assertStringContainsString('una traducción facilitada', $html);
        // Not a word of French in a document a Spanish speaker signs.
        self::assertFalse(str_contains($html, 'fait foi'));
    }

    /** No clause at all when the wording has one language: it would be noise. */
    public function testASingleLanguageDocumentCarriesNoClause(): void
    {
        $version = $this->draftVersion(['fr']);
        $this->templates->publish($version);

        $contract = $this->contractFor($version, 'fr');
        $this->contracts->freeze($contract);

        self::assertStringNotContainsString('contract-language', (string) $contract->getRenderedHtml());
        self::assertNull($contract->getContentSnapshot()['governingLocale']);
    }

    /**
     * A body and an annex naming different languages is a contradiction nobody
     * can resolve at freeze time. Sealing it would produce one document with
     * two authoritative texts.
     */
    public function testPartsThatDisagreeAreRefused(): void
    {
        $body = $this->draftVersion(['fr', 'en'], 'fr');
        $this->templates->publish($body);

        $annexTemplate = $this->templates->create(new ContractTemplateInput('Annexe tarifaire', ContractTemplateKindEnum::Annex));
        $annex = $annexTemplate->getDraft();
        $this->templates->updateDraft($annex, new ContractTemplateVersionInput(
            translations: $this->wording(['fr', 'en']),
            governingLocale: 'en',
        ));
        $this->templates->publish($annex);

        $contract = $this->contracts->create(new ContractInput(
            customerId: $this->customer()->getId(),
            bodyTemplateId: $body->getTemplate()->getId(),
            annexTemplateId: $annexTemplate->getId(),
            locale: 'fr',
        ));

        $this->expectException(FieldException::class);

        try {
            $this->contracts->freeze($contract);
        } catch (FieldException $fieldException) {
            self::assertSame('annexVersion', $fieldException->getField());
            self::assertFalse($contract->isFrozen());

            throw $fieldException;
        }
    }

    /** @param list<string> $locales */
    private function draftVersion(array $locales, ?string $governing = null): ContractTemplateVersionInterface
    {
        $template = $this->templates->create(new ContractTemplateInput('Contrat mensuel', ContractTemplateKindEnum::Body));
        $version = $template->getDraft();

        self::assertInstanceOf(ContractTemplateVersionInterface::class, $version);

        $this->templates->updateDraft($version, new ContractTemplateVersionInput(
            translations: $this->wording($locales),
            governingLocale: $governing,
        ));

        return $version;
    }

    /**
     * @param list<string> $locales
     *
     * @return array<string, array{title: string, content: array<string, mixed>}>
     */
    private function wording(array $locales): array
    {
        $titles = [
            'fr' => 'CONTRAT DE PRESTATION DE SERVICES',
            'en' => 'SERVICES AGREEMENT',
            'es' => 'CONTRATO DE PRESTACIÓN DE SERVICIOS',
        ];

        $articles = [
            'fr' => 'Le prestataire fournit les services convenus.',
            'en' => 'The provider delivers the agreed services.',
            'es' => 'El prestador presta los servicios acordados.',
        ];

        $wording = [];

        foreach ($locales as $locale) {
            $wording[$locale] = [
                'title' => $titles[$locale],
                'content' => ['blocks' => [
                    ['type' => 'header', 'data' => ['text' => 'ARTICLE 1', 'level' => 2]],
                    ['type' => 'paragraph', 'data' => ['text' => $articles[$locale]]],
                ]],
            ];
        }

        return $wording;
    }

    private function contractFor(ContractTemplateVersionInterface $version, string $locale): ContractInterface
    {
        return $this->contracts->create(new ContractInput(
            customerId: $this->customer()->getId(),
            bodyTemplateId: $this->templateOf($version)->getId(),
            locale: $locale,
        ));
    }

    private function templateOf(ContractTemplateVersionInterface $version): ContractTemplateInterface
    {
        return $version->getTemplate();
    }

    private function customer(): CustomerInterface
    {
        $customer = new Customer();
        $customer
            ->setLegalName('Boulangerie Durand')
            ->setSiret('73282932000074')
            ->setContractualEmail('contact@durand.test')
            ->setRepresentativeFirstName('Camille')
            ->setRepresentativeLastName('Durand');

        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        return $customer;
    }
}
