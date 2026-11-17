<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/** Outra escrita mudou a tarefa entre a leitura e a gravação. Quem chamou pode tentar de novo. */
class ConcurrencyException extends \RuntimeException
{
}
