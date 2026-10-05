<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Search;

use Aurora\Core\Search\BackendSearchProviderInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\Contract\Entity\ContractInterface;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateInterface;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateVersionInterface;
use Aurora\Module\Studio\Contract\Repository\ContractRepository;
use Aurora\Module\Studio\Contract\Repository\ContractTemplateRepository;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\Customer\Repository\CustomerRepository;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Repository\CustomerSpaceRepository;
use Aurora\Module\Studio\CustomerSpace\Security\SpaceVisibility;
use Aurora\Module\Studio\Deck\Entity\DeckInterface;
use Aurora\Module\Studio\Deck\Repository\DeckRepository;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableInterface;
use Aurora\Module\Studio\Deliverable\Repository\DeliverableRepository;
use Aurora\Module\Studio\Deliverable\Security\DeliverableAccess;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItemInterface;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentItemRepository;
use Aurora\Module\Studio\StudioContext;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

use function array_filter;
use function array_map;
use function array_values;
use function count;
use function implode;

/**
 * Studio's slice of the backend global search: client spaces and their
 * cards, customers, contracts, contract templates, decks and deliverables.
 *
 * **One provider, seven sections, each with its own door.** Studio is several
 * screens behind several switches and several privileges, and the search has to
 * ask each section the question its screen asks: the customers list answers to
 * `studio.customers.view` and to the customers switch, the spaces to theirs. A
 * single guard for the module would hand contract references to an account that
 * may only open decks.
 *
 * **Spaces and their cards are scoped like the spaces list**, through
 * {@see SpaceVisibility::seesAll()}: an administrator sees every space, anybody
 * else the ones they are a member of. A card title from a space the reader is
 * not on is exactly the leak the membership rule exists to prevent.
 *
 * **Each section fails alone.** The contract says never throw; one section's
 * query failing takes that section out, not the five others with it.
 *
 * Every row carries its own `path`, as the palette expects of a module's rows:
 * core's search does not know Studio's routes and should not have to.
 */
