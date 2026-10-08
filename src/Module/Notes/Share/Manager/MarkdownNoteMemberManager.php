<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Share\Manager;

use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Share\Entity\MarkdownNoteMember;
use Aurora\Module\Notes\Share\Entity\MarkdownNoteMemberInterface;
use Aurora\Module\Notes\Share\Enum\NoteMemberRoleEnum;
use Aurora\Module\Notes\Share\Repository\MarkdownNoteMemberRepository;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(MarkdownNoteMemberManagerInterface::class)]
class MarkdownNoteMemberManager implements MarkdownNoteMemberManagerInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly MarkdownNoteMemberRepository $memberRepository,
        protected readonly AuditLogger $auditLogger,
    ) {}

    public function setMember(MarkdownNoteInterface $note, CoreUserInterface $user, NoteMemberRoleEnum $role): MarkdownNoteMemberInterface
    {
        $member = $this->memberRepository->findOneFor($note, $user);

        if (!$member instanceof MarkdownNoteMemberInterface) {
            $member = $this->createMember();
            $member->setNote($note)->setUser($user);
            $this->entityManager->persist($member);
        }

        $member->setRole($role);
        $this->entityManager->flush();

        $this->auditShared($member);

        return $member;
    }

    public function removeMember(MarkdownNoteInterface $note, CoreUserInterface $user): bool
    {
        $member = $this->memberRepository->findOneFor($note, $user);

        if (!$member instanceof MarkdownNoteMemberInterface) {
            return false;
        }

        $this->entityManager->remove($member);
        $this->entityManager->flush();

        $this->auditUnshared($note, $user);

        return true;
    }

    protected function createMember(): MarkdownNoteMemberInterface
    {
        return new MarkdownNoteMember();
    }

    protected function auditShared(MarkdownNoteMemberInterface $member): void
    {
        $this->auditLogger->log('notes_markdown', 'note.shared_with_person', 'MarkdownNote', $member->getNote()->getId(), [
            ...$this->auditPayload($member->getNote()),
            'userId' => $member->getUser()->getId(),
            'role' => $member->getRole()->value,
        ]);
    }

    protected function auditUnshared(MarkdownNoteInterface $note, CoreUserInterface $user): void
    {
        $this->auditLogger->log('notes_markdown', 'note.unshared_with_person', 'MarkdownNote', $note->getId(), [
            ...$this->auditPayload($note),
            'userId' => $user->getId(),
        ]);
    }

    /**
     * What the log keeps of the note: never its title, which is encrypted for
     * the same reason the body is.
     *
     * @return array<string, mixed>
     */
    protected function auditPayload(MarkdownNoteInterface $note): array
    {
        return [
            'spaceId' => $note->getSpace()->getId(),
        ];
    }
}
