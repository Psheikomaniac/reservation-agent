<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use Tests\TestCase;

/**
 * Guards against PHP deprecation notices triggered while loading
 * `config/database.php`. Such notices are written to HTTP output by PHP and
 * corrupt every Inertia XHR response (the leading HTML breaks JSON parsing
 * and the page never swaps). Caught us once on PHP 8.5 with PDO::MYSQL_ATTR_SSL_CA.
 */
class DatabaseConfigTest extends TestCase
{
    public function test_loading_database_config_emits_no_php_deprecations(): void
    {
        $deprecations = [];

        $previous = set_error_handler(function (int $errno, string $errstr) use (&$deprecations): bool {
            if ($errno === E_DEPRECATED || $errno === E_USER_DEPRECATED) {
                $deprecations[] = $errstr;

                return true;
            }

            return false;
        });

        try {
            // Re-evaluate the file so its top-level constant lookups run again.
            include __DIR__.'/../../../config/database.php';
        } finally {
            set_error_handler($previous);
        }

        $this->assertSame(
            [],
            $deprecations,
            'config/database.php triggered PHP deprecation notices: '.implode(' | ', $deprecations)
        );
    }
}
