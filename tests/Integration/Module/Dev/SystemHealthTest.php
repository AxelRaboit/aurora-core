<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Dev;

use Aurora\Core\Scheduler\MainSchedule;
use Aurora\Module\Dev\Health\Queue\QueueInspector;
use Aurora\Module\Dev\Health\Report\SystemHealthReport;
use Aurora\Module\Dev\Health\Scheduler\ScheduleInspector;
use Aurora\Module\Dev\Health\Scheduler\ScheduleRunRecorder;
use Aurora\Module\Dev\Health\Worker\WorkerHeartbeat;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\CustomerSpace\Message\SpaceActivityDigestMessage;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use RuntimeException;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Scheduler\Event\PostRunEvent;
use Symfony\Component\Scheduler\Generator\MessageContext;

use function array_column;
use function array_filter;
use function array_values;
use function json_decode;
use function sprintf;
use function time;

/**
 * « État du système »: the worker's heartbeat, the scheduled tasks' last
 * runs and the queues' counts, measured rather than assumed.
 *
 * None of it existed: a worker that crashed twice in a morning, a task that
 * stopped, a message left in the failure queue were all invisible from the
 * application.
 */
final class SystemHealthTest extends IntegrationTestCase
{
    private CacheItemPoolInterface $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = static::getContainer()->get('cache.app');
        $this->cache->deleteItem(WorkerHeartbeat::CACHE_KEY);
    }

    protected function tearDown(): void
    {
        static::getContainer()->get('cache.app')->deleteItem(WorkerHeartbeat::CACHE_KEY);
        static::getContainer()->get(Connection::class)->executeStatement("DELETE FROM messenger_messages WHERE queue_name = 'failed'");

        parent::tearDown();
    }

    public function testAStartedWorkerIsSeenAndASilentOneIsDown(): void
    {
        self::assertSame('danger', $this->check('worker')['status'], 'Never seen: down.');

        $worker = new Worker(['async' => new InMemoryTransport()], static::getContainer()->get(MessageBusInterface::class));
        static::getContainer()->get(WorkerHeartbeat::class)->onStarted(new WorkerStartedEvent($worker));

        $worker = $this->check('worker');
        self::assertSame('ok', $worker['status']);
        self::assertSame(['async'], $worker['facts']['transports']);

        // Three minutes of silence.
        $item = $this->cache->getItem(WorkerHeartbeat::CACHE_KEY);
        $item->set(['at' => time() - 180, 'startedAt' => null, 'transports' => ['async'], 'pid' => null]);
        $this->cache->save($item);

        self::assertSame('danger', $this->check('worker')['status']);
    }

    /**
     * The worker leaves every hour on its time limit and comes back: that is
     * no incident. One that dies without stopping is, for a day, even though
     * it runs again. Each heartbeat below is a new process, as each worker is.
     */
    public function testACleanStopIsNoCrashButADeathIs(): void
    {
        $worker = new Worker(['async' => new InMemoryTransport()], static::getContainer()->get(MessageBusInterface::class));

        // Written before the worker said how it stopped: not a crash.
        $item = $this->cache->getItem(WorkerHeartbeat::CACHE_KEY);
        $item->set(['at' => time() - 60, 'startedAt' => null, 'transports' => ['async'], 'pid' => null]);
        $this->cache->save($item);
        $first = new WorkerHeartbeat($this->cache);
        $first->onStarted(new WorkerStartedEvent($worker));
        self::assertSame('ok', $this->check('worker')['status']);

        // An hour later, it leaves on its time limit and comes back.
        $first->onStopped(new WorkerStoppedEvent($worker));
        new WorkerHeartbeat($this->cache)->onStarted(new WorkerStartedEvent($worker));
        self::assertSame('ok', $this->check('worker')['status'], 'A stop it chose is no crash.');

        // Then it dies, and the supervisor brings it back.
        new WorkerHeartbeat($this->cache)->onStarted(new WorkerStartedEvent($worker));
        $check = $this->check('worker');
        self::assertSame('warning', $check['status']);
        self::assertSame('suite.health.worker.crashed', $check['messageKey']);
        self::assertNotNull($check['facts']['crashedAt']);

        // Later clean restarts keep the crash in sight.
        $next = new WorkerHeartbeat($this->cache);
        $next->onStarted(new WorkerStartedEvent($worker));
        $next->onStopped(new WorkerStoppedEvent($worker));
        new WorkerHeartbeat($this->cache)->onStarted(new WorkerStartedEvent($worker));
        self::assertSame('warning', $this->check('worker')['status']);

        // A day later, it is history.
        $item = $this->cache->getItem(WorkerHeartbeat::CACHE_KEY);
        $item->set([...$item->get(), 'crashedAt' => time() - WorkerHeartbeat::CRASH_SHOWN_FOR - 60]);
        $this->cache->save($item);
        self::assertSame('ok', $this->check('worker')['status']);
    }

    /**
     * A task is judged on its own rhythm: the hourly cleaning seen a minute
     * ago is on time, seen three hours ago it is late.
     */
    public function testATaskIsLateAgainstItsOwnRhythm(): void
    {
        $schedule = static::getContainer()->get(MainSchedule::class);
        $hourly = null;
        foreach ($schedule->getSchedule()->getRecurringMessages() as $recurring) {
            if ('0 * * * *' === (string) $recurring->getTrigger()) {
                $hourly = $recurring;
            }
        }
        self::assertNotNull($hourly, 'the core schedule has its hourly cleaning');

        $recorder = static::getContainer()->get(ScheduleRunRecorder::class);
        $context = new MessageContext('main', $hourly->getId(), $hourly->getTrigger(), new DateTimeImmutable());
        $recorder->onPostRun(new PostRunEvent($schedule, $context, new stdClass()));

        self::assertSame('ok', $this->taskStatus($hourly->getId()));

        $item = $this->cache->getItem('aurora.health.schedule.'.hash('xxh128', $hourly->getId()));
        $item->set(['at' => time() - 3 * 3600, 'failed' => false, 'error' => null]);
        $this->cache->save($item);

        self::assertSame('danger', $this->taskStatus($hourly->getId()));
    }

    /** A failed message is counted, listed, and sent back to its queue by « Relancer ». */
    public function testAFailedMessageIsCountedListedAndRetried(): void
    {
        $failed = static::getContainer()->get('messenger.transport.failed');
        self::assertInstanceOf(TransportInterface::class, $failed);
        $failed->send(new Envelope(new SpaceActivityDigestMessage(1, 1), [
            new SentToFailureTransportStamp('async'),
            ErrorDetailsStamp::create(new RuntimeException('Le serveur de messagerie ne répond pas.')),
        ]));

        $queues = static::getContainer()->get(QueueInspector::class);
        self::assertSame(1, $queues->queue(QueueInspector::FAILED)['count']);
        self::assertSame('danger', $this->check('queue_failed')['status']);

        $failures = $queues->failures();
        self::assertCount(1, $failures);
        self::assertSame('SpaceActivityDigestMessage', $failures[0]['message']);
        self::assertStringContainsString('ne répond pas', (string) $failures[0]['error']);

        self::assertTrue($queues->retry($failures[0]['id']));
        self::assertSame(0, $queues->queue(QueueInspector::FAILED)['count']);
    }

    /** The block is the developer's; a write without the page's token is refused. */
    public function testTheBlockAnswersADeveloperAndRefusesAWriteWithoutToken(): void
    {
        static::ensureKernelShutdown();
        $client = static::createClient();
        $developer = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $developer);
        $client->loginUser($developer, 'admin');

        $client->request('GET', '/dev/dashboard/health', server: ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $health = json_decode((string) $client->getResponse()->getContent(), true)['health'];
        self::assertContains($health['status'], ['ok', 'warning', 'danger', 'unknown']);
        self::assertSame(['background', 'foundations', 'environment'], array_column($health['sections'], 'key'));

        $client->jsonRequest('POST', '/dev/dashboard/health/failed/1/delete', ['_token' => 'wrong']);
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    /** @return array<string, mixed> */
    private function check(string $key): array
    {
        foreach (static::getContainer()->get(SystemHealthReport::class)->build()['sections'] as $section) {
            foreach ($section['checks'] as $check) {
                if ($key === $check['key']) {
                    return $check;
                }
            }
        }

        self::fail(sprintf('no check "%s" in the report', $key));
    }

    private function taskStatus(string $id): string
    {
        $tasks = array_values(array_filter(
            static::getContainer()->get(ScheduleInspector::class)->tasks(),
            static fn (array $task): bool => $id === $task['id'],
        ));

        self::assertCount(1, $tasks);

        return $tasks[0]['status'];
    }
}
