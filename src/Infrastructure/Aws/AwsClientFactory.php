<?php

declare(strict_types=1);

namespace App\Infrastructure\Aws;

use Aws\DynamoDb\DynamoDbClient;
use Aws\Sns\SnsClient;
use Aws\Sqs\SqsClient;

/**
 * Cria os clientes da AWS. No cluster a credencial vem do role do ServiceAccount (IRSA), pela
 * cadeia padrão do SDK: nenhuma chave no ambiente. AWS_ENDPOINT_URL aponta para o LocalStack no
 * desenvolvimento local e no e2e.
 */
final class AwsClientFactory
{
    /** @param array<string, mixed> $env */
    public function __construct(private readonly array $env)
    {
    }

    public function dynamoDb(): DynamoDbClient
    {
        return new DynamoDbClient($this->config());
    }

    public function sns(): SnsClient
    {
        return new SnsClient($this->config());
    }

    public function sqs(): SqsClient
    {
        return new SqsClient($this->config());
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        $config = [
            'version' => 'latest',
            'region'  => (string)($this->env['AWS_REGION'] ?? 'us-east-1'),
        ];

        $endpoint = (string)($this->env['AWS_ENDPOINT_URL'] ?? '');
        if ($endpoint !== '') {
            $config['endpoint'] = $endpoint;
            // O LocalStack aceita qualquer credencial; estas não dão acesso a nada real.
            $config['credentials'] = ['key' => 'local', 'secret' => 'local'];
        }

        return $config;
    }
}
