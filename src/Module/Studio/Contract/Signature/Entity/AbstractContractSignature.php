<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Signature\Entity;

use Aurora\Core\Encryption\Doctrine\EncryptedTextType;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\Contract\Entity\ContractInterface;
use Aurora\Module\Studio\Contract\Signature\Enum\ContractSignatureRoleEnum;
use Aurora\Module\Studio\Contract\Signature\Exception\SignedContractIsImmutableException;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use function implode;
use function mb_trim;

/**
 * One party's signature, and everything that makes it defensible.
 *
 * This table is the answer to a gap the audit found: `AuditLog` cannot carry
 * this evidence. It has no IP column, no user agent, and its actor is null for
 * a guest - which is exactly who signs here. A signature that could only be
 * proven by an audit row would be a signature with no proof at all.
 *
 * The columns fall into three groups, and keeping them apart is the point.
 *
 * **What the signer declared.** Their name, their email, the place and the date
 * they wrote. Stated by them, so worth exactly what a statement is worth.
 *
 * **What the server observed.** The moment on the server clock, the address the
 * request came from, the user agent, which link was used, and whether a code
 * mailed to the contract's address was verified. None of this is typed by
 * anybody, which is what makes it evidence rather than testimony.
 *
 * **What was signed.** The hash of the document at the moment of signing. If
 * the stored document ever stops matching it, this row says what was agreed to
 * and the contract says something else - and that discrepancy is detectable
 * rather than silent.
 *
 * The declared date and the observed one are both kept, deliberately. They are
 * usually the same day and occasionally not, and a signer writing yesterday's
 * date is a fact about the document worth preserving rather than correcting.
 *
 * Append-only, enforced: every setter refuses once the row has an id.
 */
#[ORM\MappedSuperclass]
abstract class AbstractContractSignature implements ContractSignatureInterface
{
    /**
     * RESTRICT, unlike everything else pointing at a contract.
     *
     * Deleting a contract somebody signed has to fail loudly. Every other
     * relation in this module degrades gracefully on delete; this one must not,
     * because the row it would take with it is the evidence.
     */
    #[ORM\ManyToOne(targetEntity: ContractInterface::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    protected ContractInterface $contract;

    #[ORM\Column(length: 16, enumType: ContractSignatureRoleEnum::class)]
    protected ContractSignatureRoleEnum $role;

    #[ORM\Column(length: 100)]
    protected string $declaredFirstName;

    #[ORM\Column(length: 100)]
    protected string $declaredLastName;

    /**
     * The address the signer typed.
     *
     * Kept even though the contract already names one, and kept separately:
     * they are usually identical and the difference matters when they are not.
     * The code went to the contract's address; this is who says they signed.
     */
    #[ORM\Column(length: 180)]
    protected string $declaredEmail;

    /** "Fait à …" - the blank the signer fills, as they wrote it. */
    #[ORM\Column(length: 120)]
    protected string $declaredPlace;

    /** "le …" - the date the signer states, which is not always today. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    protected DateTimeImmutable $declaredDate;

    /**
     * The server's own clock.
     *
     * The one time in this row nobody chose. Where the declared date and this
     * disagree, this is the one that says when the request arrived.
     */
    #[ORM\Column]
    protected DateTimeImmutable $signedAt;

    /**
     * The address the request came from.
     *
     * Forty-five characters: an IPv6 address with an embedded IPv4 suffix is
     * the longest form that reaches this, and truncating an address would make
     * the evidence worse than absent.
     */
    #[ORM\Column(length: 45, nullable: true)]
    protected ?string $ipAddress = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    protected ?string $userAgent = null;

    /**
     * Which address was used to reach the document.
     *
     * The selector, never the secret: the secret is not stored anywhere, and
     * this answers "which link was this" without being one.
     */
    #[ORM\Column(length: 32, nullable: true)]
    protected ?string $linkSelector = null;

    /**
     * When the code mailed to the contract's address was verified.
     *
     * Null for a provider, who is authenticated instead. For a customer this is
     * the column that turns "somebody who had the link" into "somebody who
     * also controls the mailbox the contract names".
     */
    #[ORM\Column(nullable: true)]
    protected ?DateTimeImmutable $challengeVerifiedAt = null;

    /**
     * The address the verified code was mailed to, as it was at that moment.
     *
     * Copied from the code rather than read from the customer: the signed
     * PDF used to print the customer's current address, so a contractual
     * email changed between the signature and the countersignature made the
     * document certify a code sent to a mailbox that never received it. Null
     * for a provider, and for signatures older than the column that no code
     * could be traced back to.
     */
    #[ORM\Column(length: 180, nullable: true)]
    protected ?string $challengeSentTo = null;

    /**
     * The account that signed, for the provider.
     *
     * The counterpart of the mailed code: a session rather than a mailbox, and
     * a stronger link to a person. Null for a customer, who has no account -
     * which is the whole point of the public link.
     */
    #[ORM\ManyToOne(targetEntity: CoreUserInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?CoreUserInterface $user = null;

    /**
     * The document's hash at the moment of signing.
     *
     * Copied rather than read back from the contract, and that is the entire
     * value of the column: if the two ever disagree, this row still says what
     * was agreed to.
     */
    #[ORM\Column(length: 64)]
    protected string $signedContentHash;

