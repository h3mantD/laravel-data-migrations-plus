<?php

use H3mantd\DataMigrations\Enums\MigrationType;

it('has bootstrap, transform, backfill, reconcile, and cleanup cases', function (): void {
    expect(MigrationType::cases())->toHaveCount(5);
    expect(MigrationType::Bootstrap->value)->toBe('bootstrap');
    expect(MigrationType::Transform->value)->toBe('transform');
    expect(MigrationType::Backfill->value)->toBe('backfill');
    expect(MigrationType::Reconcile->value)->toBe('reconcile');
    expect(MigrationType::Cleanup->value)->toBe('cleanup');
});
