<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Entity;

use Aurora\Core\Money\Enum\CurrencyEnum;
use Aurora\Core\Timestampable\TimestampableTrait;
use Aurora\Module\Studio\Contract\Enum\ContractStatusEnum;
use Aurora\Module\Studio\Contract\Enum\ContractTemplateKindEnum;
use Aurora\Module\Studio\Contract\Enum\ContractTerminationOriginEnum;
use Aurora\Module\Studio\Contract\Exception\ContractPdfAlreadyGeneratedException;
use Aurora\Module\Studio\Contract\Exception\FrozenContractIsImmutableException;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use LogicException;

use function sprintf;

/**
 * One contract, for one customer, built from templates and then frozen.
 *
 * Two halves live here and they must not be confused. Before freezing, the
 * contract is a set of choices: which body, which annex, which customer, which
 * amount. After freezing, it is a document: a snapshot of the wording with the
 * variables substituted, the HTML that was rendered from it, and the hash of
 * that HTML. From then on the choices are history and the document is what
 * counts, because the document is what somebody was asked to sign.
 *
 * The freeze happens when the link is created, not when anybody signs. That
 * ordering is the whole point: nothing can move between the moment the
 * customer receives the document and the moment they sign it, and no
 * signature - theirs or the provider's - can ever be invalidated by an edit,
 * because there is no edit to make.
 *
 * The snapshot is why deleting a template is safe. A contract reads nothing
 * back from the version it came from; the version relations exist for the
 * trail, and both are `SET NULL` on delete so losing a template loses the
 * trail and never the document.
 *
 * Two variables are deliberately NOT substituted at freeze time: the city and
 * the date of signature, which the signer states. They stay as tokens in the
 * snapshot and are rendered into the final PDF from the signature, exactly as
 * "fait à …, le …" is a blank on paper.
 */
#[ORM\MappedSuperclass]
#[ORM\HasLifecycleCallbacks]
abstract class AbstractContract implements ContractInterface
{
    use TimestampableTrait;

    /**
     * `CTR-2026-0001` by default, `CM-2026-0001` where the setting says so.
     *
     * Assigned when the contract is frozen rather than when it is created: the
     * reference is printed inside the document, so it has to exist before the
     * rendering that the hash covers - and a draft that never goes out should
     * not burn a number that an accountant will later look for.
     */
    #[ORM\Column(length: 32, unique: true, nullable: true)]
    protected ?string $reference = null;

    #[ORM\ManyToOne(targetEntity: CustomerInterface::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    protected CustomerInterface $customer;

    /**
     * The version the body came from, for the trail only.
     *
     * `SET NULL`: a template can be deleted, and when it is, this contract
     * loses the answer to "which trame was this" and keeps its document.
     */
    #[ORM\ManyToOne(targetEntity: ContractTemplateVersionInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?ContractTemplateVersionInterface $bodyVersion = null;

    #[ORM\ManyToOne(targetEntity: ContractTemplateVersionInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?ContractTemplateVersionInterface $annexVersion = null;

    /** The language the contract is written and signed in. */
    #[ORM\Column(length: 10)]
    protected string $locale = 'fr';

    #[ORM\Column(length: 24, enumType: ContractStatusEnum::class)]
    protected ContractStatusEnum $status = ContractStatusEnum::Draft;

