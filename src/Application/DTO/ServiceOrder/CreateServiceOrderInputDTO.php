<?php

declare(strict_types=1);

namespace App\Application\DTO\ServiceOrder;

class CreateServiceOrderInputDTO
{
    public function __construct(
        public readonly string $customerId,
        public readonly string $vehicleId,
        /** O que o cliente relatou: vai no pedido de diagnóstico para a Execução. */
        public readonly string $description = '',
    ) {
    }
}
