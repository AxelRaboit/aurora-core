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

/**
 * The blanks a trame leaves to one contract.
 *
 * The real trames this was built for carry a handful of them: the person
 * habilitated to validate, a kilometric threshold, a deposit rate. They are
 * neither customer data nor template wording, and before this they had nowhere
 * to go - so the paper trame kept `[À COMPLÉTER]` and the sealed document would
 * have kept it too.
 *
 * What the tests below pin down is the guarantee that makes the feature safe:
 * **a document is never sealed with one of them empty.** Everything else is
 * convenience.
 */
final class ContractCustomFieldsTest extends IntegrationTestCase
{
    private ContractManager $contracts;

    private ContractTemplateManager $templates;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);

        $canonicalizer = new ContractCanonicalizer();

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
            new ContractSeal($canonicalizer),
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

    public function testAFilledFieldIsWrittenIntoTheSealedDocument(): void
    {
        $version = $this->publishedVersionAsking();
        $contract = $this->contractFor($version, ['acompte' => '40 %']);

        $this->contracts->freeze($contract);

        self::assertStringContainsString('Un acompte de 40 % est dû à la signature.', (string) $contract->getRenderedHtml());
        // Recorded in the snapshot too: a later reader has to see the values
        // the document was sealed with, not just their trace in the HTML.
        self::assertSame(['acompte' => '40 %'], $contract->getContentSnapshot()['customFields']);
    }

    /**
     * The one that matters. A trame asks for a value because the sentence
     * around it needs one, and sealing "un acompte de  est dû" would produce a
     * signed document with a hole in it.
     */
    public function testAMissingFieldRefusesTheFreezeAndNamesIt(): void
    {
        $version = $this->publishedVersionAsking();
        $contract = $this->contractFor($version, []);

        $this->expectException(FieldException::class);

        try {
            $this->contracts->freeze($contract);
        } catch (FieldException $fieldException) {
            self::assertSame('customFields', $fieldException->getField());
            self::assertStringContainsString('acompte', $fieldException->getMessage());
            // Nothing was consumed: no reference minted, no snapshot written.
            self::assertFalse($contract->isFrozen());
            self::assertNull($contract->getReference());

            throw $fieldException;
        }
    }

    /** An empty string is a blank, and a blank is the thing being prevented. */
    public function testAnEmptyFieldCountsAsMissing(): void
    {
        $version = $this->publishedVersionAsking();
        $contract = $this->contractFor($version, ['acompte' => '']);

        $this->expectException(FieldException::class);

        $this->contracts->freeze($contract);
    }

    /**
     * A value is a person's free text, so it is inserted as text. Without this
     * a company name could carry markup into a document about to be signed.
     */
    public function testAValueCannotCarryMarkupIntoTheDocument(): void
    {
        $version = $this->publishedVersionAsking();
        $contract = $this->contractFor($version, ['acompte' => '<b>40 %</b>']);

        $this->contracts->freeze($contract);

        $html = (string) $contract->getRenderedHtml();

        self::assertStringContainsString('&lt;b&gt;40 %&lt;/b&gt;', $html);
        self::assertStringNotContainsString('<b>40 %</b>', $html);
    }

    /** A trame that asks for nothing is unaffected: no field, no refusal. */
    public function testATrameThatAsksForNothingFreezesWithNoFields(): void
    {
        $version = $this->publishedVersionAsking(false);
        $contract = $this->contractFor($version, []);

        $this->contracts->freeze($contract);

        self::assertTrue($contract->isFrozen());
        self::assertSame([], $contract->getContentSnapshot()['customFields']);
    }

    private function publishedVersionAsking(bool $asking = true): ContractTemplateVersionInterface
    {
        $template = $this->templates->create(new ContractTemplateInput('Contrat mensuel', ContractTemplateKindEnum::Body));
        $version = $template->getDraft();

        self::assertInstanceOf(ContractTemplateVersionInterface::class, $version);

        $text = $asking
            ? 'Un acompte de {{contract.custom.acompte}} est dû à la signature.'
            : 'Le forfait est payable d\'avance.';

        $this->templates->updateDraft($version, new ContractTemplateVersionInput([
            'fr' => [
                'title' => 'CONTRAT DE PRESTATION DE SERVICES',
                'content' => ['blocks' => [
                    ['type' => 'header', 'data' => ['text' => 'ARTICLE 5', 'level' => 2]],
                    ['type' => 'paragraph', 'data' => ['text' => $text]],
                ]],
            ],
        ]));

        $this->templates->publish($version);

        return $version;
    }

    /** @param array<string, string> $customFields */
    private function contractFor(ContractTemplateVersionInterface $version, array $customFields): ContractInterface
    {
        return $this->contracts->create(new ContractInput(
            customerId: $this->customer()->getId(),
            bodyTemplateId: $version->getTemplate()->getId(),
            locale: 'fr',
            customFields: $customFields,
        ));
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