    /**
     * The values filled in per signature, keyed by token without its braces.
     *
     * Only the freeze-time ones: `contract.amount`, `contract.effective_date`.
     * The customer's own fields are read off the Customer, never copied here,
     * so a typo corrected on the customer is corrected everywhere that is not
     * yet frozen.
     *
     * @var array<string, string>
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    protected array $variables = [];

    /**
     * What this one contract fills in that no template can know.
     *
     * The trames a business actually signs carry a handful of blanks that are
     * neither the customer's identity nor the module's own fields: the person
     * habilitated to validate, a kilometric threshold, a deposit rate. They
     * vary per contract, so they cannot live in the wording, and they are not
     * customer data, so they cannot live on the Customer.
     *
     * The wording declares them by using them: `{{contract.custom.acompte}}`
     * in a published version makes `acompte` a key this contract has to carry,
     * non-empty, before it can be sealed. A blank in a signed document is the
     * one outcome this must never produce.
     *
     * @var array<string, string>
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    protected array $customFields = [];

    /**
     * The wording adapted for this contract alone, by part (`body`, `annex`).
     *
     * A trame is the model; a client sometimes needs a clause moved, added or
     * struck. Copying the trame into a new one for a single client is how a
     * list of trames fills with near-duplicates that no longer follow the
     * original. The adapted text lives here instead, on the contract it was
     * written for: it is sealed with it, deleted with it, and can never
     * outlive or leak into another contract.
     *
     * Each entry keeps the language it was written in and the version it was
     * adapted from, so a contract can always say « version 3 of this trame,
     * adapted », and changing either the trame or the language drops the
     * adaptation rather than sealing a text written for something else.
     *
     * @var array<string, array{locale: string, title: string, blocks: list<mixed>, baseVersionId: int|null, adaptedAt: string}>
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    protected array $adaptedWording = [];

    #[ORM\Column(nullable: true)]
    protected ?int $amountCents = null;

    #[ORM\Column(length: 3, nullable: true, enumType: CurrencyEnum::class)]
    protected ?CurrencyEnum $amountCurrency = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    protected ?DateTimeImmutable $effectiveDate = null;

    #[ORM\Column(nullable: true)]
    protected ?DateTimeImmutable $frozenAt = null;

    /**
     * The contract this one amends, when it is an amendment.
     *
     * An amendment is a **document of its own**, not a new state on the one it
     * changes. It has to be: a sealed contract is immutable by design, so
     * "modify the annex of a signed contract" has no implementation that is
     * not a lie. What happens on paper is what happens here - a second
     * document, signed by both parties, that says which part of the first it
     * replaces.
     *
     * That is also the answer to the annex modified after the body was signed:
     * the original keeps its own sealed copy of both halves forever, and the
     * amendment carries the new annex. Nothing is rewritten, and the history
     * reads in the order it happened.
     *
     * `SET NULL` on delete, with the reference copied beside it: once the
     * parent's retention runs out and somebody deletes it, the amendment still
     * says what it amended. The same reason the document keeps its own snapshot
     * of the wording rather than reading it back from the template.
     */
    #[ORM\ManyToOne(targetEntity: ContractInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?ContractInterface $amends = null;

    #[ORM\Column(length: 32, nullable: true)]
    protected ?string $amendsReference = null;

    /**
     * Which amendment of its parent this is, counted at the freeze.
     *
     * At the freeze and not at the creation, so a draft abandoned before it
     * went anywhere consumes no number - unlike a template version, where the
     * number is claimed early on purpose because a draft is already a thing
     * somebody worked on.
     */
    #[ORM\Column(nullable: true)]
    protected ?int $amendmentRank = null;

    /**
     * The end of the relationship, which is not the end of the document.
     *
     * Deliberately not a status. A concluded contract stays concluded forever:
     * it was signed, and that fact does not expire. Termination is something
     * that happened *to the relationship* afterwards, with its own two dates -
     * when notice was given, and when it takes effect - because a notice
     * period is exactly the gap between them.
     *
     * Not a signature flow either. A customer terminates by writing an email,
     * not by clicking a link in an application they do not have an account on;
     * building a form for it would be building something nobody can use. What
     * the application can do is record the fact, which is what an accountant
     * and a dispute both need.
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    protected ?DateTimeImmutable $terminationNoticedAt = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    protected ?DateTimeImmutable $terminationEffectiveAt = null;

    #[ORM\Column(length: 20, nullable: true, enumType: ContractTerminationOriginEnum::class)]
    protected ?ContractTerminationOriginEnum $terminationOrigin = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    protected ?string $terminationReason = null;

    /**
     * When the customer said no, and what they said about it.
     *
     * A refusal is an answer, not an absence, and the difference matters to
     * whoever reads the list: a contract nobody ever opened and one the
     * customer declined are two different conversations to have. The three
     * observed fields are the same evidence a signature carries, for the same
     * reason - the act came from whoever held the address, and that is worth
     * recording even when the act creates no obligation.
     *
     * The reason is the customer's own words and is optional. Demanding a
     * justification to decline would be a small piece of coercion in a
     * document about consent.
     */
    #[ORM\Column(nullable: true)]
    protected ?DateTimeImmutable $refusedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    protected ?string $refusalReason = null;

