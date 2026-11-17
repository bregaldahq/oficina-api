<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/** A mensagem já foi processada (inbox). Quem consome confirma e segue, sem efeito. */
class DuplicateMessageException extends \RuntimeException
{
}
