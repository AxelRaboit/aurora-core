<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Poll\Entity;

use Aurora\Module\Editorial\Poll\Repository\PollVoteRepository;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * One reader's answer to one poll.
 *
 * A poll is a zone of a publication, so it is named by the pair
 * (publication, zone id) rather than having a row of its own: the question
 * and its answers live in the grid, translated, and a poll removed from the
 * page leaves only its tallies behind, which the publication takes with it.
 *
 * The reader is an opaque fingerprint, never an address or an account: enough
 * to count one vote each, nothing that designates anyone.
 */
#[ORM\Entity(repositoryClass: PollVoteRepository::class)]
#[ORM\Table(name: 'core_editorial_poll_votes')]
#[ORM\UniqueConstraint(name: 'uniq_poll_vote_voter', columns: ['post_id', 'zone_id', 'voter'])]
#[ORM\Index(name: 'idx_poll_vote_zone', columns: ['post_id', 'zone_id'])]
class PollVote
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_editorial_poll_vote_id', allocationSize: 1)]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: PostInterface::class)]
        #[ORM\JoinColumn(name: 'post_id', nullable: false, onDelete: 'CASCADE')]
        private PostInterface $post,
        #[ORM\Column(length: 36)]
        private string $zoneId,
        #[ORM\Column(type: 'smallint')]
        private int $answer,
        #[ORM\Column(length: 64)]
        private string $voter,
    ) {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPost(): PostInterface
    {
        return $this->post;
    }

    public function getZoneId(): string
    {
        return $this->zoneId;
    }

    public function getAnswer(): int
    {
        return $this->answer;
    }

    public function getVoter(): string
    {
        return $this->voter;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