final readonly class StudioBackendSearchProvider implements BackendSearchProviderInterface
{
    private const int LIMIT = 8;

    public function __construct(
        private StudioContext $studioContext,
        private Security $security,
        private SpaceVisibility $visibility,
        private CustomerSpaceRepository $spaces,
        private SpaceContentItemRepository $items,
        private CustomerRepository $customers,
        private ContractRepository $contracts,
        private ContractTemplateRepository $templates,
        private DeckRepository $decks,
        private DeliverableRepository $deliverables,
        private DeliverableAccess $deliverableAccess,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
    ) {}

    public function search(string $query): array
    {
        try {
            $user = $this->security->getUser();
            if (!$user instanceof CoreUserInterface || !$this->studioContext->isBackendEnabled() || '' === mb_trim($query)) {
                return [];
            }

            $sections = [];

            if ($this->studioContext->areSpacesEnabled() && $this->security->isGranted('studio.spaces.view')) {
                // Null for "every space": computed once for both sections.
                $spaceIds = $this->visibleSpaceIds($user);
                $sections['spaces'] = $this->section(fn (): array => $this->spaceRows($query, $spaceIds));
                $sections['space_contents'] = $this->section(fn (): array => $this->itemRows($query, $spaceIds));
            }

            if ($this->studioContext->areCustomersEnabled() && $this->security->isGranted('studio.customers.view')) {
                $sections['customers'] = $this->section(fn (): array => $this->customerRows($query));
            }

            if ($this->studioContext->areContractsEnabled()) {
                if ($this->security->isGranted('studio.contracts.view')) {
                    $sections['contracts'] = $this->section(fn (): array => $this->contractRows($query));
                }

                // The templates screen has its own privilege, and shares the
                // contracts switch: it sits under the same menu entry.
                if ($this->security->isGranted('studio.contract_templates.view')) {
                    $sections['contract_templates'] = $this->section(fn (): array => $this->templateRows($query));
                }
            }

            if ($this->studioContext->areDecksEnabled() && $this->security->isGranted('studio.decks.view')) {
                $sections['decks'] = $this->section(fn (): array => $this->deckRows($query));
            }

            // A deliverable lives in Studio or in a space, behind a switch and a
            // privilege each: the section opens when either door does, and each
            // row is then checked against the one rule that decides who reads it.
            if (($this->studioContext->areDeliverablesEnabled() && $this->security->isGranted(DeliverableAccess::VIEW))
                || ($this->studioContext->areSpacesEnabled() && $this->security->isGranted('studio.spaces.view'))) {
                $sections['deliverables'] = $this->section(fn (): array => $this->deliverableRows($query));
            }

            return $sections;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param callable(): list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function section(callable $rows): array
    {
        try {
            return $rows();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Null when the reader sees every space, their memberships otherwise.
     *
     * @return list<int>|null
     */
    private function visibleSpaceIds(CoreUserInterface $user): ?array
    {
        return $this->visibility->seesAll() ? null : $this->spaces->findIdsWhereMember($user);
    }

    /**
     * @param list<int>|null $spaceIds
     *
     * @return list<array<string, mixed>>
     */
    private function spaceRows(string $query, ?array $spaceIds): array
    {
        return array_map(
            fn (CustomerSpaceInterface $space): array => [
                'id' => $space->getId(),
                'title' => $space->getName(),
                'subtitle' => $this->join([
                    $space->getCustomer()->getLegalName(),
                    $space->isArchived() ? $this->translator->trans($space->getStatus()->getLabelKey()) : null,
                ]),
                'path' => $this->urlGenerator->generate('workspace_space_content', ['id' => $space->getId()]),
            ],
            $this->spaces->searchByName($query, $spaceIds, self::LIMIT),
        );
    }

    /**
     * @param list<int>|null $spaceIds
     *
     * @return list<array<string, mixed>>
     */
    private function itemRows(string $query, ?array $spaceIds): array
    {
        return array_map(
            fn (SpaceContentItemInterface $item): array => [
                'id' => $item->getId(),
                'title' => $item->getTitle(),
                'subtitle' => $this->join([$item->getSpace()->getName(), $item->getColumn()->getName()]),
                // `?item=` opens the card on the space's board, as the
                // notifications and the calendar already link to it.
                'path' => $this->urlGenerator->generate('workspace_space_content', [
                    'id' => $item->getSpace()->getId(),
                    'item' => $item->getId(),
                ]),
            ],
            $this->items->searchByTitle($query, $spaceIds, self::LIMIT),
        );
    }

    /** @return list<array<string, mixed>> */
    private function customerRows(string $query): array
    {
        return array_map(
            fn (CustomerInterface $customer): array => [
                'id' => $customer->getId(),
                'title' => $customer->getLegalName(),
                'subtitle' => $this->join([
                    $this->translator->trans($customer->getStatus()->getLabelKey()),
                    $customer->getSiret(),
                ]),
                // The list has no page per customer; it reads `?search=` on
                // load, so the link lands on the list filtered to this one.
                'path' => $this->urlGenerator->generate('backend_studio_customers', ['search' => $customer->getLegalName()]),
            ],
            $this->customers->searchByNameOrNumber($query, self::LIMIT),
        );
    }

    /** @return list<array<string, mixed>> */
    private function contractRows(string $query): array
    {
        return array_map(
            fn (ContractInterface $contract): array => [
                'id' => $contract->getId(),
                // A draft has no reference yet: the document page calls it
                // this, so the search does too.
                'title' => $contract->getReference() ?? $this->translator->trans('backend.studio.contracts.draft_title'),
                'subtitle' => $this->join([
                    $contract->getCustomer()->getLegalName(),
                    $contract->getBodyVersion()?->getTemplate()->getName(),
                    $this->translator->trans($contract->getStatus()->getLabel()),
                ]),
                'path' => $this->urlGenerator->generate('backend_studio_contracts_show', ['id' => $contract->getId()]),
            ],
            $this->contracts->search($query, self::LIMIT),
        );
    }

    /** @return list<array<string, mixed>> */
    private function templateRows(string $query): array
    {
        return array_map(
            function (ContractTemplateInterface $template): array {
                $category = $template->getCategory()?->getLabel();

                return [
                    'id' => $template->getId(),
                    'title' => $template->getName(),
                    'subtitle' => $this->join([
                        $this->translator->trans($template->getKind()->getLabel()),
                        null === $category ? null : $this->translator->trans($category),
                        $template->isArchived() ? $this->translator->trans('backend.studio.contract_templates.state_archived') : null,
                    ]),
                    'path' => $this->templatePath($template),
                ];
            },
            $this->templates->searchByName($query, self::LIMIT),
        );
    }

    /**
     * The version in force, or the draft when nothing is published yet.
     *
     * The same first link the templates list gives a row. A template with
     * neither does not exist in practice (creating one opens its draft), but
     * the list filtered to its name is the honest fallback if it ever does.
     */
    private function templatePath(ContractTemplateInterface $template): string
    {
        $version = $template->getLatestPublishedVersion() ?? $template->getDraft();

        if (!$version instanceof ContractTemplateVersionInterface) {
            return $this->urlGenerator->generate('backend_studio_contract_templates', ['search' => $template->getName()]);
        }

        return $this->urlGenerator->generate('backend_studio_contract_templates_editor', [
            'id' => $template->getId(),
            'versionId' => $version->getId(),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function deckRows(string $query): array
    {
        return array_map(
            fn (DeckInterface $deck): array => [
                'id' => $deck->getId(),
                'title' => $deck->getTitle(),
                'subtitle' => $this->join([
                    $deck->isTemplate() ? $this->translator->trans('backend.studio.decks.template_badge') : null,
                    $deck->getCustomer()?->getLegalName() ?? $deck->getCategory()?->getName(),
                ]),
                'path' => $this->urlGenerator->generate('backend_studio_deck', ['id' => $deck->getId()]),
            ],
            $this->decks->searchByTitle($query, self::LIMIT),
        );
    }

    /**
     * The deliverables the reader may open, whichever side they live on.
     *
     * Candidates by title, then each one through {@see DeliverableAccess::canRead()}:
     * a personal deliverable of a colleague, or one in a space the reader is not
     * on, is exactly what a title in a search result must not reveal. Asked for
     * more than the section shows, so filtering still leaves a full list.
     *
     * @return list<array<string, mixed>>
     */
    private function deliverableRows(string $query): array
    {
        $rows = [];

        foreach ($this->deliverables->searchByTitle($query, self::LIMIT * 5) as $deliverable) {
            if (!$this->isSearchable($deliverable)) {
                continue;
            }

            $space = $deliverable->getSpace();
            $rows[] = [
                'id' => $deliverable->getId(),
                'title' => $deliverable->getTitle(),
                'subtitle' => $this->join([
                    $space instanceof CustomerSpaceInterface ? $space->getName() : $this->translator->trans('backend.studio.deliverables.scope.'.$deliverable->getScope()->value),
                    $deliverable->getSummary(),
                ]),
                'path' => $space instanceof CustomerSpaceInterface
                    ? $this->urlGenerator->generate('workspace_space_deliverables_edit', ['id' => $space->getId(), 'deliverableId' => $deliverable->getId()])
                    : $this->urlGenerator->generate('backend_studio_deliverables_edit', ['id' => $deliverable->getId()]),
            ];

            if (count($rows) >= self::LIMIT) {
                break;
            }
        }

        return $rows;
    }

    private function isSearchable(DeliverableInterface $deliverable): bool
    {
        $served = $deliverable->isStandalone() ? $this->studioContext->areDeliverablesEnabled() : $this->studioContext->areSpacesEnabled();

        return $served && $this->deliverableAccess->canRead($deliverable);
    }

    /**
     * The parts that exist, joined by a middle dot; null when none does.
     *
     * @param list<string|null> $parts
     */
    private function join(array $parts): ?string
    {
        $kept = array_values(array_filter($parts, static fn (?string $part): bool => null !== $part && '' !== $part));

        return [] === $kept ? null : implode(' · ', $kept);
    }
}
