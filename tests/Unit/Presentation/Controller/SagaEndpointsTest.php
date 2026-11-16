<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation\Controller;

use App\Application\UseCase\ServiceOrder\CancelServiceOrderUseCase;
use App\Application\UseCase\ServiceOrder\CreateServiceOrderUseCase;
use App\Application\UseCase\ServiceOrder\DeliverServiceOrderUseCase;
use App\Application\UseCase\ServiceOrder\GetServiceOrderUseCase;
use App\Application\UseCase\ServiceOrder\ListServiceOrdersByCustomerUseCase;
use App\Application\UseCase\ServiceOrder\ListServiceOrdersUseCase;
use App\Domain\Saga\Saga;
use App\Infrastructure\Event\InMemoryEventDispatcher;
use App\Infrastructure\Repository\PdoCustomerRepository;
use App\Infrastructure\Repository\PdoVehicleRepository;
use App\Presentation\Controller\InternalCustomerController;
use App\Presentation\Controller\ServiceOrderController;
use PHPUnit\Framework\TestCase;
use Tests\Support\SagaHarness;
use Tests\Support\SequenceUuid;

/** As rotas da OS na Fase 4: abrir (inicia a saga), consultar com a saga, entregar e cancelar. */
class SagaEndpointsTest extends TestCase
{
    private SagaHarness $h;

    protected function setUp(): void
    {
        $this->h = new SagaHarness();
    }

    private function controller(): ServiceOrderController
    {
        return new ServiceOrderController(
            new CreateServiceOrderUseCase(new PdoCustomerRepository(), new PdoVehicleRepository(), new SequenceUuid(), $this->h->orchestrator, $this->h->commit, $this->h->clock),
            new GetServiceOrderUseCase($this->h->orders, $this->h->sagas),
            new ListServiceOrdersByCustomerUseCase($this->h->orders),
            new ListServiceOrdersUseCase($this->h->orders),
            new CancelServiceOrderUseCase($this->h->sagas, $this->h->orders, $this->h->orchestrator, $this->h->commit, $this->h->clock),
            new DeliverServiceOrderUseCase($this->h->orders, new InMemoryEventDispatcher()),
        );
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function call(object $controller, string $action, mixed ...$args): array
    {
        http_response_code(200);
        ob_start();
        $controller->{$action}(...$args);
        $body = json_decode((string)ob_get_clean(), true);

        return [(int)http_response_code(), is_array($body) ? $body : []];
    }

    public function testConsultaTrazAndamentoDaSaga(): void
    {
        $osId = $this->h->until(Saga::STEP_PAYMENT);

        [$status, $body] = $this->call($this->controller(), 'show', ['id' => $osId], ['role' => 'customer', 'sub' => SagaHarness::CUSTOMER]);

        $this->assertSame(200, $status);
        $this->assertSame('AWAITING_PAYMENT', $body['status']);
        $this->assertSame('https://pagar', $body['payment_url']);
        $this->assertSame('bud-1', $body['budget_id']);
        $this->assertSame(Saga::STEP_PAYMENT, $body['saga']['step']);
    }

    public function testEntregaSoDepoisDeTerminado(): void
    {
        $osId = $this->h->until(Saga::STEP_REPAIR);
        $this->assertSame(422, $this->call($this->controller(), 'deliver', ['id' => $osId])[0]);

        $this->h->reply($osId, 'repair.completed');
        [$status, $body] = $this->call($this->controller(), 'deliver', ['id' => $osId]);

        $this->assertSame([200, 'DELIVERED'], [$status, $body['status']]);
        $this->assertSame(404, $this->call($this->controller(), 'deliver', ['id' => 'nao-existe'])[0]);
    }

    public function testCancelamentoSemMotivoE422EOsInexistenteE404(): void
    {
        $osId = $this->h->create()->getId();

        $this->assertSame(422, $this->call($this->controller(), 'cancel', ['id' => $osId])[0], 'sem corpo, sem motivo');
        $this->assertSame(404, $this->call($this->controller(), 'cancel', ['id' => 'nao-existe'])[0]);
        $this->assertSame('RECEIVED', $this->h->order($osId)->getStatus());
    }

    public function testAberturaSemClienteOuVeiculoE400(): void
    {
        $this->assertSame(400, $this->call($this->controller(), 'store')[0]);
    }

    public function testConsultaInternaDeClientePorDocumento(): void
    {
        $controller = new InternalCustomerController(new PdoCustomerRepository(), 'token-interno');

        [$status, $body] = $this->call($controller, 'byDocument', ['document' => '529.982.247-25'], 'token-interno');
        $this->assertSame(200, $status);
        $this->assertSame(['id' => 'cli-1', 'name' => 'Maria Silva', 'document' => '52998224725', 'status' => 'ACTIVE'], $body);

        $this->assertSame(401, $this->call($controller, 'byDocument', ['document' => '52998224725'], 'errado')[0]);
        $this->assertSame(400, $this->call($controller, 'byDocument', ['document' => '123'], 'token-interno')[0]);
        $this->assertSame(404, $this->call($controller, 'byDocument', ['document' => '39053344705'], 'token-interno')[0]);
        $this->assertSame(401, $this->call(new InternalCustomerController(new PdoCustomerRepository(), ''), 'byDocument', ['document' => '52998224725'], '')[0], 'sem token configurado, fechado');
    }
}
