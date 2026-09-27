<?php

declare(strict_types=1);

namespace Aurora\Module\Dev\Audit\Service;

use Aurora\Core\Sequence\SequenceGenerator;
use Aurora\Core\Sequence\SequencePrefixEnum;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Dev\Audit\Entity\AuditLog;
use Aurora\Module\Platform\User\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

readonly class AuditLogger
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Security $security,
        private SequenceGenerator $sequenceGenerator,
        private SettingRepository $settingRepository,
    ) {}

    public function log(
        string $module,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $data = null,
    ): void {
        $log = $this->entry($module, $action, $entityType, $entityId, $data);
        $log->setReference($this->sequenceGenerator->next($this->prefix()));

        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }

    /**
     * One line per row, for an action done to many rows at once.
     *
     * Still a line each, because the log is read row by row: "who moved this
     * document" has to find its own line. But the references are reserved in
     * one statement and the lines written in one flush. Called in a loop,
     * `log()` flushed every time, and each flush recomputed the changes of
     * everything the unit of work already held: a trash of five hundred
     * documents cost a quadratic amount of work before a single file went.
     *
     * Like `log()`, the flush also writes whatever the caller left pending.
     *
     * @param list<array{id: int|null, data: array<string, mixed>|null}> $entries
     */
    public function logMany(string $module, string $action, ?string $entityType, array $entries): void
    {
        if ([] === $entries) {
            return;
        }

        $references = $this->sequenceGenerator->nextMany($this->prefix(), count($entries));

        foreach ($entries as $index => $entry) {
            $log = $this->entry($module, $action, $entityType, $entry['id'], $entry['data']);
            $log->setReference($references[$index]);
            $this->entityManager->persist($log);
        }

        $this->entityManager->flush();
    }

    /** @param array<string, mixed>|null $data */
    private function entry(string $module, string $action, ?string $entityType, ?int $entityId, ?array $data): AuditLog
    {
        $user = $this->security->getUser();
        $appUser = $user instanceof User ? $user : null;

        return new AuditLog(
            module: $module,
            action: $action,
            entityType: $entityType,
            entityId: $entityId,
            userId: $appUser?->getId(),
            userEmail: $user?->getUserIdentifier(),
            userName: $appUser?->getName(),
            data: $data,
        );
    }

    private function prefix(): string
    {
        return $this->settingRepository->get(ApplicationParameterEnum::CoreAuditLogPrefix->value, SequencePrefixEnum::AuditLog->value) ?? SequencePrefixEnum::AuditLog->value;
    }
}
