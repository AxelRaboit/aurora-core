<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\View;

use Aurora\Module\Studio\Contract\Access\Entity\ContractAccessLinkInterface;
use Aurora\Module\Studio\Contract\Access\Manager\ContractAccessLinkManagerInterface;
use Aurora\Module\Studio\Contract\Access\Repository\ContractAccessLinkRepository;
use Aurora\Module\Studio\Contract\Entity\ContractInterface;
use Aurora\Module\Studio\Contract\Enum\ContractStatusEnum;
use Aurora\Module\Studio\Contract\Repository\ContractRepository;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\StudioContext;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * A customer's contracts, in their client space.
 *
 * **The space did not show them at all.** A contract lived on its own link,
 * in one mail; once signed, the customer's only copy was the attachment of
 * another. Their space - the place they come back to - now lists what was
 * sent to them, says where each stands, offers to sign the one waiting and
 * to download the signed copy.
 *
 * **Only for a link that may see them** ({@see SpaceAccessLinkInterface::canSeeContracts()}),
 * and only when the contracts are switched on. Signing opens the contract's
 * own page, with the address the space can rebuild
 * ({@see ContractAccessLinkManagerInterface::spaceToken()}): the link in the
 * customer's mailbox keeps working, and the signature still asks for the
 * code mailed to the contractual address.
 */
final readonly class SpaceContractsViewBuilder
{
    public function __construct(
        private ContractRepository $contractRepository,
        private ContractAccessLinkRepository $accessLinkRepository,
        private ContractAccessLinkManagerInterface $accessLinks,
        private UrlGeneratorInterface $urlGenerator,
        private StudioContext $studioContext,
    ) {}

    public function canShow(SpaceAccessLinkInterface $link): bool
    {
        return $link->canSeeContracts() && $this->studioContext->areContractsEnabled();
    }

    /**
     * @param string $token the secret half of the space's address, for the
     *                      download path
     *
     * @return array{contracts: list<array<string, mixed>>}
     */
    public function publicView(SpaceAccessLinkInterface $link, string $token): array
    {
        if (!$this->canShow($link)) {
            return ['contracts' => []];
        }

        $contracts = $this->contractRepository->findShownToCustomer($link->getSpace()->getCustomer());
        $activeLinks = [];
        foreach ($this->accessLinkRepository->findActiveForContracts($contracts) as $accessLink) {
            $activeLinks[(int) $accessLink->getContract()->getId()] = $accessLink;
        }

        return [
            'contracts' => array_map(
                fn (ContractInterface $contract): array => $this->serialize($contract, $activeLinks[(int) $contract->getId()] ?? null, $link, $token),
                $contracts,
            ),
        ];
    }

    /** How many contracts wait for this link's signature: the « À faire » line. */
    public function countToSign(SpaceAccessLinkInterface $link): int
    {
        if (!$this->canShow($link)) {
            return 0;
        }

        return count(array_filter(
            $this->contractRepository->findShownToCustomer($link->getSpace()->getCustomer()),
            static fn (ContractInterface $contract): bool => in_array($contract->getStatus(), [ContractStatusEnum::Sent, ContractStatusEnum::Opened], true),
        ));
    }

    /** @return array<string, mixed> */
    private function serialize(ContractInterface $contract, ?ContractAccessLinkInterface $accessLink, SpaceAccessLinkInterface $link, string $token): array
    {
        $step = match ($contract->getStatus()) {
            ContractStatusEnum::Sent, ContractStatusEnum::Opened => 'to_sign',
            ContractStatusEnum::SignedByCustomer => 'awaiting_countersignature',
            ContractStatusEnum::Refused => 'refused',
            default => $contract->isTerminationEffective() ? 'ended' : 'concluded',
        };

        $spaceToken = $accessLink instanceof ContractAccessLinkInterface ? $this->accessLinks->spaceToken($accessLink) : null;

        return [
            'id' => $contract->getId(),
            'reference' => $contract->getReference(),
            'isAmendment' => $contract->isAmendment(),
            'amendsReference' => $contract->getAmendsReference(),
            'step' => $step,
            'effectiveDate' => $contract->getEffectiveDate()?->format('Y-m-d'),
            'terminationEffectiveAt' => $contract->getTerminationEffectiveAt()?->format('Y-m-d'),
            // The contract's own page, where reading and signing happen. Only
            // while a link is live: an expired one opens nothing.
            'signUrl' => 'to_sign' === $step && null !== $spaceToken && $accessLink instanceof ContractAccessLinkInterface
                ? $this->urlGenerator->generate('public_contract_show', ['selector' => $accessLink->getSelector(), 'token' => $spaceToken])
                : null,
            'pdfUrl' => in_array($step, ['concluded', 'ended'], true) && $contract->hasPdf()
                ? $this->urlGenerator->generate('public_space_contract_pdf', ['selector' => $link->getSelector(), 'token' => $token, 'contractId' => $contract->getId()])
                : null,
        ];
    }
}
