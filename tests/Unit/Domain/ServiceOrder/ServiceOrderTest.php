<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\ServiceOrder;

use App\Domain\Aggregate\ServiceOrder;
use App\Domain\Entity\Customer;
use App\Domain\Entity\Vehicle;
use App\Domain\Event\ServiceOrderCreatedEvent;
use App\Domain\Event\ServiceOrderStatusChangedEvent;
use App\Domain\Exception\InvalidDocumentException;
use App\Domain\Exception\InvalidStatusTransitionException;
use App\Domain\ValueObject\Document;
use App\Domain\ValueObject\LicensePlate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A máquina de estados da OS na Fase 4 (docs/fase-4/CONTRATOS.md §6.4, no oficina-api). */
class ServiceOrderTest extends TestCase
{
    private function order(): ServiceOrder
    {
        return ServiceOrder::create(
            'os-1',
            Customer::create('cust-1', 'João Silva', new Document('529.982.247-25')),
            Vehicle::create('veh-1', 'cust-1', new LicensePlate('ABC-1234'), 'Toyota', 'Corolla', 2020),
        );
    }

    /** @return list<string> "DE→PARA" de cada evento de mudança de status */
    private function transitions(ServiceOrder $order): array
    {
        $out = [];
        foreach ($order->releaseEvents() as $event) {
            if ($event instanceof ServiceOrderStatusChangedEvent) {
                $out[] = $event->previousStatus . "→" . $event->newStatus;
            }
        }

        return $out;
    }

    private function awaitingPayment(): ServiceOrder
    {
        $order = $this->order();
        $order->changeToDiagnosis();
        $order->sendForApproval('budget-1', 558.0);
        $order->approve();

        return $order;
    }

    public function testNasceRecebidaEAvisaACriacao(): void
    {
        $order = $this->order();

        $this->assertSame('RECEIVED', $order->getStatus());
        $this->assertTrue($order->isNew());
        $this->assertInstanceOf(ServiceOrderCreatedEvent::class, $order->releaseEvents()[0]);
    }

    public function testCaminhoFelizDaSaga(): void
    {
        $order = $this->order();
        $order->releaseEvents();

        $order->changeToDiagnosis();
        $order->sendForApproval('budget-1', 558.004);
        $order->approve();
        $order->recordPaymentLink('https://pagar');
        $this->assertSame('https://pagar', $order->getPaymentUrl());
        $order->startExecution();
        $order->finish();
        $order->deliver();

        $this->assertSame([
            'RECEIVED→DIAGNOSIS',
            'DIAGNOSIS→AWAITING_APPROVAL',
            'AWAITING_APPROVAL→AWAITING_PAYMENT',
            'AWAITING_PAYMENT→EXECUTING',
            'EXECUTING→FINISHED',
            'FINISHED→DELIVERED',
        ], $this->transitions($order));
        $this->assertSame('budget-1', $order->getBudgetId());
        $this->assertSame(558.0, $order->getTotalAmount(), 'o total vem do orçamento, arredondado');
        $this->assertNull($order->getPaymentUrl(), 'pago: o link deixa de valer');
    }

    public function testRecusaDoOrcamento(): void
    {
        $order = $this->order();
        $order->changeToDiagnosis();
        $order->sendForApproval('budget-1', 10.0);
        $order->releaseEvents();

        $order->reject();

        $this->assertSame(['AWAITING_APPROVAL→REJECTED'], $this->transitions($order));
        $this->assertFalse($order->isCancellable());
    }

