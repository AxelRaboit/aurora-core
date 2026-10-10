<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Customer\Serializer;

use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\Pipeline\Service\FollowUpCalendar;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

use const DATE_ATOM;

#[AsAlias(CustomerSerializerInterface::class)]
class CustomerSerializer implements CustomerSerializerInterface
{
    public function __construct(
        protected readonly FollowUpCalendar $followUpCalendar,
    ) {}

    /** @return array<string, mixed> */
    public function serialize(CustomerInterface $customer): array
    {
        return [
            'id' => $customer->getId(),
            'legalName' => $customer->getLegalName(),
            'status' => $customer->getStatus()->value,
            'statusLabel' => $customer->getStatus()->getLabelKey(),
            'legalForm' => $customer->getLegalForm(),
            // Sent as cents, formatted by the page: a number formatted server
            // side arrives as a string the form then has to parse back, and
            // the two parsers disagree the first time someone types a comma.
            'shareCapitalCents' => $customer->getShareCapitalCents(),
            'shareCapitalCurrency' => $customer->getShareCapitalCurrency()?->value,
            'registeredOffice' => $customer->getRegisteredOffice(),
            'siret' => $customer->getSiret(),
            'tradeRegister' => $customer->getTradeRegister(),
            'vatNumber' => $customer->getVatNumber(),
            'activitySector' => $customer->getActivitySector(),
            'representativeFirstName' => $customer->getRepresentativeFirstName(),
            'representativeLastName' => $customer->getRepresentativeLastName(),
            'representativeFullName' => $customer->getRepresentativeFullName(),
            'representativeRole' => $customer->getRepresentativeRole(),
            'contractualEmail' => $customer->getContractualEmail(),
            'phone' => $customer->getPhone(),
            'landline' => $customer->getLandline(),
            'siren' => $customer->getSiren(),
            'links' => $customer->getLinks(),
            'informationNotes' => $customer->getInformationNotes(),
            'createdAt' => $customer->getCreatedAt()->format(DATE_ATOM),
            // The pipeline. The stage as an id: the page holds the stages and
            // names it, so renaming one does not have to reload every row.
            'pipelineStageId' => $customer->getPipelineStage()?->getId(),
            'pipelineStageChangedAt' => $customer->getPipelineStageChangedAt()?->format(DATE_ATOM),
            'nextFollowUpOn' => $customer->getNextFollowUpOn()?->format('Y-m-d'),
            'followUpNote' => $customer->getFollowUpNote(),
            'followUpState' => $this->followUpState($customer),
            'source' => $customer->getSource()?->value,
            'sourceReference' => $customer->getSourceReference(),
            'estimatedValueCents' => $customer->getEstimatedValueCents(),
            'estimatedValueCurrency' => $customer->getEstimatedValueCurrency()?->value,
            'lostReason' => $customer->getLostReason(),
        ];
    }

    /**
     * Whether the follow-up is late, due today or to come.
     *
     * Decided here rather than by the page: "today" is the site's day, and a
     * browser in another timezone would disagree with the reminder about which
     * follow-ups are late.
     */
    protected function followUpState(CustomerInterface $customer): ?string
    {
        $date = $customer->getNextFollowUpOn()?->format('Y-m-d');

        if (null === $date) {
            return null;
        }

        $today = $this->followUpCalendar->today()->format('Y-m-d');

        return match (true) {
            $date < $today => 'late',
            $date === $today => 'today',
            default => 'upcoming',
        };
    }
}
