<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Database;

use App\Infrastructure\Database\PdoConnection;
use PDO;
use PHPUnit\Framework\TestCase;

class PdoConnectionTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $env;

    protected function setUp(): void
    {
        $this->env = $_ENV;
        PdoConnection::reset();
    }

    protected function tearDown(): void
    {
        $_ENV = $this->env;
        PdoConnection::reset();
    }

    public function testDevolveAInstanciaInjetada(): void
    {
        $pdo = new PDO('sqlite::memory:');
        PdoConnection::setInstance($pdo);

        $this->assertSame($pdo, PdoConnection::getInstance());
    }

    public function testFalhaDeConexaoViraRuntimeExceptionSemVazarOPdo(): void
    {
        // Porta 1 em loopback: recusa na hora, sem esperar timeout.
        $_ENV['DB_HOST'] = '127.0.0.1';
        $_ENV['DB_PORT'] = '1';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Falha na conexão com o banco de dados');

        PdoConnection::getInstance();
    }
}
