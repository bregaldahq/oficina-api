<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Saga;

use App\Domain\Exception\DomainException;
use App\Domain\Saga\Saga;
use App\Domain\Saga\SagaTimeouts;
use PHPUnit\Framework\TestCase;

class SagaTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-16T10:00:00Z');
    }

    private function saga(): Saga
    {
        $saga = Saga::start('s', 'os', 'c', ['id' => 'c1', 'name' => 'Ana', 'email' => 'a@x'], ['license_plate' => 'P', 'brand' => 'B', 'model' => 'M'], 'd', $this->now, $this->now);
        $saga->releaseCommands();

        return $saga;
    }

    public function testCompensacaoDesconhecidaERecusada(): void
    {
        $this->expectException(DomainException::class);

        $this->saga()->compensate('x', ['billing.qualquer-coisa' => []], $this->now, $this->now);
    }

    public function testSoAvancaCompensaOuConcluiEmAndamento(): void
    {
        $saga = $this->saga();
        $saga->complete($this->now);

        foreach ([
            fn () => $saga->advance(Saga::STEP_BUDGET, null, $this->now),
            fn () => $saga->compensate('x', [], $this->now, $this->now),
            fn () => $saga->complete($this->now),
        ] as $action) {
            try {
                $action();
                $this->fail('saga concluída aceitou mudança');
            } catch (DomainException $e) {
                $this->assertStringContainsString('COMPLETED', $e->getMessage());
            }
        }
    }

    public function testReenvioSoEmCompensacao(): void
    {
        $this->expectException(DomainException::class);

        $this->saga()->retryCompensation($this->now, 60, 3);
    }

    public function testConfirmacaoForaDeCompensacaoOuNaoEsperada(): void
    {
        $saga = $this->saga();
        $this->assertFalse($saga->confirm('execution.cancelled', $this->now), 'não está compensando');

        $saga->compensate('x', ['execution.cancel' => ['reason' => 'x']], $this->now, $this->now);
        $this->assertFalse($saga->confirm('payment.refunded', $this->now), 'não era esperada');
        $this->assertFalse($saga->confirm('diagnosis.started', $this->now), 'nem é confirmação');
        $this->assertTrue($saga->confirm('execution.cancelled', $this->now));
        $this->assertSame(Saga::COMPENSATED, $saga->getState());
    }

    public function testReenvioComEsperaCrescente(): void
    {
        $saga = $this->saga();
        $saga->compensate('x', ['execution.cancel' => ['reason' => 'x']], $this->now, $this->now);
        $saga->releaseCommands();

        $saga->retryCompensation($this->now, 60, 5);

        $this->assertSame('2026-11-16 10:02:00', $saga->getDeadlineAt()?->format('Y-m-d H:i:s'), 'segunda tentativa: 2 x 60 s');
        $this->assertSame([['type' => 'execution.cancel', 'payload' => ['reason' => 'x']]], $saga->releaseCommands());
    }

    public function testPrazosPeloAmbiente(): void
    {
        $timeouts = SagaTimeouts::fromEnv(['SAGA_APPROVAL_TIMEOUT_SECONDS' => '600', 'SAGA_PAYMENT_TIMEOUT_SECONDS' => 'abc']);

        $this->assertSame(600, $timeouts->approval);
        $this->assertSame(86400, $timeouts->payment, 'valor inválido cai no padrão do contrato');
        $this->assertSame(300, $timeouts->budget);
        $this->assertSame(5, $timeouts->maxCompensationAttempts);
    }

    public function testGettersParaPersistencia(): void
    {
        $saga = $this->saga();

        $this->assertSame(['s', 'os', 'c', 0], [$saga->getId(), $saga->getOsId(), $saga->getCorrelationId(), $saga->getAttempts()]);
        $this->assertEquals($this->now, $saga->getCreatedAt());
        $this->assertEquals($this->now, $saga->getUpdatedAt());
        $this->assertNull($saga->getFailedStep());
    }
}
