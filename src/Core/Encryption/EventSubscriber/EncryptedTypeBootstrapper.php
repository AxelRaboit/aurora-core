<?php

declare(strict_types=1);

namespace Aurora\Core\Encryption\EventSubscriber;

use Aurora\Core\Encryption\Doctrine\EncryptedStringType;
use Aurora\Core\Encryption\Doctrine\EncryptedTextType;
use Aurora\Core\Encryption\Service\EncryptionServiceInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Injects the EncryptionService into the static Doctrine encrypted types
 * as early as possible in the request / console lifecycle.
 *
 * Doctrine instantiates types itself (not via Symfony DI) so the service
 * must be wired statically before any query runs. Subscribing to high-priority
 * events on both KernelEvents::REQUEST and ConsoleEvents::COMMAND covers
 * HTTP requests, fixtures, migrations and any other command.
 *
 * The actual injection happens in the constructor - being instantiated by
 * the event dispatcher is enough.
 */
final readonly class EncryptedTypeBootstrapper implements EventSubscriberInterface
{
    public function __construct(EncryptionServiceInterface $encryptionService)
    {
        EncryptedTextType::setEncryptionService($encryptionService);
        EncryptedStringType::setEncryptionService($encryptionService);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['noop', 9999],
            ConsoleEvents::COMMAND => ['noop', 9999],
        ];
    }

    public function noop(): void {}
}
