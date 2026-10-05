<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\Auth\Enum;

enum AccessRequestStatusEnum: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function getLabelKey(): string
    {
        return 'suite.access_requests.status_'.$this->value;
    }
}
