<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation\Request;

use App\Presentation\Request\RequestValidationException;
use App\Presentation\Request\RequestValidator;
use PHPUnit\Framework\TestCase;

class RequestValidatorTest extends TestCase
{
    public function testValoresValidos(): void
    {
        $body = ['nome' => '  Ana  ', 'preco' => '10.5', 'ano' => '2020', 'vazio' => ''];

        $this->assertSame('Ana', RequestValidator::requireString($body, 'nome'));
        $this->assertSame(10.5, RequestValidator::requireFloat($body, 'preco'));
        $this->assertSame(2020, RequestValidator::requireInt($body, 'ano'));
        $this->assertSame('padrão', RequestValidator::optionalString($body, 'ausente', 'padrão'));
        $this->assertSame(7, RequestValidator::optionalInt($body, 'ausente', 7));
        $this->assertSame(2020, RequestValidator::optionalInt($body, 'ano'));
    }

    /** @return array<string, array{0: callable(): mixed}> */
    public static function invalidos(): array
    {
        return [
            'texto ausente'    => [fn () => RequestValidator::requireString([], 'nome')],
            'texto longo'      => [fn () => RequestValidator::requireString(['nome' => str_repeat('a', 300)], 'nome')],
            'número inválido'  => [fn () => RequestValidator::requireFloat(['preco' => 'dez'], 'preco')],
            'inteiro inválido' => [fn () => RequestValidator::requireInt(['ano' => 'dois mil'], 'ano')],
        ];
    }

    /** @param callable(): mixed $call */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidos')]
    public function testValorInvalidoLancaEmVezDeEncerrarOProcesso(callable $call): void
    {
        $this->expectException(RequestValidationException::class);

        $call();
    }
}
