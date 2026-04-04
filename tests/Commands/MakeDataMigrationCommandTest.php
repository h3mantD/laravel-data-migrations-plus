<?php

use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->centralDir = database_path('data-migrations');
    $this->tenantDir = database_path('data-migrations/tenant');
    if (is_dir($this->centralDir)) {
        File::cleanDirectory($this->centralDir);
    }
    if (is_dir($this->tenantDir)) {
        File::cleanDirectory($this->tenantDir);
    }
});

afterEach(function () {
    if (is_dir($this->centralDir)) {
        File::cleanDirectory($this->centralDir);
    }
    if (is_dir($this->tenantDir)) {
        File::cleanDirectory($this->tenantDir);
    }
});

it('creates a central data migration file', function () {
    $this->artisan('make:data-migration', ['name' => 'AddDefaultRoles'])->assertSuccessful();
    $files = File::glob($this->centralDir.'/*_add_default_roles.php');
    expect($files)->toHaveCount(1);
    $content = file_get_contents($files[0]);
    expect($content)->toContain('extends DataMigration');
    expect($content)->toContain('MigrationScope::Central');
});

it('creates a tenant data migration file', function () {
    $this->artisan('make:data-migration', ['name' => 'BackfillDeviceStatus', '--scope' => 'tenant'])->assertSuccessful();
    $files = File::glob($this->tenantDir.'/*_backfill_device_status.php');
    expect($files)->toHaveCount(1);
    $content = file_get_contents($files[0]);
    expect($content)->toContain('MigrationScope::Tenant');
});

it('creates a migration with a type', function () {
    $this->artisan('make:data-migration', ['name' => 'BackfillEmails', '--type' => 'backfill'])->assertSuccessful();
    $files = File::glob($this->centralDir.'/*_backfill_emails.php');
    $content = file_get_contents($files[0]);
    expect($content)->toContain('MigrationType::Backfill');
});

it('creates directories if they do not exist', function () {
    if (is_dir($this->centralDir)) {
        File::deleteDirectory($this->centralDir);
    }
    $this->artisan('make:data-migration', ['name' => 'SeedConfig'])->assertSuccessful();
    expect(is_dir($this->centralDir))->toBeTrue();
});

it('generates a timestamped filename', function () {
    $this->artisan('make:data-migration', ['name' => 'AddRoles'])->assertSuccessful();
    $files = File::glob($this->centralDir.'/*.php');
    $filename = basename($files[0]);
    expect($filename)->toMatch('/^\d{4}_\d{2}_\d{2}_\d{6}_add_roles\.php$/');
});