    #[ORM\Column(length: 45, nullable: true)]
    protected ?string $refusedFromIp = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    protected ?string $refusedUserAgent = null;

    /**
     * How many times this contract has been chased, and when last.
     *
     * On the contract rather than on the link, because a reminder hands out a
     * new address: counting on the link would restart at zero every time and
     * the ceiling would never be reached.
     */
    #[ORM\Column(options: ['default' => 0])]
    protected int $reminderCount = 0;

    #[ORM\Column(nullable: true)]
    protected ?DateTimeImmutable $lastReminderAt = null;

    /**
     * The canonical document, as the deterministic structure it was hashed
     * from.
     *
     * Kept alongside the rendered HTML rather than instead of it. This one is
     * what a later verification recomputes against; the HTML is what a human
     * read. Recomputing the HTML from this would mean trusting the renderer of
     * the day, which is exactly what a frozen document must not depend on.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    protected array $contentSnapshot = [];

    /** The document a human read, variables already substituted. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    protected ?string $renderedHtml = null;

    /** Hex SHA-256 of the rendered document. */
    #[ORM\Column(length: 64, nullable: true)]
    protected ?string $contentHash = null;

    #[ORM\Column(length: 16, nullable: true)]
    protected ?string $hashAlgo = null;

    /**
     * Where the signed PDF lives, relative to the upload directory.
     *
     * Null until the countersignature. Its presence is what says a PDF exists,
     * and what makes a second generation refuse rather than overwrite.
     */
    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $pdfPath = null;

    /**
     * SHA-256 of the file itself.
     *
     * A different thing from `contentHash`, which covers the document. This one
     * covers the bytes on disk, so a PDF replaced on the filesystem is
     * detectable even though the contract it belongs to still verifies.
     */
    #[ORM\Column(length: 64, nullable: true)]
    protected ?string $pdfHash = null;

    #[ORM\Column(nullable: true)]
    protected ?DateTimeImmutable $pdfGeneratedAt = null;

    /**
     * Which canonicalisation produced the hash.
     *
     * Stored because the rule may have to change one day, and a hash means
     * nothing without knowing how it was computed. A verification reads this
     * and applies the matching rule instead of assuming the current one.
     */
    #[ORM\Column(nullable: true)]
    protected ?int $canonicalVersion = null;

