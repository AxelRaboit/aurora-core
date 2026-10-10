<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Pipeline\Manager;

use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\Customer\Repository\CustomerRepository;
use Aurora\Module\Studio\Pipeline\Entity\PipelineStageInterface;
use Aurora\Module\Studio\Pipeline\Enum\PipelineStageRoleEnum;
use Aurora\Module\Studio\Pipeline\Repository\PipelineStageRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\Translation\TranslatorInterface;

use function mb_substr;
use function mb_trim;

#[AsAlias(PipelineManagerInterface::class)]
class PipelineManager implements PipelineManagerInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly AuditLogger $auditLogger,
        protected readonly CustomerRepository $customerRepository,
        protected readonly PipelineStageRepository $stageRepository,
        protected readonly TranslatorInterface $translator,
        protected readonly ?ClockInterface $clock = null,
    ) {}

    /**
     * Writes a stage's order from the list the board sent.
     *
     * The whole order of the target stage every time, like the columns of a
     * space's board: a card dropped from another stage arrives in that list,
     * and the customer whose stage changes is the one that was not in it.
     *
     * **Two moves are refused**, and the board never offers them:
     *
     * - a prospect into the won stage: winning is converting, which asks for
     *   the address the contract goes to. The board opens the conversion
     *   instead, and the conversion files the client here;
     * - a client out of the won stage: a client is not a prospect any more,
     *   and putting one back in "Contacted" would make it vanish from the
     *   board without becoming a prospect again.
     *
     * @param list<int> $customerIds
     */
    public function move(PipelineStageInterface $stage, array $customerIds, ?string $lostReason = null): void
    {
        $customers = [];
        foreach ([] === $customerIds ? [] : $this->customerRepository->findBy(['id' => $customerIds]) as $customer) {
            $customers[(int) $customer->getId()] = $customer;
        }

        $now = $this->clock?->now() ?? new DateTimeImmutable();
        $firstInProgressId = $this->firstInProgressId();
        $position = 0;

        foreach ($customerIds as $customerId) {
            $customer = $customers[$customerId] ?? null;

            if (!$customer instanceof CustomerInterface) {
                continue;
            }

            if (($customer->getPipelineStage()?->getId() ?? $firstInProgressId) !== $stage->getId()) {
                $this->assertMayEnter($customer, $stage);
                $this->enter($customer, $stage, $now, $lostReason);
            } elseif (!$customer->getPipelineStage() instanceof PipelineStageInterface) {
                // Shown in the first stage, now filed there for real.
                $customer->setPipelineStage($stage);
            }

            $customer->setPipelinePosition($position);
            ++$position;
        }

        $this->entityManager->flush();
    }

    public function fileAsWon(CustomerInterface $customer): void
    {
        $won = $this->stageRepository->findOneByRole(PipelineStageRoleEnum::Won);

        if (!$won instanceof PipelineStageInterface || $customer->getPipelineStage()?->getId() === $won->getId()) {
            return;
        }

        // On top of the column: it is the deal that was just won.
        $customer
            ->setPipelineStage($won)
            ->setPipelinePosition(-1)
            ->setPipelineStageChangedAt($this->clock?->now() ?? new DateTimeImmutable())
            ->setLostReason(null);
    }

    protected function assertMayEnter(CustomerInterface $customer, PipelineStageInterface $stage): void
    {
        if ($customer->isProspect() && $stage->isWon()) {
            throw new FieldException('stage', $this->translator->trans('suite.studio.pipeline.errors.won_needs_conversion'));
        }

        if (!$customer->isProspect() && !$stage->isWon()) {
            throw new FieldException('stage', $this->translator->trans('suite.studio.pipeline.errors.client_stays_won'));
        }
    }

    /**
     * A customer changes stage.
     *
     * Entering the lost stage records why, and drops the follow-up: nobody is
     * reminded to call back a deal that is over. Leaving it forgets the reason,
     * which belonged to the decision being undone.
     */
    protected function enter(CustomerInterface $customer, PipelineStageInterface $stage, DateTimeImmutable $now, ?string $lostReason): void
    {
        $from = $customer->getPipelineStage();

        $customer
            ->setPipelineStage($stage)
            ->setPipelineStageChangedAt($now);

        if ($stage->isLost()) {
            $reason = null === $lostReason ? '' : mb_trim($lostReason);

            $customer
                ->setLostReason('' === $reason ? null : mb_substr($reason, 0, 255))
                ->setNextFollowUpOn(null)
                ->setFollowUpNote(null);
        } else {
            $customer->setLostReason(null);
        }

        $this->auditLogger->log('studio', 'customer.stage_changed', 'Customer', $customer->getId(), [
            'legalName' => $customer->getLegalName(),
            'from' => $from?->getName(),
            'to' => $stage->getName(),
        ]);
    }

    /**
     * Where a customer with no stage yet is shown: the first stage in
     * progress. Moving it there is therefore not a change of stage.
     */
    protected function firstInProgressId(): ?int
    {
        foreach ($this->stageRepository->findOrdered() as $candidate) {
            if (null === $candidate->getRole()) {
                return $candidate->getId();
            }
        }

        return null;
    }
}
