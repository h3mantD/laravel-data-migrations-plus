<?php

declare(strict_types=1);

namespace H3mantd\DataMigrations\Enums;

enum MigrationType: string
{
    case Bootstrap = 'bootstrap';
    case Transform = 'transform';
    case Backfill = 'backfill';
    case Reconcile = 'reconcile';
    case Cleanup = 'cleanup';
}
