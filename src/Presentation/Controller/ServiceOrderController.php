<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Application\DTO\ServiceOrder\CreateServiceOrderInputDTO;
use App\Application\UseCase\ServiceOrder\CancelServiceOrderUseCase;
use App\Application\UseCase\ServiceOrder\CreateServiceOrderUseCase;
use App\Application\UseCase\ServiceOrder\DeliverServiceOrderUseCase;
use App\Application\UseCase\ServiceOrder\GetServiceOrderUseCase;
use App\Application\UseCase\ServiceOrder\ListServiceOrdersByCustomerUseCase;
use App\Application\UseCase\ServiceOrder\ListServiceOrdersUseCase;
use App\Domain\Exception\ConcurrencyException;
use App\Domain\Exception\DomainException;
use App\Domain\Exception\NotFoundException;

/**
 * Ordens de serviço (docs/fase-4/CONTRATOS.md §4, no oficina-api). Na Fase 4 o status é movido
 * pela saga: daqui saem só a abertura (que inicia a saga), a entrega e o cancelamento.
 */
class ServiceOrderController
{
    public function __construct(
        private readonly CreateServiceOrderUseCase $createUseCase,
        private readonly GetServiceOrderUseCase $getUseCase,
        private readonly ListServiceOrdersByCustomerUseCase $listByCustomerUseCase,
        private readonly ListServiceOrdersUseCase $listUseCase,
        private readonly ?CancelServiceOrderUseCase $cancelUseCase = null,
        private readonly ?DeliverServiceOrderUseCase $deliverUseCase = null,
        /** Quem lê o corpo; os testes passam um, em produção é php://input. */
        private readonly ?\Closure $bodyReader = null,
    ) {
    }

    public function index(): void
    {
        http_response_code(200);
        echo json_encode($this->listUseCase->execute());
    }

    /**
     * @param array<string, string> $params
     * @param array<string, mixed>  $claims
     */
    public function show(array $params, array $claims = []): void
    {
        try {
            $order = $this->getUseCase->execute($params['id']);

            // A customer may only read their own order. We answer 404 (not 403) on purpose:
            // a 403 would confirm that an order with this id exists.
            if (($claims['role'] ?? null) === 'customer'
                && $order->getCustomer()->getId() !== ($claims['sub'] ?? null)
            ) {
                http_response_code(404);
                echo json_encode(['error' => "Ordem de serviço não encontrada: {$params['id']}"]);
                return;
            }

            http_response_code(200);
            echo json_encode($order->toArray() + ['saga' => $this->getUseCase->saga($order->getId())]);
        } catch (NotFoundException $e) {
            http_response_code(404);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string, string> $params
     * @param array<string, mixed>  $claims
     */
    public function mine(array $params = [], array $claims = []): void
    {
        $customerId = $claims['sub'] ?? null;

        if (!is_string($customerId) || $customerId === '') {
            http_response_code(401);
            echo json_encode(['error' => 'Token sem identificação de cliente.']);
            return;
        }

        http_response_code(200);
        echo json_encode($this->listByCustomerUseCase->execute($customerId));
    }

    /** Abre a OS e inicia a saga dela. */
    public function store(string $correlationId = ''): void
    {
        $body = $this->parseBody();

        if (empty($body['customer_id']) || empty($body['vehicle_id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'customer_id e vehicle_id são obrigatórios.']);
            return;
        }

        $this->respond(function () use ($body, $correlationId): array {
            $order = $this->createUseCase->execute(new CreateServiceOrderInputDTO(
                customerId: (string)$body['customer_id'],
                vehicleId: (string)$body['vehicle_id'],
                description: is_string($body['description'] ?? null) ? $body['description'] : '',
            ), $correlationId);

            return $order->toArray() + ['saga' => $this->getUseCase->saga($order->getId())];
        }, 201);
    }

    /** @param array<string, string> $params */
    public function deliver(array $params): void
    {
        $this->respond(fn (): array => $this->deliverUseCase()->execute($params['id'])->toArray());
    }

    /** @param array<string, string> $params */
    public function cancel(array $params): void
    {
        $body = $this->parseBody();
        $this->respond(function () use ($params, $body): array {
            $order = $this->cancelUseCase()->execute($params['id'], is_string($body['reason'] ?? null) ? $body['reason'] : '');

            return $order->toArray() + ['saga' => $this->getUseCase->saga($order->getId())];
        });
    }

    /** @param callable(): array<string, mixed> $action */
    private function respond(callable $action, int $success = 200): void
    {
        try {
            $data = $action();
            http_response_code($success);
            echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (NotFoundException $e) {
            http_response_code(404);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (ConcurrencyException) {
            http_response_code(409);
            echo json_encode(['error' => 'A ordem de serviço mudou enquanto a operação corria. Tente de novo.']);
        } catch (DomainException $e) {
            http_response_code(422);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    private function cancelUseCase(): CancelServiceOrderUseCase
    {
        return $this->cancelUseCase ?? throw new \LogicException('Cancelamento não configurado.');
    }

    private function deliverUseCase(): DeliverServiceOrderUseCase
    {
        return $this->deliverUseCase ?? throw new \LogicException('Entrega não configurada.');
    }

    /** @return array<string, mixed> */
    private function parseBody(): array
    {
        $raw  = $this->bodyReader !== null ? ($this->bodyReader)() : file_get_contents('php://input');
        $data = json_decode($raw ?: '{}', true);

        return is_array($data) ? $data : [];
    }
}
