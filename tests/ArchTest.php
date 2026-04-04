<?php

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

arch('source code uses strict types')
    ->expect('H3mantd\DataMigrations')
    ->toUseStrictTypes();
