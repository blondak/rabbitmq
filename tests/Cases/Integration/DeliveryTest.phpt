<?php

declare(strict_types=1);

namespace Bckp\RabbitMQ\Tests\Cases\Integration;

use Bckp\RabbitMQ\Connection\ConnectionFactory;
use Bckp\RabbitMQ\Connection\IConnection;
use Bckp\RabbitMQ\Consumer\Consumer;
use Bckp\RabbitMQ\Consumer\ConsumerFactory;
use Bckp\RabbitMQ\Consumer\IConsumer;
use Bckp\RabbitMQ\DI\RabbitMQExtension;
use Bckp\RabbitMQ\Exchange\ExchangesDataBag;
use Bckp\RabbitMQ\LazyDeclarator;
use Bckp\RabbitMQ\Producer\ProducerFactory;
use Bckp\RabbitMQ\Queue\QueueFactory;
use Bckp\RabbitMQ\Queue\QueuesDataBag;
use Bunny\Message;
use Nette\DI\Compiler;
use Nette\DI\Container;
use Nette\DI\ContainerLoader;
use Tester\Assert;
use Tester\Environment;
use Tester\TestCase;

require __DIR__ . '/../../bootstrap.php';

if (getenv('RABBITMQ_HOST') === false) {
	Environment::skip('Set RABBITMQ_HOST to run tests against a disposable RabbitMQ broker.');
}

final class DeliveryTest extends TestCase
{
	private Container $container;
	private IConnection $connection;
	private string $queue;
	private string $exchange;

	protected function setUp(): void
	{
		$this->queue = 'php-compat-' . bin2hex(random_bytes(8));
		$this->exchange = $this->queue . '.events';
		$loader = new ContainerLoader(TEMP_DIR, true);
		$class = $loader->load(function (Compiler $compiler): void {
			$compiler->addExtension('rabbitmq', new RabbitMQExtension());
			$compiler->addConfig([
				'services' => ['handler' => DeliveryHandler::class],
				'rabbitmq' => [
					'connections' => ['default' => [
						'host' => getenv('RABBITMQ_HOST'),
						'port' => (int) (getenv('RABBITMQ_PORT') ?: 5672),
						'user' => getenv('RABBITMQ_USER') ?: 'guest',
						'password' => getenv('RABBITMQ_PASSWORD') ?: 'guest',
						'vhost' => getenv('RABBITMQ_VHOST') ?: '/',
						'publishConfirm' => 3,
						'heartbeat' => 1,
						'timeout' => 3,
					]],
					'queues' => [$this->queue => ['dlx' => '+1 second']],
					'exchanges' => [$this->exchange => [
						'type' => 'direct',
						'queueBindings' => [$this->queue => ['routingKey' => ['order.created', 'order.changed']]],
					]],
					'producers' => [
						'queue' => ['queue' => $this->queue, 'contentType' => 'application/json'],
						'events' => ['exchange' => $this->exchange],
					],
					'consumers' => ['orders' => [
						'queue' => $this->queue,
						'callback' => ['@handler', 'consume'],
						'qos' => ['prefetchSize' => 0, 'prefetchCount' => 1],
					]],
				],
			]);
		}, $this->queue);
		$this->container = new $class();
		$this->connection = $this->container->getByType(ConnectionFactory::class)->getConnection('default');
		$this->container->getByType(LazyDeclarator::class)->declare();
	}

	protected function tearDown(): void
	{
		$channel = $this->connection->getChannel();
		foreach ($this->container->getByType(QueuesDataBag::class)->getDataKeys() as $name) {
			$channel->queueDelete($name);
		}
		foreach ($this->container->getByType(ExchangesDataBag::class)->getDataKeys() as $name) {
			$channel->exchangeDelete($name);
		}
		$this->connection->resetConnection();
	}

	public function testPublishWithConfirmAndAck(): void
	{
		$this->producer('queue')->publish('{"order":123}', ['correlation-id' => 'php84']);
		$message = $this->receive();
		Assert::same('{"order":123}', $message->content);
		Assert::same('php84', $message->headers['correlation-id']);
		Assert::same('application/json', $message->headers['content-type']);
		$this->connection->getChannel()->ack($message);
		Assert::null($this->connection->getChannel()->get($this->queue));
	}

