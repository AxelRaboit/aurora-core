<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Pipeline\FollowUp;

use Aurora\Core\Notification\Manager\NotificationManagerInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Customer\Repository\CustomerRepository;
use Aurora\Module\Studio\Pipeline\Service\FollowUpCalendar;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\UserAuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_filter;
use function array_values;

/**
 * The morning reminder of the follow-ups due.
 *
 * **Once per follow-up date**, not once per morning: a follow-up left late
 * does not ring every day - the dashboard and the side menu keep counting it,
 * which is the nagging that can be ignored. Setting a new date rings again on
 * that date.
 *
 * **Everybody who can see customers is told.** A prospect has no owner in
 * Aurora: the studio follows it, and the person who calls back is whoever
 * gets to it first. A one-person studio receives one notification.
 */
final readonly class FollowUpReminders
{
    public const string TYPE = 'studio.follow_up';

    public function __construct(
        private CustomerRepository $customerRepository,
        private UserRepository $userRepository,
        private UserAuthorizationCheckerInterface $userAuthorizationChecker,
        private NotificationManagerInterface $notificationManager,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
        private FollowUpCalendar $followUpCalendar,
        private EntityManagerInterface $entityManager,
    ) {}

    /** @return int the notifications written */
    public function sendDue(): int
    {
        $today = $this->followUpCalendar->today();
        $customers = $this->customerRepository->findFollowUpsToNotify($today);

        if ([] === $customers) {
            return 0;
        }

        $recipients = array_values(array_filter(
            $this->userRepository->findAllAdminsAlphabetical(),
            fn (CoreUserInterface $user): bool => (!$user instanceof User || $user->isActive()) && $this->userAuthorizationChecker->isGrantedForUser($user, 'studio.customers.view'),
        ));

        $sent = 0;

        foreach ($customers as $customer) {
            $customer->setFollowUpNotifiedOn($today);

            // A path, not an absolute address: the worker has no request, so
            // an absolute one would point at localhost.
            $url = $this->urlGenerator->generate('suite_studio_customers_show', ['id' => $customer->getId()]);

            foreach ($recipients as $user) {
                $locale = $user instanceof User ? $user->getLocale()->value : null;
                $note = $customer->getFollowUpNote();

                $this->notificationManager->notify(
                    $user,
                    self::TYPE,
                    $this->translator->trans('suite.studio.pipeline.follow_up.notification_title', ['{name}' => $customer->getLegalName()], null, $locale),
                    null === $note || '' === $note
                        ? $this->translator->trans('suite.studio.pipeline.follow_up.notification_body', [], null, $locale)
                        : $note,
                    $url,
                    ['customerId' => $customer->getId()],
                    flush: false,
                );
                ++$sent;
            }
        }

        $this->entityManager->flush();

        return $sent;
    }
}
