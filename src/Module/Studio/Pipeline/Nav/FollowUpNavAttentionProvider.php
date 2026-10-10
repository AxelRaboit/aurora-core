<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Pipeline\Nav;

use Aurora\Core\Module\Nav\NavItemAttentionProviderInterface;
use Aurora\Module\Studio\Customer\Repository\CustomerRepository;
use Aurora\Module\Studio\Pipeline\Service\FollowUpCalendar;

/**
 * The pill beside "Customers": follow-ups due today or late, the same figure
 * as the dashboard's "to handle" line, from the same query.
 */
final readonly class FollowUpNavAttentionProvider implements NavItemAttentionProviderInterface
{
    private const string CUSTOMERS = 'suite_studio_customers';

    public function __construct(
        private CustomerRepository $customerRepository,
        private FollowUpCalendar $followUpCalendar,
    ) {}

    public function getAttentionItemKeys(): array
    {
        return [self::CUSTOMERS];
    }

    public function countAttention(string $itemKey): int
    {
        return self::CUSTOMERS === $itemKey ? $this->customerRepository->countFollowUpsDue($this->followUpCalendar->today()) : 0;
    }

    public function getAttentionLabelKey(string $itemKey): string
    {
        return 'suite.studio.pipeline.follow_up.due_count';
    }
}