	public function testRoutingKeys(): void
	{
		$producer = $this->producer('events');
		$producer->publish('created', [], 'order.created');
		$producer->publish('changed', [], 'order.changed');
		$producer->publish('unmatched', [], 'other');
		foreach (['created', 'changed'] as $expected) {
			$message = $this->receive();
			Assert::same($expected, $message->content);
			$this->connection->getChannel()->ack($message);
		}
		Assert::null($this->connection->getChannel()->get($this->queue));
	}

	public function testConsumerFactoryAndAck(): void
	{
		$this->producer('queue')->publish('consume');
		$this->container->getByType(ConsumerFactory::class)->getConsumer('orders')->consume(3, 1);
		Assert::same(['consume'], $this->container->getByType(DeliveryHandler::class)->messages);
		$this->connection->resetConnection();
		Assert::null($this->connection->getChannel()->get($this->queue));
	}

	public function testNackRequeuesMessage(): void
	{
		$this->producer('queue')->publish('retry');
		$this->consumer(static fn (Message $message): int => IConsumer::MESSAGE_NACK_AND_TERMINATE)->consume(3);
		$this->connection->resetConnection();
		$message = $this->receive();
		Assert::same('retry', $message->content);
		Assert::true($message->redelivered);
		$this->connection->getChannel()->ack($message);
	}

	public function testRejectReturnsThroughDelayedDeadLetterQueue(): void
	{
		$this->producer('queue')->publish('delayed');
		$this->consumer(static fn (Message $message): int => IConsumer::MESSAGE_REJECT_AND_TERMINATE)->consume(3);
		$this->connection->resetConnection();
		Assert::null($this->connection->getChannel()->get($this->queue));
		$message = $this->receive();
		Assert::same('delayed', $message->content);
		Assert::same('expired', $message->headers['x-death'][0]['reason']);
		Assert::same($this->queue . '.dlx-1', $message->headers['x-death'][0]['queue']);
		$this->connection->getChannel()->ack($message);
	}

	public function testCallbackExceptionLeavesMessageForRedelivery(): void
	{
		$this->producer('queue')->publish('failed');
		$consumer = $this->consumer(static function (Message $message): int {
			throw new \RuntimeException('Consumer failed');
		});
		Assert::exception(static fn () => $consumer->consume(3), \RuntimeException::class, 'Consumer failed');
		$this->connection->resetConnection();
		$message = $this->receive();
		Assert::same('failed', $message->content);
		Assert::true($message->redelivered);
		$this->connection->getChannel()->ack($message);
	}

	public function testPublishAfterConnectionReset(): void
	{
		$producer = $this->producer('queue');
		$producer->publish('before');
		$this->connection->resetConnection();
		$producer->publish('after');
		foreach (['before', 'after'] as $expected) {
			$message = $this->receive();
			Assert::same($expected, $message->content);
			$this->connection->getChannel()->ack($message);
		}
	}

	private function producer(string $name): \Bckp\RabbitMQ\Producer\IProducer
	{
		return $this->container->getByType(ProducerFactory::class)->getProducer($name);
	}

	private function consumer(callable $callback): Consumer
	{
		return new Consumer(
			$this->container->getByType(LazyDeclarator::class),
			'orders',
			$this->container->getByType(QueueFactory::class)->getQueue($this->queue),
			$callback,
			0,
			1,
		);
	}

	private function receive(): Message
	{
		$deadline = microtime(true) + 5;
		do {
			$message = $this->connection->getChannel()->get($this->queue);
			if ($message instanceof Message) {
				return $message;
			}
			usleep(20000);
		} while (microtime(true) < $deadline);
		Assert::fail('Message was not delivered within five seconds.');
	}
}

final class DeliveryHandler implements IConsumer
{
	public array $messages = [];

	public function consume(Message $message): int
	{
		$this->messages[] = $message->content;
		return self::MESSAGE_ACK;
	}
}

(new DeliveryTest())->run();