    /**
     * The drawn signature, as a data URI.
     *
     * Encrypted at rest: it is a biometric-adjacent trace of a real person, it
     * has no use outside the document it belongs to, and a database dump should
     * not hand out images of people's signatures.
     */
    #[ORM\Column(type: EncryptedTextType::NAME, nullable: true)]
    protected ?string $signatureImage = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    protected DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    abstract public function getId(): ?int;

    /**
     * Refuses every write once the row exists.
     *
     * The id is the test rather than a flag: a signature that has been
     * persisted has been recorded, and there is no version of amending it that
     * is not falsifying it.
     */
    public function assertUnrecorded(): void
    {
        if (null !== $this->getId()) {
            throw SignedContractIsImmutableException::forSignature($this->getId());
        }
    }

    public function getContract(): ContractInterface
    {
        return $this->contract;
    }

    public function setContract(ContractInterface $contract): static
    {
        $this->assertUnrecorded();

        $this->contract = $contract;

        return $this;
    }

    public function getRole(): ContractSignatureRoleEnum
    {
        return $this->role;
    }

    public function setRole(ContractSignatureRoleEnum $role): static
    {
        $this->assertUnrecorded();

        $this->role = $role;

        return $this;
    }

    public function getDeclaredFirstName(): string
    {
        return $this->declaredFirstName;
    }

    public function setDeclaredFirstName(string $declaredFirstName): static
    {
        $this->assertUnrecorded();

        $this->declaredFirstName = $declaredFirstName;

        return $this;
    }

    public function getDeclaredLastName(): string
    {
        return $this->declaredLastName;
    }

    public function setDeclaredLastName(string $declaredLastName): static
    {
        $this->assertUnrecorded();

        $this->declaredLastName = $declaredLastName;

        return $this;
    }

    public function getDeclaredFullName(): string
    {
        $parts = [];

        foreach ([$this->declaredFirstName, $this->declaredLastName] as $part) {
            $part = mb_trim($part);

            if ('' !== $part) {
                $parts[] = $part;
            }
        }

        return implode(' ', $parts);
    }

    public function getDeclaredEmail(): string
    {
        return $this->declaredEmail;
    }

    public function setDeclaredEmail(string $declaredEmail): static
    {
        $this->assertUnrecorded();

        $this->declaredEmail = $declaredEmail;

        return $this;
    }

    public function getDeclaredPlace(): string
    {
        return $this->declaredPlace;
    }

    public function setDeclaredPlace(string $declaredPlace): static
    {
        $this->assertUnrecorded();

        $this->declaredPlace = $declaredPlace;

        return $this;
    }

    public function getDeclaredDate(): DateTimeImmutable
    {
        return $this->declaredDate;
    }

    public function setDeclaredDate(DateTimeImmutable $declaredDate): static
    {
        $this->assertUnrecorded();

        $this->declaredDate = $declaredDate;

        return $this;
    }

    public function getSignedAt(): DateTimeImmutable
    {
        return $this->signedAt;
    }

    public function setSignedAt(DateTimeImmutable $signedAt): static
    {
        $this->assertUnrecorded();

        $this->signedAt = $signedAt;

        return $this;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(?string $ipAddress): static
    {
        $this->assertUnrecorded();

        $this->ipAddress = $ipAddress;

        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): static
    {
        $this->assertUnrecorded();

        $this->userAgent = $userAgent;

        return $this;
    }

    public function getLinkSelector(): ?string
    {
        return $this->linkSelector;
    }

    public function setLinkSelector(?string $linkSelector): static
    {
        $this->assertUnrecorded();

        $this->linkSelector = $linkSelector;

        return $this;
    }

    public function getChallengeSentTo(): ?string
    {
        return $this->challengeSentTo;
    }

    public function setChallengeSentTo(?string $challengeSentTo): static
    {
        $this->challengeSentTo = $challengeSentTo;

        return $this;
    }

    public function getChallengeVerifiedAt(): ?DateTimeImmutable
    {
        return $this->challengeVerifiedAt;
    }

    public function setChallengeVerifiedAt(?DateTimeImmutable $challengeVerifiedAt): static
    {
        $this->assertUnrecorded();

        $this->challengeVerifiedAt = $challengeVerifiedAt;

        return $this;
    }

    public function getUser(): ?CoreUserInterface
    {
        return $this->user;
    }

    public function setUser(?CoreUserInterface $user): static
    {
        $this->assertUnrecorded();

        $this->user = $user;

        return $this;
    }

    public function getSignedContentHash(): string
    {
        return $this->signedContentHash;
    }

    public function setSignedContentHash(string $signedContentHash): static
    {
        $this->assertUnrecorded();

        $this->signedContentHash = $signedContentHash;

        return $this;
    }

    public function getSignatureImage(): ?string
    {
        return $this->signatureImage;
    }

    public function setSignatureImage(?string $signatureImage): static
    {
        $this->assertUnrecorded();

        $this->signatureImage = $signatureImage;

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Whether this signature still covers the document it is attached to.
     *
     * The one question this row exists to answer. False means the contract's
     * document changed after somebody signed it, which is an incident and not
     * an error.
     */
    public function coversCurrentDocument(): bool
    {
        return $this->signedContentHash === $this->contract->getContentHash();
    }
}
