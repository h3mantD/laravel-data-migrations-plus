<?php

use H3mantd\DataMigrations\Helpers\DataMigrationHelpers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::create('test_records', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email')->nullable();
        $table->string('role')->nullable();
        $table->timestamps();
    });
    $this->helpers = new DataMigrationHelpers(DB::connection());
});

afterEach(function () {
    Schema::dropIfExists('test_records');
});

describe('ensureRecord', function () {
    it('inserts a record when it does not exist', function () {
        $this->helpers->ensureRecord('test_records', ['name' => 'admin'], ['email' => 'admin@example.com']);
        expect(DB::table('test_records')->where('name', 'admin')->exists())->toBeTrue();
        expect(DB::table('test_records')->where('name', 'admin')->first()->email)->toBe('admin@example.com');
    });

    it('does not duplicate when record already exists', function () {
        DB::table('test_records')->insert(['name' => 'admin', 'email' => 'old@example.com']);
        $this->helpers->ensureRecord('test_records', ['name' => 'admin'], ['email' => 'new@example.com']);
        expect(DB::table('test_records')->where('name', 'admin')->count())->toBe(1);
        expect(DB::table('test_records')->where('name', 'admin')->first()->email)->toBe('old@example.com');
    });
});

describe('updateWhereNull', function () {
    it('updates rows where column is null', function () {
        DB::table('test_records')->insert([
            ['name' => 'alice', 'role' => null],
            ['name' => 'bob', 'role' => 'admin'],
        ]);
        $this->helpers->updateWhereNull('test_records', 'role', 'user');
        expect(DB::table('test_records')->where('name', 'alice')->first()->role)->toBe('user');
        expect(DB::table('test_records')->where('name', 'bob')->first()->role)->toBe('admin');
    });

    it('applies additional where conditions', function () {
        DB::table('test_records')->insert([
            ['name' => 'alice', 'role' => null],
            ['name' => 'bob', 'role' => null],
        ]);
        $this->helpers->updateWhereNull('test_records', 'role', 'special', ['name' => 'alice']);
        expect(DB::table('test_records')->where('name', 'alice')->first()->role)->toBe('special');
        expect(DB::table('test_records')->where('name', 'bob')->first()->role)->toBeNull();
    });
});

describe('normalizeColumn', function () {
    it('remaps old values to new values', function () {
        DB::table('test_records')->insert([
            ['name' => 'alice', 'role' => 'admin'],
            ['name' => 'bob', 'role' => 'mod'],
            ['name' => 'charlie', 'role' => 'user'],
        ]);
        $this->helpers->normalizeColumn('test_records', 'role', [
            'admin' => 'administrator',
            'mod' => 'moderator',
        ]);
        expect(DB::table('test_records')->where('name', 'alice')->first()->role)->toBe('administrator');
        expect(DB::table('test_records')->where('name', 'bob')->first()->role)->toBe('moderator');
        expect(DB::table('test_records')->where('name', 'charlie')->first()->role)->toBe('user');
    });
});
