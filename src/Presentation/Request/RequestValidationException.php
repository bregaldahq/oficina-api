<?php

declare(strict_types=1);

namespace App\Presentation\Request;

/** Corpo de requisição inválido. public/index.php responde com o código e a mensagem (400). */
final class RequestValidationException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 400)
    {
        parent::__construct($message);
    }
}
