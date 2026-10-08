<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Customer\View;

use Aurora\Module\Studio\Customer\Serializer\CustomerInformationSerializerInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\StudioContext;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * The customer's sheet, sent read-only with their space's page.
 *
 * **The sheet belongs to the customer, not to the space**, and that is the
 * point: a SIRET belongs to a company and not to a project. Two spaces opened
 * for the same customer therefore show the same sheet, and cannot contradict
 * each other.
 *
 * **It is no longer written from here.** The tab had its own form, with other
 * fields than the customers screen: the SIREN, landline, links and notes
 * could only be entered from a space, the capital and RCS only from the
 * list. Everything is now edited on the customer's page, and the tab leads
 * there, for whoever has the right to edit it.
 */
final readonly class SpaceInformationViewBuilder
{
    public function __construct(
        private CustomerInformationSerializerInterface $serializer,
        private CustomerRelatedViewBuilder $relatedViewBuilder,
        private UrlGeneratorInterface $urlGenerator,
        private AuthorizationCheckerInterface $authorizationChecker,
        private StudioContext $studioContext,
    ) {}

    /** @return array<string, mixed> */
    public function view(CustomerSpaceInterface $space): array
    {
        $customer = $space->getCustomer();

        return [
            'information' => $this->serializer->serialize($customer),
            'related' => $this->relatedViewBuilder->related($customer, $space),
            'customerPath' => $this->customerPath($space),
        ];
    }

    /**
     * The customer's page, or null for whoever could not edit the sheet there.
     *
     * Both rights, because the link is called "Modifier la fiche": the page
     * requires viewing customers, the save editing them. A link to a read-only
     * form would be a promise it does not keep. And nothing when the
     * customers module is off: its page would answer 404.
     */
    private function customerPath(CustomerSpaceInterface $space): ?string
    {
        if (!$this->studioContext->areCustomersEnabled()) {
            return null;
        }

        if (!$this->authorizationChecker->isGranted('studio.customers.view') || !$this->authorizationChecker->isGranted('studio.customers.edit')) {
            return null;
        }

        return $this->urlGenerator->generate('suite_studio_customers_show', ['id' => $space->getCustomer()->getId()]);
    }
}
