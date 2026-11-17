<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/** Mensagem fora do envelope do contrato (docs/fase-4/CONTRATOS.md §5.1, no oficina-api). */
class InvalidMessageException extends DomainException
{
}
