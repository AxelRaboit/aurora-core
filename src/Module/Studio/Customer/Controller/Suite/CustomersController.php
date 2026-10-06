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
     * La page d'un client : toute sa fiche, et ce qui l'entoure.
     *
     * Lisible avec le droit de voir les clients ; le formulaire ne s'ouvre en
     * écriture qu'à qui peut les modifier, et l'enregistrement le vérifie de
     * son côté.
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
     * La fiche entière, depuis la page du client : le seul chemin qui l'écrit.
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
     * Un prospect devient client.
     *
     * Sa propre route plutot qu'un `update` : le formulaire de conversion ne
     * connait qu'un champ, et passer par la mise a jour aurait demande de
     * renvoyer la raison sociale et le reste pour changer une colonne.
     *
     * Repond avec la liste entiere : le rang change d'onglet, et les ecrans
     * qui offrent ce bouton (la liste, celle des espaces, la page du client)
     * y relisent chacun ce qui les concerne.
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
