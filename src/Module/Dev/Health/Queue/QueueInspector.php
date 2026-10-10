<?php

declare(strict_types=1);

namespace Aurora\Module\Dev\Health\Queue;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Throwable;

/**
 * What waits in the queues, and what failed.
 *
 * **The failure queue had no screen.** A message that failed its retries -
 * a digest, a GED move, a form delivered to a webhook - went to `failed` and
 * stayed there, readable only with `messenger:failed:show` on the server.
 * The block counts both queues, says how old the oldest waiting message is
 * (a queue that grows while nobody consumes it is a stopped worker seen from
 * the other side), lists the failures, and retries or deletes one.
 *
 * The counts come from the transports when they can say
 * ({@see MessageCountAwareInterface}); the age of the oldest message from the
 * Doctrine table when the transport is Doctrine, and is simply absent
 * otherwise.
 */
final readonly class QueueInspector
{
    public const string ASYNC = 'async';

    public const string FAILED = 'failed';

    /** How many failures the block lists. */
    public const int FAILURES_SHOWN = 30;

    public function __construct(
        #[Autowire(service: 'messenger.receiver_locator')]
        private ContainerInterface $receivers,
        private Connection $connection,
        private MessageBusInterface $bus,
    ) {}

    /** @return array{count: int|null, oldestAt: string|null} */
    public function queue(string $transport): array
    {
        $receiver = $this->receiver($transport);
        $count = null;

        if ($receiver instanceof MessageCountAwareInterface) {
            try {
                $count = $receiver->getMessageCount();
            } catch (Throwable) {
            }
        }

        return ['count' => $count, 'oldestAt' => $this->oldestWaiting(self::FAILED === $transport ? 'failed' : 'default')?->format(DATE_ATOM)];
    }

    /** @return list<array<string, mixed>> */
    public function failures(): array
    {
        $receiver = $this->receiver(self::FAILED);
        if (!$receiver instanceof ListableReceiverInterface) {
            return [];
        }

        $rows = [];

        try {
            foreach ($receiver->all(self::FAILURES_SHOWN) as $envelope) {
                $rows[] = $this->describe($envelope);
            }
        } catch (Throwable) {
            return [];
        }

        return $rows;
    }

    /** Sends a failed message again through its routing, then takes it off the failure queue. */
    public function retry(string $id): bool
    {
        $receiver = $this->receiver(self::FAILED);
        if (!$receiver instanceof ListableReceiverInterface) {
            return false;
        }

        $envelope = $receiver->find($id);
        if (!$envelope instanceof Envelope) {
            return false;
        }

        $this->bus->dispatch($envelope->getMessage());
        $receiver->reject($envelope);

        return true;
    }

    public function delete(string $id): bool
    {
        $receiver = $this->receiver(self::FAILED);
        if (!$receiver instanceof ListableReceiverInterface) {
            return false;
        }

        $envelope = $receiver->find($id);
        if (!$envelope instanceof Envelope) {
            return false;
        }

        $receiver->reject($envelope);

        return true;
    }

    /** @return array<string, mixed> */
    private function describe(Envelope $envelope): array
    {
        $class = $envelope->getMessage()::class;
        $error = $envelope->last(ErrorDetailsStamp::class);
        $redelivery = $envelope->last(RedeliveryStamp::class);

        return [
            'id' => (string) $envelope->last(TransportMessageIdStamp::class)?->getId(),
            'message' => mb_substr($class, (int) mb_strrpos($class, '\\') + 1),
            'messageClass' => $class,
            'from' => $envelope->last(SentToFailureTransportStamp::class)?->getOriginalReceiverName(),
            'error' => $error instanceof StampInterface ? mb_substr($error->getExceptionMessage(), 0, 300) : null,
            'errorClass' => $error?->getExceptionClass(),
            'failedAt' => $redelivery?->getRedeliveredAt()->format(DATE_ATOM),
        ];
    }

    private function receiver(string $transport): ?ReceiverInterface
    {
        try {
            $receiver = $this->receivers->has($transport) ? $this->receivers->get($transport) : null;
        } catch (Throwable) {
            return null;
        }

        return $receiver instanceof ReceiverInterface ? $receiver : null;
    }

    /**
     * The oldest message still waiting in a Doctrine queue, or null when the
     * transport is not Doctrine, the table does not exist, or nothing waits.
     */
    private function oldestWaiting(string $queueName): ?DateTimeImmutable
    {
        try {
            $oldest = $this->connection->fetchOne(
                'SELECT MIN(created_at) FROM messenger_messages WHERE queue_name = :queue AND delivered_at IS NULL',
                ['queue' => $queueName],
            );
        } catch (Throwable) {
            return null;
        }

        return is_string($oldest) ? new DateTimeImmutable($oldest) : null;
    }
}
