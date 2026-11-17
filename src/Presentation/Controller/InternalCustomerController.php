<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Domain\Repository\CustomerRepositoryInterface;
use App\Domain\ValueObject\Document;

/**
 * Consulta interna de cliente por CPF/CNPJ, para a Lambda auth-cpf (docs/fase-4/CONTRATOS.md
 * §4 e §7, no oficina-api): é o que tira dela o acesso direto ao banco do serviço de OS.
 *
 * Fora do API Gateway: chega só por dentro da VPC, pelo NLB, e exige X-Internal-Token. Devolve
 * só o que o login precisa.
 */
class InternalCustomerController
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customers,
        private readonly string $internalToken,
    ) {
    }

    /** @param array<string, string> $params */
    public function byDocument(array $params, string $token): void
    {
        if ($this->internalToken === '' || !hash_equals($this->internalToken, $token)) {
            http_response_code(401);
            echo json_encode(['error' => 'Token interno inválido.']);

            return;
        }

        try {
            $document = (new Document($params['document']))->getValue();
        } catch (\Throwable) {
            http_response_code(400);
            echo json_encode(['error' => 'CPF ou CNPJ inválido.']);

            return;
        }

        $customer = $this->customers->findByDocument($document);
        if ($customer === null) {
            http_response_code(404);
            echo json_encode(['error' => 'Cliente não encontrado.']);

            return;
        }

        http_response_code(200);
        echo json_encode([
            'id'       => $customer->getId(),
            'name'     => $customer->getName(),
            'document' => $customer->getDocument()->getValue(),
            'status'   => $customer->getStatus()->value,
        ], JSON_UNESCAPED_UNICODE);
    }
}