    abstract public function getId(): ?int;

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): static
    {
        $this->assertEditable();

        $this->reference = $reference;

        return $this;
    }

    public function getCustomer(): CustomerInterface
    {
        return $this->customer;
    }

    public function setCustomer(CustomerInterface $customer): static
    {
        $this->assertEditable();

        $this->customer = $customer;

        return $this;
    }

    public function getBodyVersion(): ?ContractTemplateVersionInterface
    {
        return $this->bodyVersion;
    }

    public function setBodyVersion(?ContractTemplateVersionInterface $bodyVersion): static
    {
        $this->assertEditable();

        // Another trame is another text: an adaptation of the previous one
        // would otherwise be sealed under the name of a trame it never was.
        if ($bodyVersion?->getId() !== $this->bodyVersion?->getId()) {
            unset($this->adaptedWording[ContractTemplateKindEnum::Body->value]);
        }

        $this->bodyVersion = $bodyVersion;

        return $this;
    }

    public function getAnnexVersion(): ?ContractTemplateVersionInterface
    {
        return $this->annexVersion;
    }

    public function setAnnexVersion(?ContractTemplateVersionInterface $annexVersion): static
    {
        $this->assertEditable();

        if ($annexVersion?->getId() !== $this->annexVersion?->getId()) {
            unset($this->adaptedWording[ContractTemplateKindEnum::Annex->value]);
        }

        $this->annexVersion = $annexVersion;

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): static
    {
        $this->assertEditable();

        // An adaptation is written in one language; a contract that changes
        // language starts again from the trame's text in the new one.
        if ($locale !== $this->locale) {
            $this->adaptedWording = [];
        }

        $this->locale = $locale;

        return $this;
    }

    public function getStatus(): ContractStatusEnum
    {
        return $this->status;
    }

    /**
     * Moves the contract along.
     *
     * Not guarded by `assertEditable()`, and it is the one writer that is not:
     * every state after the freeze is reached by changing the status, so a
     * guard here would make the freeze the last thing that ever happened.
     */
    public function setStatus(ContractStatusEnum $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getVariables(): array
    {
        return $this->variables;
    }

    public function setVariables(array $variables): static
    {
        $this->assertEditable();

        $this->variables = $variables;

        return $this;
    }

    /** @return array<string, string> */
    public function getCustomFields(): array
    {
        return $this->customFields;
    }

    /** @param array<string, string> $customFields */
    public function setCustomFields(array $customFields): static
    {
        $this->assertEditable();

        $this->customFields = $customFields;

        return $this;
    }

    public function getAmountCents(): ?int
    {
        return $this->amountCents;
    }

    public function setAmountCents(?int $amountCents): static
    {
        $this->assertEditable();

        $this->amountCents = $amountCents;

        return $this;
    }

    public function getAmountCurrency(): ?CurrencyEnum
    {
        return $this->amountCurrency;
    }

    public function setAmountCurrency(?CurrencyEnum $amountCurrency): static
    {
        $this->assertEditable();

        $this->amountCurrency = $amountCurrency;

        return $this;
    }

    public function getEffectiveDate(): ?DateTimeImmutable
    {
        return $this->effectiveDate;
    }

    public function setEffectiveDate(?DateTimeImmutable $effectiveDate): static
    {
        $this->assertEditable();

        $this->effectiveDate = $effectiveDate;

        return $this;
    }

    public function getFrozenAt(): ?DateTimeImmutable
    {
        return $this->frozenAt;
    }

    public function isFrozen(): bool
    {
        return $this->frozenAt instanceof DateTimeImmutable;
    }

    public function getContentSnapshot(): array
    {
        return $this->contentSnapshot;
    }

    public function getRenderedHtml(): ?string
    {
        return $this->renderedHtml;
    }

    public function getContentHash(): ?string
    {
        return $this->contentHash;
    }

    public function getHashAlgo(): ?string
    {
        return $this->hashAlgo;
    }

    public function getCanonicalVersion(): ?int
    {
        return $this->canonicalVersion;
    }

    /**
     * Seals the document, once.
     *
     * Everything the hash covers is written in this one call, so there is no
     * window in which a contract carries a snapshot and no hash, or a hash of
     * something other than what it holds. Calling it twice throws rather than
     * overwriting: a second freeze is a request to replace a document somebody
     * may already have read.
     */
    public function freeze(
        DateTimeImmutable $at,
        string $reference,
        array $snapshot,
        string $renderedHtml,
        string $contentHash,
        string $hashAlgo,
        int $canonicalVersion,
    ): static {
        $this->assertEditable();

        $this->reference = $reference;
        $this->contentSnapshot = $snapshot;
        $this->renderedHtml = $renderedHtml;
        $this->contentHash = $contentHash;
        $this->hashAlgo = $hashAlgo;
        $this->canonicalVersion = $canonicalVersion;
        $this->frozenAt = $at;
        // Sealed, not sent: the link has not gone out yet, and claiming it
        // had would be a statement nobody could verify.
        $this->status = ContractStatusEnum::Sealed;

        return $this;
    }

    public function getPdfPath(): ?string
    {
        return $this->pdfPath;
    }

    public function getPdfHash(): ?string
    {
        return $this->pdfHash;
    }

    public function getPdfGeneratedAt(): ?DateTimeImmutable
    {
        return $this->pdfGeneratedAt;
    }

    public function hasPdf(): bool
    {
        return null !== $this->pdfPath;
    }

    /**
     * Records the file, once.
     *
     * Not guarded by `assertEditable()` - the contract is frozen by the time
     * this runs, which is the point - but guarded against itself: a second call
     * would mean two files claim to be the same contract.
     */
    public function attachPdf(string $path, string $hash, DateTimeImmutable $at): static
    {
        if (null !== $this->pdfPath) {
            throw ContractPdfAlreadyGeneratedException::forContract($this->reference, $this->pdfPath);
        }

        $this->pdfPath = $path;
        $this->pdfHash = $hash;
        $this->pdfGeneratedAt = $at;

        return $this;
    }

    public function getRefusedAt(): ?DateTimeImmutable
    {
        return $this->refusedAt;
    }

    public function getRefusalReason(): ?string
    {
        return $this->refusalReason;
    }

    public function getRefusedFromIp(): ?string
    {
        return $this->refusedFromIp;
    }

    public function getRefusedUserAgent(): ?string
    {
        return $this->refusedUserAgent;
    }

    public function isRefused(): bool
    {
        return $this->refusedAt instanceof DateTimeImmutable;
    }

    /**
     * Records the refusal and moves the contract to it.
     *
     * The status and the trace are set together rather than by two callers:
     * a contract that says `refused` with no date, or carries a date without
     * saying so, is a half-state somebody would have to interpret.
     *
     * Refused on a signed contract is refused: an engagement is not undone by
     * a click on a page, and the manager refuses before reaching this.
     */
    public function refuse(
        DateTimeImmutable $at,
        ?string $reason = null,
        ?string $ip = null,
        ?string $userAgent = null,
    ): static {
        $this->refusedAt = $at;
        $this->refusalReason = '' === $reason ? null : $reason;
        $this->refusedFromIp = $ip;
        $this->refusedUserAgent = $userAgent;
        $this->status = ContractStatusEnum::Refused;

        return $this;
    }

    /**
     * Clears the refusal, because a new link is a new ask.
     *
     * The record of it does not disappear with it: the audit trail keeps the
     * refusal and its trace, which is where a question about the history
     * belongs. What this clears is the *current* state, and the current state
     * of a contract that has just been sent again is "sent".
     */
    public function clearRefusal(): static
    {
        $this->refusedAt = null;
        $this->refusalReason = null;
        $this->refusedFromIp = null;
        $this->refusedUserAgent = null;

        return $this;
    }

    public function getReminderCount(): int
    {
        return $this->reminderCount;
    }

    public function getLastReminderAt(): ?DateTimeImmutable
    {
        return $this->lastReminderAt;
    }

    public function markReminded(DateTimeImmutable $at): static
    {
        ++$this->reminderCount;
        $this->lastReminderAt = $at;

        return $this;
    }

    /**
     * The day the evidence stops being required.
     *
     * Counted from the freeze, which is the moment the document became final
     * and the only date that cannot move afterwards. Null before then: a draft
     * has nothing to keep.
     */
    public function retainedUntil(int $years): ?DateTimeImmutable
    {
        if (!$this->frozenAt instanceof DateTimeImmutable) {
            return null;
        }

        return $this->frozenAt->modify(sprintf('+%d years', $years));
    }

    public function getAmends(): ?ContractInterface
    {
        return $this->amends;
    }

    /**
     * Names the contract this one amends, before the seal.
     *
     * The reference is copied at the same time rather than read through the
     * relation later: the relation is `SET NULL` and the copy is what makes the
     * amendment still able to say what it amended once the parent's retention
     * has run out and somebody deleted it.
     */
    public function setAmends(?ContractInterface $amends): static
    {
        $this->assertEditable();

        $this->amends = $amends;
        $this->amendsReference = $amends?->getReference();

        return $this;
    }

    public function getAmendsReference(): ?string
    {
        return $this->amendsReference;
    }

    /**
     * Whether this document changes another one.
     *
     * Read from the copied reference as well as from the relation, so a
     * deleted parent does not turn an amendment back into an original.
     */
    public function isAmendment(): bool
    {
        return $this->amends instanceof ContractInterface || null !== $this->amendsReference;
    }

    public function getAmendmentRank(): ?int
    {
        return $this->amendmentRank;
    }

    public function setAmendmentRank(?int $rank): static
    {
        $this->amendmentRank = $rank;

        return $this;
    }

    public function getTerminationNoticedAt(): ?DateTimeImmutable
    {
        return $this->terminationNoticedAt;
    }

    public function getTerminationEffectiveAt(): ?DateTimeImmutable
    {
        return $this->terminationEffectiveAt;
    }

    public function getTerminationOrigin(): ?ContractTerminationOriginEnum
    {
        return $this->terminationOrigin;
    }

    public function getTerminationReason(): ?string
    {
        return $this->terminationReason;
    }

    public function isTerminated(): bool
    {
        return $this->terminationEffectiveAt instanceof DateTimeImmutable;
    }

    /**
     * Whether the relationship has actually stopped, as opposed to being due to.
     *
     * The two dates exist because a notice period is the gap between them: a
     * contract noticed today and effective in thirty days is still running,
     * and a screen that showed it as over would be wrong for a month.
     */
    public function isTerminationEffective(?DateTimeImmutable $on = null): bool
    {
        return $this->terminationEffectiveAt instanceof DateTimeImmutable
            && $this->terminationEffectiveAt <= ($on ?? new DateTimeImmutable());
    }

    /**
     * Records the end of the relationship.
     *
     * Not guarded by `assertEditable()`, and it is the second writer that is
     * not: everything about a termination happens after the seal, by
     * definition. The status is left alone on purpose - the document was
     * signed, and that stays true.
     */
    public function terminate(
        DateTimeImmutable $noticedAt,
        DateTimeImmutable $effectiveAt,
        ContractTerminationOriginEnum $origin,
        ?string $reason = null,
    ): static {
        $this->terminationNoticedAt = $noticedAt;
        $this->terminationEffectiveAt = $effectiveAt;
        $this->terminationOrigin = $origin;
        $this->terminationReason = '' === $reason ? null : $reason;

        return $this;
    }

    /**
     * @return array{locale: string, title: string, blocks: list<mixed>, baseVersionId: int|null, adaptedAt: string}|null
     */
    public function getAdaptedWording(ContractTemplateKindEnum $part): ?array
    {
        return $this->adaptedWording[$part->value] ?? null;
    }

    public function isAdapted(?ContractTemplateKindEnum $part = null): bool
    {
        return $part instanceof ContractTemplateKindEnum ? isset($this->adaptedWording[$part->value]) : [] !== $this->adaptedWording;
    }

    /** @param list<mixed> $blocks */
    public function adaptWording(
        ContractTemplateKindEnum $part,
        string $title,
        array $blocks,
        DateTimeImmutable $at,
    ): static {
        $this->assertEditable();

        $version = ContractTemplateKindEnum::Body === $part ? $this->bodyVersion : $this->annexVersion;

        if (!$version instanceof ContractTemplateVersionInterface) {
            throw new LogicException(sprintf('Contract %d has no %s to adapt.', (int) $this->getId(), $part->value));
        }

        $this->adaptedWording[$part->value] = [
            'locale' => $this->locale,
            'title' => $title,
            'blocks' => $blocks,
            'baseVersionId' => $version->getId(),
            'adaptedAt' => $at->format(DATE_ATOM),
        ];

        return $this;
    }

    public function resetWording(ContractTemplateKindEnum $part): static
    {
        $this->assertEditable();

        unset($this->adaptedWording[$part->value]);

        return $this;
    }

    /**
     * Copies another contract's adaptations, for a duplicate built on the
     * same trames and language. Only the parts that still match: a duplicate
     * of a cancelled contract keeps the text that was negotiated.
     */
    public function adoptWordingFrom(ContractInterface $source): static
    {
        $this->assertEditable();

        foreach (ContractTemplateKindEnum::cases() as $part) {
            $adapted = $source->getAdaptedWording($part);
            $version = ContractTemplateKindEnum::Body === $part ? $this->bodyVersion : $this->annexVersion;

            if (null !== $adapted && $adapted['locale'] === $this->locale && $adapted['baseVersionId'] === $version?->getId()) {
                $this->adaptedWording[$part->value] = $adapted;
            }
        }

        return $this;
    }

    public function assertEditable(): void
    {
        if ($this->isFrozen()) {
            throw FrozenContractIsImmutableException::forContract($this->getId(), $this->reference);
        }
    }
}
