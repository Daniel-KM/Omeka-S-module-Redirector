<?php declare(strict_types=1);

/**
 * Bootstrap file for Redirector module tests.
 *
 * Uses Common module Bootstrap helper for test setup.
 */

require dirname(__DIR__, 3) . '/modules/Common/test/Bootstrap.php';

\CommonTest\Bootstrap::bootstrap(
    [
        'Common',
        'Redirector',
    ],
    'RedirectorTest',
    __DIR__ . '/RedirectorTest'
);
