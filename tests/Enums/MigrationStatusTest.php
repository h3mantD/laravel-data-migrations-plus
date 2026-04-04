<?php

use H3mantd\DataMigrations\Enums\MigrationStatus;

it('has pending, running, completed, and failed cases', function (): void {
    expect(MigrationStatus::cases())->toHaveCount(4);
    expect(MigrationStatus::Pending->value)->toBe('pending');
    expect(MigrationStatus::Running->value)->toBe('running');
    expect(MigrationStatus::Completed->value)->toBe('completed');
    expect(MigrationStatus::Failed->value)->toBe('failed');
});
