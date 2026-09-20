<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Customer\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Studio\Customer\Dto\CustomerInformationInputFactoryInterface;
use Aurora\Module\Studio\Customer\Manager\CustomerManagerInterface;
use Aurora\Module\Studio\Customer\View\SpaceInformationViewBuilder;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * La fiche du client, écrite depuis son espace.
 *
 * **Le droit demandé est celui des clients, pas celui des espaces.** L'écran
 * s'ouvre depuis un projet, mais ce qu'il modifie est la société : son nom,
 * son SIRET, son adresse. Quelqu'un qui peut tenir le tableau d'un espace sans
 * pouvoir toucher aux fiches clients ne doit pas y arriver par ce chemin - la
 * porte d'à côté n'est pas une autorisation.
 *
 * Lire la page demande l'autre droit, celui de voir l'espace, parce que c'est
 * cette page-là qu'on ouvre.
 */
#[Route('/workspace/{id}/information', name: 'workspace_space_information', requirements: ['id' => '\d+'])]
#[IsGranted('studio.spaces.view')]
class SpaceInformationController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        protected readonly CustomerManagerInterface $customers,
        protected readonly CustomerInformationInputFactoryInterface $inputFactory,
        protected readonly SpaceInformationViewBuilder $viewBuilder,
        protected readonly PayloadValidator $payloadValidator,
    ) {}

    #[Route('/save', name: '_save', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.customers.edit')]
    public function save(CustomerSpace $space, Request $request): JsonResponse
    {
        $input = $this->inputFactory->fromArray($this->decodeJson($request));

        $errors = $this->payloadValidator->errors($input);

        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        try {
            $this->customers->updateInformation($space->getCustomer(), $input);
        } catch (FieldException $fieldException) {
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        }

        return $this->jsonSuccess($this->viewBuilder->payload($space));
    }
}
