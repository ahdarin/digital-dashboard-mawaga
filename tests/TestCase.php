<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Safeguard KI-15: pastikan test TIDAK PERNAH menyentuh database
     * development ("digidaw") - hard-abort di setUp() sebelum RefreshDatabase
     * (atau test manapun) sempat menulis/migrate apapun, walau phpunit.xml
     * atau .env.testing suatu saat berubah/salah konfigurasi lagi.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (! app()->environment('testing')) {
            throw new RuntimeException(
                'Test dijalankan tanpa APP_ENV=testing - dibatalkan demi keamanan database.'
            );
        }

        $dbName = DB::connection()->getDatabaseName();

        if ($dbName === 'digidaw' || ! str_contains($dbName, 'test')) {
            throw new RuntimeException(
                "Test connection menunjuk ke database \"{$dbName}\" yang terlihat seperti database development, ".
                'bukan database testing (nama harus mengandung "test"). Dibatalkan demi keamanan data development. '.
                'Cek phpunit.xml / .env.testing.'
            );
        }
    }
}
