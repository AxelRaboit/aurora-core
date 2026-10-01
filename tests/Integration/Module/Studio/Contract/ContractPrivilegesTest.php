<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Contract;

use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Studio\Contract\Dto\ContractTemplateInput;
use Aurora\Module\Studio\Contract\Dto\ContractTemplateVersionInput;
use Aurora\Module\Studio\Contract\Entity\Contract;
use Aurora\Module\Studio\Contract\Entity\ContractTemplate;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateInterface;
use Aurora\Module\Studio\Contract\Enum\ContractTemplateKindEnum;
use Aurora\Module\Studio\Contract\Manager\ContractTemplateManager;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function sprintf;

/**
 * Every write on a contract or a trame asks for its own privilege, and a
 * reader who may only look is turned away at the door.
 *
 * The screens hide what a reader may not do, which proves nothing: the
 * refusal that counts is the server's, and until now no test asked for it.
 */
final class ContractPrivilegesTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', Contract::class))->execute();
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', ContractTemplate::class))->execute();
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', Customer::class))->execute();

        parent::tearDown();
    }

    public function testAReaderOfContractsCannotWriteOne(): void
    {
        $contract = $this->draftContract();
        $this->logInWith(['studio.contracts.view'], 'lecteur-contrats@example.test');

        // Reading stays open: the refusals below are about the gesture.
        $this->client->request('GET', sprintf('/backend/studio/contracts/%d', $contract->getId()));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', sprintf('/backend/studio/contracts/%d/wording/body', $contract->getId()));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        foreach (['update', 'freeze', 'send', 'remind', 'revoke-link', 'cancel', 'duplicate', 'terminate', 'countersign', 'delete', 'wording/body/save', 'wording/body/reset'] as $gesture) {
            $this->client->jsonRequest('POST', sprintf('/backend/studio/contracts/%d/%s', $contract->getId(), $gesture));

            self::assertSame(403, $this->client->getResponse()->getStatusCode(), $gesture);
        }

        $this->client->jsonRequest('POST', '/backend/studio/contracts/create', []);
        self::assertSame(403, $this->client->getResponse()->getStatusCode(), 'create');
    }

    /** Sending is its own right: editing a contract does not include it. */
    public function testAnEditorWhoMayNotSendCannotSend(): void
    {
        $contract = $this->draftContract();
        $this->logInWith(['studio.contracts.view', 'studio.contracts.edit'], 'redacteur-contrats@example.test');

        foreach (['send', 'remind', 'revoke-link', 'countersign', 'delete'] as $gesture) {
            $this->client->jsonRequest('POST', sprintf('/backend/studio/contracts/%d/%s', $contract->getId(), $gesture));

            self::assertSame(403, $this->client->getResponse()->getStatusCode(), $gesture);
        }

        // And what editing does include goes through: the refusals above are
        // the privilege's, not the account's.
        $this->client->jsonRequest('POST', sprintf('/backend/studio/contracts/%d/freeze', $contract->getId()));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testAReaderOfTramesCannotWriteOne(): void
    {
        $template = $this->templateWithDraft();
        $versionId = $template->getDraft()?->getId();
        $this->logInWith(['studio.contract_templates.view'], 'lecteur-trames@example.test');

        $this->client->request('GET', sprintf('/backend/studio/contract-templates/%d/versions/%d', $template->getId(), $versionId));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $paths = [
            sprintf('/%d/update', $template->getId()),
            sprintf('/%d/archive', $template->getId()),
            sprintf('/%d/restore', $template->getId()),
            sprintf('/%d/delete', $template->getId()),
            sprintf('/%d/duplicate', $template->getId()),
            sprintf('/%d/open-draft', $template->getId()),
            sprintf('/%d/versions/%d/save', $template->getId(), $versionId),
            sprintf('/%d/versions/%d/publish', $template->getId(), $versionId),
            sprintf('/%d/versions/%d/discard', $template->getId(), $versionId),
            '/create',
        ];

        foreach ($paths as $path) {
            $this->client->jsonRequest('POST', '/backend/studio/contract-templates'.$path, []);

            self::assertSame(403, $this->client->getResponse()->getStatusCode(), $path);
        }
    }

    /** @param list<string> $privileges */
    private function logInWith(array $privileges, string $email): void
    {
        $user = new User();
        $user
            ->setEmail($email)
            ->setName('Équipier')
            ->setType(UserTypeEnum::Backend)
            ->setRoles([UserRoleEnum::User->value])
            ->setPrivileges($privileges)
            ->setPassword('x');

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->client->loginUser($user, 'admin');
    }

    private function draftContract(): Contract
    {
        $version = $this->templateWithDraft()->getDraft();
        $this->templates()->publish($version);

        $customer = new Customer();
        $customer->setLegalName('Boulangerie Durand')->setContractualEmail('contact@durand.test');
        $this->entityManager->persist($customer);

        $contract = new Contract();
        $contract
            ->setCustomer($customer)
            ->setBodyVersion($version)
            ->setLocale('fr');
        $this->entityManager->persist($contract);
        $this->entityManager->flush();

        return $contract;
    }

    private function templateWithDraft(): ContractTemplateInterface
    {
        $template = $this->templates()->create(new ContractTemplateInput('Contrat mensuel', ContractTemplateKindEnum::Body));

        $this->templates()->updateDraft($template->getDraft(), new ContractTemplateVersionInput([
            'fr' => [
                'title' => 'CONTRAT DE PRESTATION DE SERVICES',
                'content' => ['blocks' => [
                    ['type' => 'paragraph', 'data' => ['text' => 'Pour {{customer.legal_name}}.']],
                ]],
            ],
        ]));

        return $template;
    }

    private function templates(): ContractTemplateManager
    {
        return static::getContainer()->get(ContractTemplateManager::class);
    }
}
