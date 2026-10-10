<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerInteraction\Controller\Suite;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\Customer\Serializer\CustomerSerializerInterface;
use Aurora\Module\Studio\CustomerInteraction\Dto\CustomerInteractionInputFactoryInterface;
use Aurora\Module\Studio\CustomerInteraction\Dto\CustomerInteractionInputInterface;
use Aurora\Module\Studio\CustomerInteraction\Entity\CustomerInteraction;
use Aurora\Module\Studio\CustomerInteraction\Manager\CustomerInteractionManagerInterface;
use Aurora\Module\Studio\CustomerInteraction\Repository\CustomerInteractionRepository;
use Aurora\Module\Studio\CustomerInteraction\Serializer\CustomerInteractionSerializerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function array_map;

/**
 * A customer's exchanges, written from their page.
 *
 * Under the customer's address and rights: the history is part of the sheet,
 * and whoever may edit the sheet may write in it. Every gesture answers with
 * the whole timeline and the sheet read again, since recording a call can
 * move the follow-up.
 */
#[Route('/suite/studio/customers/{id}/interactions', name: 'suite_studio_customers_interactions', requirements: ['id' => '\d+'])]
#[IsGranted('studio.customers.edit')]
class CustomerInteractionsController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        protected readonly CustomerInteractionManagerInterface $interactionManager,
        protected readonly CustomerInteractionInputFactoryInterface $inputFactory,
        protected readonly CustomerInteractionRepository $interactionRepository,
        protected readonly CustomerInteractionSerializerInterface $interactionSerializer,
        protected readonly CustomerSerializerInterface $customerSerializer,
        protected readonly PayloadValidator $payloadValidator,
    ) {}

    #[Route('/create', name: '_create', methods: [HttpMethodEnum::Post->value])]
    public function create(Customer $customer, Request $request): JsonResponse
    {
        return $this->withInput($request, $customer, function (CustomerInteractionInputInterface $input) use ($customer): void {
            $user = $this->getUser();
            $this->interactionManager->create($customer, $input, $user instanceof CoreUserInterface ? $user : null);
        });
    }

    #[Route('/{interactionId}/update', name: '_update', requirements: ['interactionId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    public function update(Customer $customer, #[MapEntity(id: 'interactionId')] CustomerInteraction $interaction, Request $request): JsonResponse
    {
        if ($interaction->getCustomer()->getId() !== $customer->getId()) {
            return $this->jsonNotFound();
        }

        return $this->withInput($request, $customer, function (CustomerInteractionInputInterface $input) use ($interaction): void {
            $this->interactionManager->update($interaction, $input);
        });
    }

    #[Route('/{interactionId}/delete', name: '_delete', requirements: ['interactionId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    public function delete(Customer $customer, #[MapEntity(id: 'interactionId')] CustomerInteraction $interaction): JsonResponse
    {
        if ($interaction->getCustomer()->getId() !== $customer->getId()) {
            return $this->jsonNotFound();
        }

        $this->interactionManager->delete($interaction);

        return $this->jsonSuccess($this->payload($customer));
    }

    /** @param callable(CustomerInteractionInputInterface):void $save */
    private function withInput(Request $request, CustomerInterface $customer, callable $save): JsonResponse
    {
        $input = $this->inputFactory->fromArray($this->decodeJson($request));

        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $save($input);

        return $this->jsonSuccess($this->payload($customer));
    }

    /** @return array<string, mixed> */
    private function payload(CustomerInterface $customer): array
    {
        return [
            'customer' => $this->customerSerializer->serialize($customer),
            'interactions' => array_map(
                $this->interactionSerializer->serialize(...),
                $this->interactionRepository->findForCustomer($customer),
            ),
        ];
    }
}
