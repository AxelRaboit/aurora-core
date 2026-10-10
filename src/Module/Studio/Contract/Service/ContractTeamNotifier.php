<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Service;

use Aurora\Core\Notification\Manager\NotificationManagerInterface;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Studio\Contract\Entity\ContractInterface;
use Aurora\Module\Studio\CustomerSpace\Repository\CustomerSpaceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * Telling the people who work for a customer that one of its contracts moved.
 *
 * **The administrator address was the only one told.** A signature, a refusal
 * went to the site's email setting and nowhere else, which is right for
 * whoever runs the application and blind for a colleague who runs the
 * customer's space: they learnt that the contract was signed by opening the
 * list. The bell now tells them too. The administrator mail stays, so nothing
 * changes for the person who already received it.
 *
 * **The team is the members of the customer's spaces.** A contract names a
 * customer, not a space, and a customer may have several: each of their
 * members hears it once. A customer with no space has no team, and only the
 * administrator address is told - the same rule as a space with no member,
 * which notifies nobody.
 *
 * **Each person reads it in their own language.** The title is translated for
 * the recipient, not for the request that caused it: a refusal arrives from
 * the customer's page, in the customer's language.
 *
 * Nothing here can fail the change it reports. The caller has flushed the
 * contract already; a notification that cannot be written is lost, the
 * signature is not.
 */
readonly class ContractTeamNotifier
{
    public const string TYPE_SIGNED = 'studio.contract.signed';
    public const string TYPE_REFUSED = 'studio.contract.refused';
    public const string TYPE_EXPIRED = 'studio.contract.expired';
    public const string TYPE_TERMINATION_EFFECTIVE = 'studio.contract.termination_effective';

    public function __construct(
        private NotificationManagerInterface $notificationManager,
        private CustomerSpaceRepository $spaceRepository,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
        private EntityManagerInterface $entityManager,
    ) {}

    public function signed(ContractInterface $contract): void
    {
        $this->tell($contract, self::TYPE_SIGNED, 'suite.studio.contract_notifications.signed');
    }

    public function refused(ContractInterface $contract): void
    {
        $this->tell($contract, self::TYPE_REFUSED, 'suite.studio.contract_notifications.refused');
    }

    public function expired(ContractInterface $contract): void
    {
        $this->tell($contract, self::TYPE_EXPIRED, 'suite.studio.contract_notifications.expired');
    }

    /** The day a termination takes effect: the contract stops running today. */
    public function terminationEffective(ContractInterface $contract): void
    {
        $this->tell($contract, self::TYPE_TERMINATION_EFFECTIVE, 'suite.studio.contract_notifications.termination_effective');
    }

    /** @return int the notifications written */
    private function tell(ContractInterface $contract, string $type, string $titleKey): int
    {
        try {
            $team = $this->spaceRepository->findTeamOfCustomer($contract->getCustomer());
        } catch (Throwable) {
            return 0;
        }

        if ([] === $team) {
            return 0;
        }

        // A path, not an absolute address: the expiry runs in the worker,
        // which has no request to take a host from.
        $url = $this->urlGenerator->generate('suite_studio_contracts_show', ['id' => $contract->getId()]);
        $parameters = ['{reference}' => (string) $contract->getReference()];
        $written = 0;

        foreach ($team as $recipient) {
            // A deactivated account keeps its memberships; it does not keep
            // receiving news.
            if ($recipient instanceof User && !$recipient->isActive()) {
                continue;
            }

            $locale = $recipient instanceof User ? $recipient->getLocale()->value : null;

            try {
                $this->notificationManager->notify(
                    $recipient,
                    $type,
                    $this->translator->trans($titleKey, $parameters, null, $locale),
                    $contract->getCustomer()->getLegalName(),
                    $url,
                    ['contractId' => $contract->getId()],
                    flush: false,
                );
                ++$written;
            } catch (Throwable) {
            }
        }

        try {
            $this->entityManager->flush();
        } catch (Throwable) {
            return 0;
        }

        return $written;
    }
}