    /** @return array<string, array{0: callable(ServiceOrder): void}> */
    public static function cancelaveis(): array
    {
        return [
            'recebida'             => [fn (ServiceOrder $o) => null],
            'em diagnóstico'       => [fn (ServiceOrder $o) => $o->changeToDiagnosis()],
            'aguardando aprovação' => [function (ServiceOrder $o): void {
                $o->changeToDiagnosis();
                $o->sendForApproval('b', 1);
            }],
            'aguardando pagamento' => [function (ServiceOrder $o): void {
                $o->changeToDiagnosis();
                $o->sendForApproval('b', 1);
                $o->approve();
                $o->recordPaymentLink('https://pagar');
            }],
            'em execução' => [function (ServiceOrder $o): void {
                $o->changeToDiagnosis();
                $o->sendForApproval('b', 1);
                $o->approve();
                $o->startExecution();
            }],
        ];
    }

    /** @param callable(ServiceOrder): void $setup */
    #[DataProvider('cancelaveis')]
    public function testCancelaAteOFimDoReparo(callable $setup): void
    {
        $order = $this->order();
        $setup($order);
        $this->assertTrue($order->isCancellable());

        $order->cancel();

        $this->assertSame('CANCELLED', $order->getStatus());
        $this->assertNull($order->getPaymentUrl());
        $this->assertFalse($order->isCancellable());
    }

    public function testNaoCancelaDepoisDeTerminado(): void
    {
        $order = $this->awaitingPayment();
        $order->startExecution();
        $order->finish();

        $this->expectException(InvalidStatusTransitionException::class);
        $order->cancel();
    }

    /** @return array<string, array{0: callable(ServiceOrder): void}> */
    public static function foraDeHora(): array
    {
        return [
            'orçamento antes do diagnóstico' => [fn (ServiceOrder $o) => $o->sendForApproval('b', 1)],
            'aprovar sem orçamento'          => [fn (ServiceOrder $o) => $o->approve()],
            'link sem aprovação'             => [fn (ServiceOrder $o) => $o->recordPaymentLink('x')],
            'executar sem pagar'             => [fn (ServiceOrder $o) => $o->startExecution()],
            'terminar sem executar'          => [fn (ServiceOrder $o) => $o->finish()],
            'entregar sem terminar'          => [fn (ServiceOrder $o) => $o->deliver()],
            'recusar sem orçamento'          => [fn (ServiceOrder $o) => $o->reject()],
        ];
    }

    /** @param callable(ServiceOrder): void $action */
    #[DataProvider('foraDeHora')]
    public function testTransicaoInvalidaENegadaSemMudarNada(callable $action): void
    {
        $order = $this->order();

        try {
            $action($order);
            $this->fail('esperava InvalidStatusTransitionException');
        } catch (InvalidStatusTransitionException $e) {
            $this->assertStringContainsString("'RECEIVED'", $e->getMessage());
        }

        $this->assertSame('RECEIVED', $order->getStatus());
        $this->assertNull($order->getBudgetId());
    }

    public function testExecutarDuasVezesENegado(): void
    {
        $order = $this->awaitingPayment();
        $order->startExecution();

        $this->expectException(InvalidStatusTransitionException::class);
        $order->startExecution();
    }

    public function testReconstituteNaoGeraEvento(): void
    {
        $order = ServiceOrder::reconstitute(
            'os-9',
            Customer::create('c', 'Ana', new Document('529.982.247-25')),
            Vehicle::create('v', 'c', new LicensePlate('ABC-1234'), 'Fiat', 'Uno', 2010),
            'AWAITING_PAYMENT',
            300.5,
            new \DateTimeImmutable('2026-11-16 10:00:00'),
            'budget-2',
            'https://pagar',
        );

        $this->assertSame([], $order->releaseEvents());
        $this->assertFalse($order->isNew());
        $this->assertSame([
            'id', 'customer', 'vehicle', 'status', 'total_amount', 'budget_id', 'payment_url', 'created_at',
        ], array_keys($order->toArray()));
        $this->assertSame('https://pagar', $order->toArray()['payment_url']);
    }

    public function testDocumentoInvalidoFalhaNaCriacaoDoCliente(): void
    {
        $this->expectException(InvalidDocumentException::class);

        new Document('111.111.111-11');
    }
}
