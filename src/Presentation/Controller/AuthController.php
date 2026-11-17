<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Application\DTO\Auth\AuthDTO;
use App\Application\UseCase\Auth\AuthenticateUseCase;
use App\Domain\Exception\DomainException;

/**
 * Admin login. Rate limiting is NOT done here: the previous file-based counter in
 * sys_get_temp_dir() was per-Pod and therefore useless behind a horizontally scaled
 * Deployment. Throttling now lives in the API Gateway stage (CONTRATOS.md §5).
 */
class AuthController
{
    public function __construct(
        private readonly AuthenticateUseCase $authenticateUseCase,
        /** Quem lê o corpo; os testes passam um, em produção é php://input. */
        private readonly ?\Closure $bodyReader = null,
    ) {
    }

    public function login(): void
    {
        $body = $this->parseBody();

        if (empty($body['username']) || empty($body['password'])) {
            http_response_code(400);
            echo json_encode(['error' => 'username e password são obrigatórios.']);
            return;
        }

        try {
            $token = $this->authenticateUseCase->execute(new AuthDTO(
                username: $body['username'],
                password: $body['password'],
            ));

            http_response_code(200);
            echo json_encode(['token' => $token]);
        } catch (DomainException $e) {
            http_response_code(401);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /** @return array<string, mixed> */
    private function parseBody(): array
    {
        $raw  = $this->bodyReader !== null ? ($this->bodyReader)() : file_get_contents('php://input');
        $data = json_decode(is_string($raw) && $raw !== '' ? $raw : '{}', true);

        return is_array($data) ? $data : [];
    }
}
