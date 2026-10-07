<?php

declare(strict_types=1);

namespace Aurora\Module\Dev\MountPoint\Enum;

enum MountPointTypeEnum: string
{
    case Database = 'database';
    case Api = 'api';
    case Sftp = 'sftp';

    /** Translated: the list read "Database" and "Api" in a French suite. */
    public function getLabelKey(): string
    {
        return 'suite.mount_points.types.'.$this->value;
    }
}
