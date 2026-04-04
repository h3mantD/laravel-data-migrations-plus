<?php

declare(strict_types=1);

namespace H3mantd\DataMigrations\Services;

class RunResult
{
    /**
     * @param  list<string>  $successful
     * @param  list<string>  $failed
     * @param  list<string>  $pretended
     */
    public function __construct(
        public readonly array $successful = [],
        public readonly array $failed = [],
        public readonly array $pretended = [],
        public readonly bool $lockFailed = false,
    ) {}
}
