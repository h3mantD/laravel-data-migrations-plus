<?php

declare(strict_types=1);

namespace H3mantd\DataMigrations\Enums;

enum MigrationStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
