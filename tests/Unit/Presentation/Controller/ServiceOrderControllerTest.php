<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation\Controller;

use App\Application\UseCase\ServiceOrder\CreateServiceOrderUseCase;
use App\Application\UseCase\ServiceOrder\GetServiceOrderUseCase;
use App\Application\UseCase\ServiceOrder\ListServiceOrdersByCustomerUseCase;
use App\Application\UseCase\ServiceOrder\ListServiceOrdersUseCase;
use App\Domain\Aggregate\ServiceOrder;
use App\Domain\Entity\Customer;
use App\Domain\Entity\Vehicle;
use App\Domain\Repository\ServiceOrderRepositoryInterface;
use App\Domain\ValueObject\Document;
use App\Domain\ValueObject\LicensePlate;
use App\Presentation\Controller\ServiceOrderController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Ownership rule of GET /api/service-orders/{id}, GET /api/service-orders/me and the
 * mandatory webhook token (CONTRATOS.md §5).
 */
class ServiceOrderControllerTest extends TestCase
{
    private MockObject&ServiceOrderRepositoryInterface $orderRepo;
    private ServiceOrderController $controller;

    protected function setUp(): void
    {
        $this->orderRepo = $this->createMock(ServiceOrderRepositoryInterface::class);

        $this->controller = new ServiceOrderController(
            $this->createMock(CreateServiceOrderUseCase::class),
            new GetServiceOrderUseCase($this->orderRepo),
            new ListServiceOrdersByCustomerUseCase($this->orderRepo),
            new ListServiceOrdersUseCase($this->orderRepo),
        );

        http_response_code(200);
    }

    private function orderOf(string $orderId, string $customerId): ServiceOrder
    {
        $customer = Customer::create($customerId, 'João', new Document('529.982.247-25'));
        $vehicle  = Vehicle::create('veh-1', $customerId, new LicensePlate('ABC-1234'), 'Toyota', 'Corolla', 2020);

        return ServiceOrder::create($orderId, $customer, $vehicle);
    }

    /**
     * @param array<string, string> $params
     * @param array<string, mixed>  $claims
     */
    private function call(string $method, array $params = [], array $claims = []): mixed
    {
        ob_start();
        $this->controller->$method($params, $claims);

        return json_decode((string)ob_get_clean(), true);
    }

    public function testAdminReadsAnyOrder(): void
    {
        $this->orderRepo->method('findById')->willReturn($this->orderOf('order-1', 'cust-1'));

        $body = $this->call('show', ['id' => 'order-1'], ['sub' => 'admin', 'role' => 'admin']);

        $this->assertSame(200, http_response_code());
        $this->assertIsArray($body);
        $this->assertSame('order-1', $body['id']);
    }

    public function testCustomerReadsTheirOwnOrder(): void
    {
        $this->orderRepo->method('findById')->willReturn($this->orderOf('order-1', 'cust-1'));

        $body = $this->call('show', ['id' => 'order-1'], ['sub' => 'cust-1', 'role' => 'customer']);

        $this->assertSame(200, http_response_code());
        $this->assertIsArray($body);
        $this->assertSame('order-1', $body['id']);
    }

    public function testCustomerReadingSomeoneElsesOrderGets404NotForbidden(): void
    {
        $this->orderRepo->method('findById')->willReturn($this->orderOf('order-1', 'cust-1'));

        $body = $this->call('show', ['id' => 'order-1'], ['sub' => 'cust-2', 'role' => 'customer']);

        // 403 would confirm that the order exists — the contract requires 404.
        $this->assertSame(404, http_response_code());
        $this->assertIsArray($body);
        $this->assertArrayHasKey('error', $body);
        $this->assertArrayNotHasKey('id', $body);
    }

    public function testMissingOrderIs404(): void
    {
        $this->orderRepo->method('findById')->willReturn(null);

        $this->call('show', ['id' => 'order-x'], ['sub' => 'admin', 'role' => 'admin']);

        $this->assertSame(404, http_response_code());
    }

    public function testMineListsOnlyTheOrdersOfTheTokenSubject(): void
    {
        $this->orderRepo->expects($this->once())
            ->method('findByCustomerId')
            ->with('cust-7')
            ->willReturn([$this->orderOf('order-9', 'cust-7')]);

        $body = $this->call('mine', [], ['sub' => 'cust-7', 'role' => 'customer']);

        $this->assertSame(200, http_response_code());
        $this->assertIsArray($body);
        $this->assertCount(1, $body);
        $this->assertSame('order-9', $body[0]['id']);
    }

    public function testMineRejectsATokenWithoutSubject(): void
    {
        $this->orderRepo->expects($this->never())->method('findByCustomerId');

        $this->call('mine', [], ['role' => 'customer']);

        $this->assertSame(401, http_response_code());
    }
}
