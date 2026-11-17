<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Domain\Entity\Vehicle;
use App\Domain\Exception\DomainException;
use App\Domain\Repository\CustomerRepositoryInterface;
use App\Domain\Repository\VehicleRepositoryInterface;
use App\Domain\UuidGeneratorInterface;
use App\Domain\ValueObject\LicensePlate;
use App\Presentation\Request\RequestValidator;

class VehicleController
{
    public function __construct(
        private readonly VehicleRepositoryInterface $vehicleRepository,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly UuidGeneratorInterface $uuidGenerator,
        /** Quem lê o corpo; os testes passam um, em produção é php://input. */
        private readonly ?\Closure $bodyReader = null,
    ) {
    }

    public function index(): void
    {
        http_response_code(200);
        echo json_encode(array_map(fn ($v) => $v->toArray(), $this->vehicleRepository->findAll()));
    }

    /** @param array<string, string> $params */
    public function show(array $params): void
    {
        $vehicle = $this->vehicleRepository->findById($params['id']);

        if ($vehicle === null) {
            http_response_code(404);
            echo json_encode(['error' => "Veículo não encontrado: {$params['id']}"]);
            return;
        }

        http_response_code(200);
        echo json_encode($vehicle->toArray());
    }

    public function store(): void
    {
        $body = $this->parseBody();

        try {
            $customerId = RequestValidator::requireString($body, 'customer_id');
            $plate      = new LicensePlate(RequestValidator::requireString($body, 'license_plate'));
            $brand      = RequestValidator::requireString($body, 'brand');
            $model      = RequestValidator::requireString($body, 'model');
            $year       = RequestValidator::requireInt($body, 'year');

            $customer = $this->customerRepository->findById($customerId);
            if ($customer === null) {
                http_response_code(404);
                echo json_encode(['error' => "Cliente não encontrado: {$customerId}"]);
                return;
            }

            $existing = $this->vehicleRepository->findByLicensePlate($plate->getValue());
            if ($existing !== null) {
                http_response_code(422);
                echo json_encode(['error' => 'Já existe um veículo com esta placa.']);
                return;
            }

            $vehicle = Vehicle::create($this->uuidGenerator->generate(), $customerId, $plate, $brand, $model, $year);
            $this->vehicleRepository->save($vehicle);

            http_response_code(201);
            echo json_encode($vehicle->toArray());
        } catch (DomainException $e) {
            http_response_code(422);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /** @param array<string, string> $params */
    public function update(array $params): void
    {
        $vehicle = $this->vehicleRepository->findById($params['id']);
        if ($vehicle === null) {
            http_response_code(404);
            echo json_encode(['error' => "Veículo não encontrado: {$params['id']}"]);
            return;
        }

        $body = $this->parseBody();

        try {
            $brand = RequestValidator::requireString($body, 'brand');
            $model = RequestValidator::requireString($body, 'model');
            $year  = RequestValidator::requireInt($body, 'year');

            $updated = Vehicle::create(
                $vehicle->getId(),
                $vehicle->getCustomerId(),
                $vehicle->getLicensePlate(),
                $brand,
                $model,
                $year,
            );

            $this->vehicleRepository->update($updated);

            http_response_code(200);
            echo json_encode($updated->toArray());
        } catch (DomainException $e) {
            http_response_code(422);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /** @param array<string, string> $params */
    public function destroy(array $params): void
    {
        $vehicle = $this->vehicleRepository->findById($params['id']);
        if ($vehicle === null) {
            http_response_code(404);
            echo json_encode(['error' => "Veículo não encontrado: {$params['id']}"]);
            return;
        }

        $this->vehicleRepository->delete($params['id']);
        http_response_code(204);
    }

    /** @return array<string, mixed> */
    private function parseBody(): array
    {
        $raw  = $this->bodyReader !== null ? ($this->bodyReader)() : file_get_contents('php://input');
        $data = json_decode(is_string($raw) && $raw !== '' ? $raw : '{}', true);

        return is_array($data) ? $data : [];
    }
}
