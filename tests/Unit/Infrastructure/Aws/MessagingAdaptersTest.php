<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Aws;

use App\Application\Port\ReceivedMessage;
use App\Application\Worker\Worker;
use App\Infrastructure\Aws\AwsClientFactory;
use App\Infrastructure\Aws\SnsMessagePublisher;
use App\Infrastructure\Aws\SqsMessageQueue;
use App\Infrastructure\Context\RequestContext;
use App\Infrastructure\Logging\JsonLogger;
use App\Infrastructure\ServiceFactory;
use App\Infrastructure\Tracing\NewRelicTracer;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\Sns\SnsClient;
use Aws\Sqs\SqsClient;
use PHPUnit\Framework\TestCase;
use Tests\Support\OsDatabase;

class MessagingAdaptersTest extends TestCase
{
    /** @var list<CommandInterface<mixed>> */
    private array $commands = [];

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function config(array $result = []): array
    {
        $mock = new MockHandler();
        $mock->append(function (CommandInterface $command) use ($result): Result {
            $this->commands[] = $command;

            return new Result($result);
        });

        return ['version' => 'latest', 'region' => 'us-east-1', 'credentials' => ['key' => 'k', 'secret' => 's'], 'handler' => $mock];
    }

    public function testPublicaNoTopicoComOAtributoDoFiltro(): void
    {
        $message = new \App\Domain\Messaging\Message('m', 'billing.charge', 'os', 's', 'o', 't', 'c', ['budget_id' => 'b']);

        (new SnsMessagePublisher(new SnsClient($this->config()), 'arn:t'))->publish($message);

        $this->assertSame('arn:t', $this->commands[0]['TopicArn']);
        $this->assertSame($message->toJson(), $this->commands[0]['Message']);
        $this->assertSame('billing.charge', $this->commands[0]['MessageAttributes']['type']['StringValue']);
    }

    public function testLeEConfirmaNaFila(): void
    {
        $queue = new SqsMessageQueue(new SqsClient($this->config(['Messages' => [['Body' => '{}', 'ReceiptHandle' => 'r']]])), 'https://q');
        $this->assertEquals([new ReceivedMessage('{}', 'r')], $queue->receive(99, 99));
        $this->assertSame([10, 20], [$this->commands[0]['MaxNumberOfMessages'], $this->commands[0]['WaitTimeSeconds']]);

        $queue = new SqsMessageQueue(new SqsClient($this->config()), 'https://q');
        $this->assertSame([], $queue->receive());
        $queue = new SqsMessageQueue(new SqsClient($this->config()), 'https://q');
        $queue->delete(new ReceivedMessage('{}', 'r-9'));
        $this->assertSame('r-9', $this->commands[count($this->commands) - 1]['ReceiptHandle']);
    }

    public function testCredencialDoClusterVemDoRoleEOLocalAceitaEndpoint(): void
    {
        $this->assertArrayNotHasKey('credentials', (new AwsClientFactory(['AWS_REGION' => 'sa-east-1']))->config());

        $local = new AwsClientFactory(['AWS_ENDPOINT_URL' => 'http://localstack:4566']);
        $this->assertSame('http://localstack:4566', (string)$local->sns()->getEndpoint());
        $this->assertSame('http://localstack:4566', (string)$local->sqs()->getEndpoint());
        $this->assertSame('http://localstack:4566', (string)$local->dynamoDb()->getEndpoint());
    }

    public function testRastreadorSemAgenteNaoFazNada(): void
    {
        $tracer = new NewRelicTracer('oficina-api', false);

        $this->assertNull($tracer->currentTrace());
        $tracer->startMessage('x', '{"a":1}');
        $tracer->endMessage(true);
        $this->addToAssertionCount(1);
    }

    public function testMontagemDaSagaParaAApiEOWorker(): void
    {
        OsDatabase::create();
        $logger  = new JsonLogger('oficina-api', 'testing');
        $factory = new ServiceFactory([
            'AWS_ENDPOINT_URL'    => 'http://localstack:4566',
            'MESSAGING_TOPIC_ARN' => 'arn:t',
            'MESSAGING_QUEUE_URL' => 'http://q',
            'SMTP_HOST'           => 'smtp.local',
            'MAIL_TO'             => 'oficina@local',
        ], $logger, new RequestContext());

        $this->assertInstanceOf(Worker::class, $factory->worker($logger));
        $this->assertSame($factory->events(), $factory->events());
        $this->assertSame($factory->sagas(), $factory->sagas());
        $factory->createServiceOrder($logger);
        $factory->cancelServiceOrder($logger);
        $factory->deliverServiceOrder();
    }
}
