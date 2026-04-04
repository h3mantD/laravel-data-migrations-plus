<?php

declare(strict_types=1);

namespace H3mantd\DataMigrations\Enums;

enum MigrationScope: string
{
    case Central = 'central';
    case Tenant = 'tenant';
}
