<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();

        // Trava de segurança: RefreshDatabase apaga tudo. Nunca rodar fora do banco de teste.
        $database = (string) $app['config']->get('database.connections.pgsql.database');
        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException("Recusando rodar testes no banco [{$database}].");
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // O Sanctum só inicia sessão para requests vindos de um domínio stateful.
        $this->withHeader('Referer', 'http://localhost');
    }
}
