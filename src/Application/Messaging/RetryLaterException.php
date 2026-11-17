<?php

declare(strict_types=1);

namespace App\Application\Messaging;

/**
 * A mensagem é válida, mas ainda não dá para aplicar: o worker a deixa na fila, e o SQS a entrega
 * de novo depois do visibility timeout. Não é erro (docs/fase-4/CONTRATOS.md §5.4 e §8).
 *
 * Dois casos: evento de um passo posterior ao atual (o SQS standard não garante ordem) e
 * colisão de versão que persistiu depois das novas tentativas na hora.
 */
final class RetryLaterException extends \RuntimeException
{
}
