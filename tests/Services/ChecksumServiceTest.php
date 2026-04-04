<?php

use H3mantd\DataMigrations\Services\ChecksumService;

beforeEach(function (): void {
    $this->service = new ChecksumService;
    $this->tempDir = sys_get_temp_dir().'/data-migration-tests';
    @mkdir($this->tempDir, 0755, true);
});

afterEach(function (): void {
    array_map(unlink(...), glob($this->tempDir.'/*'));
    @rmdir($this->tempDir);
});

it('computes sha256 hash of file contents', function (): void {
    $file = $this->tempDir.'/test_migration.php';
    file_put_contents($file, '<?php echo "hello";');
    $checksum = $this->service->compute($file);
    expect($checksum)->toBe(hash('sha256', '<?php echo "hello";'));
});

it('returns different checksums for different content', function (): void {
    $file1 = $this->tempDir.'/migration_a.php';
    $file2 = $this->tempDir.'/migration_b.php';
    file_put_contents($file1, 'content-a');
    file_put_contents($file2, 'content-b');
    expect($this->service->compute($file1))->not->toBe($this->service->compute($file2));
});

it('detects drift when content changes', function (): void {
    $file = $this->tempDir.'/test_migration.php';
    file_put_contents($file, 'original content');
    $originalChecksum = $this->service->compute($file);
    file_put_contents($file, 'modified content');
    expect($this->service->hasDrifted($file, $originalChecksum))->toBeTrue();
});

it('reports no drift when content is unchanged', function (): void {
    $file = $this->tempDir.'/test_migration.php';
    file_put_contents($file, 'stable content');
    $checksum = $this->service->compute($file);
    expect($this->service->hasDrifted($file, $checksum))->toBeFalse();
});

it('throws for non-existent file', function (): void {
    $this->service->compute('/nonexistent/file.php');
})->throws(RuntimeException::class);
