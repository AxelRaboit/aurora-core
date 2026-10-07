<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Customer\Controller\Suite;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Support\Str;
use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Studio\Customer\Dto\CustomerInputFactoryInterface;
use Aurora\Module\Studio\Customer\Dto\CustomerInputInterface;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\Customer\Manager\CustomerManagerInterface;
use Aurora\Module\Studio\Customer\View\CustomersViewBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/suite/studio/customers', name: 'suite_studio_customers')]
#[IsGranted('studio.customers.view')]
class CustomersController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        protected readonly CustomerManagerInterface $customerManager,
        protected readonly CustomerInputFactoryInterface $customerInputFactory,
        protected readonly CustomersViewBuilder $viewBuilder,
        protected readonly PayloadValidator $payloadValidator,
    ) {}

    #[Route('', name: '', methods: [HttpMethodEnum::Get->value])]
    public function index(): Response
    {
        return $this->render('@Studio/suite/customers/index.html.twig', $this->viewBuilder->indexView());
    }

    /**
     * A customer's page: their whole sheet, and what surrounds it.
     *
     * Readable with the right to view customers; the form only opens for
     * writing to whoever can edit them, and the save checks it on its own
     * side.
     */
    #[Route('/{id}', name: '_show', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Get->value])]
    public function show(Customer $customer): Response
    {
        return $this->render('@Studio/suite/customers/show.html.twig', $this->viewBuilder->showView($customer));
    }

    #[Route('/create', name: '_create', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.customers.create')]
    public function create(Request $request): JsonResponse
    {
        return $this->withInput($request, fn ($input): JsonResponse => $this->jsonSuccess(
            $this->viewBuilder->customerPayload($this->customerManager->create($input)),
        ));
    }

    /**
     * The whole sheet, from the customer's page: the only path that writes it.
     */
    #[Route('/{id}/update', name: '_update', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.customers.edit')]
    public function update(Customer $customer, Request $request): JsonResponse
    {
        return $this->withInput($request, function ($input) use ($customer): JsonResponse {
            $this->customerManager->update($customer, $input);

            return $this->jsonSuccess($this->viewBuilder->showPayload($customer));
        });
    }

    /**
     * A prospect becomes a customer.
     *
     * Its own route rather than an `update`: the conversion form only knows
     * one field, and going through the update would have meant sending the
     * company name and the rest again to change one column.
     *
     * Answers with the whole list: the row changes tab, and the screens that
     * offer this button (the list, the spaces list, the customer's page) each
     * read again what concerns them.
     */
    #[Route('/{id}/convert', name: '_convert', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.customers.edit')]
    public function convert(Customer $customer, Request $request): JsonResponse
    {
        try {
            $this->customerManager->convertToClient(
                $customer,
                Str::emailOrNullFromArray($this->decodeJson($request), 'contractualEmail'),
            );
        } catch (FieldException $fieldException) {
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        }

        return $this->jsonSuccess($this->viewBuilder->listPayload());
    }

    #[Route('/{id}/delete', name: '_delete', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.customers.delete')]
    public function delete(Customer $customer): JsonResponse
    {
        try {
            $this->customerManager->delete($customer);
        } catch (FieldException $fieldException) {
            // A customer a contract points at. Answered like a field rejection
            // rather than a 500, which is what the `RESTRICT` foreign key was
            // producing on its own.
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        }

        return $this->jsonSuccess($this->viewBuilder->listPayload());
    }

    /**
     * Validate, then save, then answer - and turn a Manager's field rejection
     * into the same shape a constraint violation takes, so the page pins both
     * kinds of error under the field they belong to.
     *
     * @param callable(CustomerInputInterface):JsonResponse $save
     */
    private function withInput(Request $request, callable $save): JsonResponse
    {
        $input = $this->customerInputFactory->fromArray($this->decodeJson($request));

        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        try {
            return $save($input);
        } catch (FieldException $fieldException) {
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        }
    }
}
