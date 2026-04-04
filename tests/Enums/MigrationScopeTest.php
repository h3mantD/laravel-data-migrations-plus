<?php

use H3mantd\DataMigrations\Enums\MigrationScope;

it('has central and tenant cases', function () {
    expect(MigrationScope::cases())->toHaveCount(2);
    expect(MigrationScope::Central->value)->toBe('central');
    expect(MigrationScope::Tenant->value)->toBe('tenant');
});
