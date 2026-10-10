<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Pipeline\Prospect;

use Aurora\Core\Contact\Prospect\ProspectDirectoryInterface;
use Aurora\Core\Contact\Prospect\WebsiteContact;
use Aurora\Module\Studio\Customer\Dto\CustomerInputFactoryInterface;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\Customer\Enum\CustomerSourceEnum;
use Aurora\Module\Studio\Customer\Enum\CustomerStatusEnum;
use Aurora\Module\Studio\Customer\Manager\CustomerManagerInterface;
use Aurora\Module\Studio\Customer\Repository\CustomerRepository;
use Aurora\Module\Studio\CustomerInteraction\Entity\CustomerInteraction;
use Aurora\Module\Studio\CustomerInteraction\Entity\CustomerInteractionInterface;
use Aurora\Module\Studio\CustomerInteraction\Enum\CustomerInteractionKindEnum;
use Aurora\Module\Studio\StudioContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

use function filter_var;
use function mb_substr;

use const FILTER_VALIDATE_EMAIL;

/**
 * Studio's side of {@see ProspectDirectoryInterface}: a website contact
 * becomes a prospect, its message the first line of its history.
 *
 * **The message is kept as an exchange**, not pasted into the sheet's notes:
 * the sheet's notes are visible to the customer, and the history is where
 * "what did they ask for" is looked up before calling back.
 */
#[AsAlias(ProspectDirectoryInterface::class)]
class CustomerProspectDirectory implements ProspectDirectoryInterface
{
    public function __construct(
        protected readonly StudioContext $studioContext,
        protected readonly AuthorizationCheckerInterface $authorizationChecker,
        protected readonly CustomerRepository $customerRepository,
        protected readonly CustomerManagerInterface $customerManager,
        protected readonly CustomerInputFactoryInterface $customerInputFactory,
        protected readonly UrlGeneratorInterface $urlGenerator,
        protected readonly EntityManagerInterface $entityManager,
    ) {}

    public function isAvailable(): bool
    {
        return $this->studioContext->isSuiteEnabled()
            && $this->studioContext->areCustomersEnabled()
            && $this->authorizationChecker->isGranted('studio.customers.create');
    }

    public function pathsForSources(array $sourceReferences): array
    {
        $paths = [];
        foreach ($this->customerRepository->findBySourceReferences($sourceReferences) as $customer) {
            $paths[(string) $customer->getSourceReference()] = $this->pathOf($customer);
        }

        return $paths;
    }

    public function createFromWebsiteContact(WebsiteContact $contact): string
    {
        $existing = $this->customerRepository->findOneBySourceReference($contact->sourceReference);
        if ($existing instanceof CustomerInterface) {
            return $this->pathOf($existing);
        }

        // Through the customer Manager rather than around it, so the audit
        // says a customer was created like any other. An address the form did
        // not check is dropped rather than refused: the prospect is worth more
        // than a typo, and the sheet can be corrected.
        $email = null !== $contact->email && false !== filter_var($contact->email, FILTER_VALIDATE_EMAIL) ? $contact->email : null;

        $customer = $this->customerManager->create($this->customerInputFactory->fromArray([
            'legalName' => mb_substr($contact->name, 0, 180),
            'contractualEmail' => $email,
            'phone' => null === $contact->phone ? null : mb_substr($contact->phone, 0, 30),
            'status' => CustomerStatusEnum::Prospect->value,
            'source' => CustomerSourceEnum::WebsiteForm->value,
        ]));

        $customer->setSourceReference($contact->sourceReference);

        $interaction = $this->createInteraction();
        $interaction
            ->setCustomer($customer)
            ->setKind(CustomerInteractionKindEnum::Message)
            ->setOccurredAt($contact->receivedAt)
            ->setSummary($contact->summary)
            ->setAuthor(null, $contact->sourceLabel);
        $this->entityManager->persist($interaction);
        $this->entityManager->flush();

        return $this->pathOf($customer);
    }

    /**
     * Instantiates the concrete entity. Override in a subclass to return a
     * client-substituted class - `resolve_target_entities` only affects
     * Doctrine relation resolution, not direct `new` calls.
     */
    protected function createInteraction(): CustomerInteractionInterface
    {
        return new CustomerInteraction();
    }

    protected function pathOf(CustomerInterface $customer): string
    {
        return $this->urlGenerator->generate('suite_studio_customers_show', ['id' => $customer->getId()]);
    }
}
