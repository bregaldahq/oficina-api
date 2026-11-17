<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Config;

use App\Infrastructure\Config\EnvLoader;
use PHPUnit\Framework\TestCase;

class EnvLoaderTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = (string)tempnam(sys_get_temp_dir(), 'env');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        unset($_ENV['ENVLOADER_A'], $_ENV['ENVLOADER_B']);
    }

    public function testCarregaChavesEIgnoraComentariosELinhasSemIgual(): void
    {
        file_put_contents($this->file, "# comentario\nENVLOADER_A = um\nlinha sem igual\nENVLOADER_B=a=b\n");

        EnvLoader::loadFile($this->file);

        $this->assertSame('um', $_ENV['ENVLOADER_A']);
        $this->assertSame('a=b', $_ENV['ENVLOADER_B']);
    }

    public function testArquivoAusenteNaoFazNada(): void
    {
        EnvLoader::loadFile($this->file . '.nao-existe');

        $this->assertArrayNotHasKey('ENVLOADER_A', $_ENV);
    }

    public function testRequireAceitaQuandoTodasExistem(): void
    {
        $_ENV['ENVLOADER_A'] = 'x';

        EnvLoader::require(['ENVLOADER_A']);

        $this->addToAssertionCount(1);
    }

    public function testRequireListaAsQueFaltam(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ENVLOADER_B');

        EnvLoader::require(['ENVLOADER_B']);
    }
}
