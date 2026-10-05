<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Enum;

enum DocumentStatusEnum: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public function getLabelKey(): string
    {
        return 'suite.ged.documents.status_'.$this->value;
    }
}
