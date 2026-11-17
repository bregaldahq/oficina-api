<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Exception\InvalidMessageException;
use App\Domain\Messaging\Message;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * O envelope é contrato entre os três serviços (docs/fase-4/CONTRATOS.md §5.1, no oficina-api).
 * O JSON literal abaixo é o mesmo nos testes dos outros serviços: mudou aqui, mudou lá.
 */
class MessageTest extends TestCase
{
    private const CONTRACT_JSON = '{"id":"7b1c0d6e-0000-4000-8000-000000000001","type":"diagnosis.completed",'
        . '"version":1,"producer":"execucao","saga_id":"saga-1","os_id":"os-1",'
        . '"occurred_at":"2026-11-10T14:03:22.512Z","correlation_id":"corr-1",'
        . '"payload":{"task_id":"os-1","items":[{"kind":"service","id":"svc-1","quantity":1}]},'
        . '"trace":{"newrelic":"{\\"traceparent\\":\\"00-abc-def-01\\"}"}}';

    public function testJsonDoContratoIdaEVoltaByteAByte(): void
    {
        $message = Message::fromJson(self::CONTRACT_JSON);

        $this->assertSame('diagnosis.completed', $message->type);
        $this->assertSame('execucao', $message->producer);
        $this->assertSame('saga-1', $message->sagaId);
        $this->assertSame('os-1', $message->osId);
        $this->assertSame('corr-1', $message->correlationId);
        $this->assertSame(1, $message->version);
        $this->assertSame('{"traceparent":"00-abc-def-01"}', $message->trace);
        $this->assertSame('svc-1', $message->payload['items'][0]['id']);
        $this->assertSame(self::CONTRACT_JSON, $message->toJson());
    }

    public function testPayloadVazioEUmObjetoETraceAusenteSomeDoJson(): void
    {
        $message = new Message('i', 'repair.started', 'execucao', 's', 'o', '2026-11-10T14:03:22.512Z', 'c');

        $json = $message->toJson();

        $this->assertStringContainsString('"payload":{}', $json);
        $this->assertStringNotContainsString('"trace"', $json);
        $this->assertSame('t', $message->withTrace('t')->trace);
        $this->assertNull($message->trace, 'withTrace não altera a original');
    }

    /** @return array<string, array{0: string}> */
    public static function invalidas(): array
    {
        return [
            'não é json'         => ['isto não é json'],
            'lista'              => ['"texto"'],
            'sem id'             => ['{"type":"x","producer":"os","saga_id":"s","os_id":"o","occurred_at":"t"}'],
            'sem saga'           => ['{"id":"i","type":"x","producer":"os","os_id":"o","occurred_at":"t"}'],
            'tipo vazio'         => ['{"id":"i","type":"","producer":"os","saga_id":"s","os_id":"o","occurred_at":"t"}'],
            'payload não objeto' => ['{"id":"i","type":"x","producer":"os","saga_id":"s","os_id":"o","occurred_at":"t","payload":"p"}'],
        ];
    }

    #[DataProvider('invalidas')]
    public function testMensagemForaDoEnvelopeERecusada(string $json): void
    {
        $this->expectException(InvalidMessageException::class);

        Message::fromJson($json);
    }

    public function testCamposOpcionaisTemPadrao(): void
    {
        $message = Message::fromJson('{"id":"i","type":"x","producer":"os","saga_id":"s","os_id":"o","occurred_at":"t"}');

        $this->assertSame('', $message->correlationId);
        $this->assertSame([], $message->payload);
        $this->assertNull($message->trace);
        $this->assertSame(Message::VERSION, $message->version);
    }
}
