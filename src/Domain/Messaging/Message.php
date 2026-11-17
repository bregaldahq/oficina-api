<?php

declare(strict_types=1);

namespace App\Domain\Messaging;

use App\Domain\Exception\InvalidMessageException;

/**
 * O envelope de toda mensagem entre os serviços (docs/fase-4/CONTRATOS.md §5.1, no oficina-api).
 * O formato é contrato: mudar um campo aqui exige mudar o documento e os outros dois serviços.
 */
final class Message
{
    public const VERSION = 1;

    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $producer,
        public readonly string $sagaId,
        public readonly string $osId,
        public readonly string $occurredAt,
        public readonly string $correlationId,
        public readonly array $payload = [],
        public readonly ?string $trace = null,
        public readonly int $version = self::VERSION,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (['id', 'type', 'producer', 'saga_id', 'os_id', 'occurred_at'] as $field) {
            if (!isset($data[$field]) || !is_string($data[$field]) || $data[$field] === '') {
                throw new InvalidMessageException("Mensagem sem o campo obrigatório '{$field}'.");
            }
        }

        $payload = $data['payload'] ?? [];
        if (!is_array($payload)) {
            throw new InvalidMessageException("O campo 'payload' deve ser um objeto.");
        }

        $trace = is_array($data['trace'] ?? null) ? ($data['trace']['newrelic'] ?? null) : null;

        return new self(
            $data['id'],
            $data['type'],
            $data['producer'],
            $data['saga_id'],
            $data['os_id'],
            $data['occurred_at'],
            is_string($data['correlation_id'] ?? null) ? $data['correlation_id'] : '',
            $payload,
            is_string($trace) && $trace !== '' ? $trace : null,
            is_int($data['version'] ?? null) ? $data['version'] : self::VERSION,
        );
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new InvalidMessageException('O corpo da mensagem não é um JSON de objeto.');
        }

        return self::fromArray($data);
    }

    public function withTrace(?string $trace): self
    {
        return new self(
            $this->id,
            $this->type,
            $this->producer,
            $this->sagaId,
            $this->osId,
            $this->occurredAt,
            $this->correlationId,
            $this->payload,
            $trace,
            $this->version,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'id'             => $this->id,
            'type'           => $this->type,
            'version'        => $this->version,
            'producer'       => $this->producer,
            'saga_id'        => $this->sagaId,
            'os_id'          => $this->osId,
            'occurred_at'    => $this->occurredAt,
            'correlation_id' => $this->correlationId,
            'payload'        => $this->payload === [] ? new \stdClass() : $this->payload,
        ];
        if ($this->trace !== null) {
            $data['trace'] = ['newrelic' => $this->trace];
        }

        return $data;
    }

    public function toJson(): string
    {
        return (string)json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
