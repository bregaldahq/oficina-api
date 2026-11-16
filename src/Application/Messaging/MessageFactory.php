<?php

declare(strict_types=1);

namespace App\Application\Messaging;

use App\Application\Port\ClockInterface;
use App\Domain\Messaging\Message;
use App\Domain\Saga\Saga;
use App\Domain\UuidGeneratorInterface;

/** Transforma os comandos que a saga registrou nos envelopes que vão para a outbox. */
final class MessageFactory
{
    public const PRODUCER = 'os';

    public function __construct(
        private readonly UuidGeneratorInterface $uuid,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @return list<Message> */
    public function fromSaga(Saga $saga): array
    {
        $messages = [];
        foreach ($saga->releaseCommands() as $command) {
            $messages[] = new Message(
                $this->uuid->generate(),
                $command['type'],
                self::PRODUCER,
                $saga->getId(),
                $saga->getOsId(),
                $this->clock->now()->format('Y-m-d\TH:i:s.v\Z'),
                $saga->getCorrelationId(),
                $command['payload'],
            );
        }

        return $messages;
    }
}
