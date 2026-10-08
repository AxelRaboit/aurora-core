<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Search;

use Aurora\Core\Search\SearchSnippetBuilder;
use Aurora\Core\Search\SuiteSearchProviderInterface;
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
use Aurora\Module\Studio\Deliverable\Entity\DeliverableInterface;
use Aurora\Module\Studio\Deliverable\Repository\DeliverableRepository;
use Aurora\Module\Studio\Deliverable\Security\DeliverableAccess;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatMessageInterface;
use Aurora\Module\Studio\SpaceChat\Repository\SpaceChatMessageRepository;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItemInterface;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentItemRepository;
use Aurora\Module\Studio\SpaceFile\Entity\SpaceFileInterface;
use Aurora\Module\Studio\SpaceFile\Repository\SpaceFileRepository;
use Aurora\Module\Studio\SpaceResource\Entity\SpaceResourceInterface;
use Aurora\Module\Studio\SpaceResource\Repository\SpaceResourceRepository;
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
use function preg_replace;

/**
 * Studio's slice of the suite global search: client spaces and their
 * cards, resources, files and conversation, customers, contracts, contract
 * templates and deliverables, pages and presentations alike.
 *
 * **One provider, nine sections, each with its own door.** Studio is several
 * screens behind several switches and several privileges, and the search has to
 * ask each section the question its screen asks: the customers list answers to
 * `studio.customers.view` and to the customers switch, the spaces to theirs. A
 * single guard for the module would hand contract references to an account that
 * may only open deliverables.
 *
 * **Spaces and everything in them are scoped like the spaces list**, through
 * {@see SpaceVisibility::seesAll()}: an administrator sees every space, anybody
 * else the ones they are a member of. A card title from a space the reader is
 * not on is exactly the leak the membership rule exists to prevent. The
 * conversation adds its own rule on top: a message is found only in a room the
 * reader has in their list, never in an internal room or a private
 * conversation they are not part of.
 *
 * **A space's notes are not here**: they live in the Notes module, whose own
 * search already covers every note space the reader may open, the ones synced
 * from client spaces included. Answering them twice would put each note under
 * two headings.
 *
 * Nothing searched here is encrypted (resource label, body and address, the
 * document title and file name, the message body are plain columns), so SQL
 * `LIKE` is the right tool. A column that becomes encrypted cannot be matched
 * this way and has to leave the query rather than be decrypted wholesale on
 * every keystroke, as the Notes provider explains.
 *
 * **Each section fails alone.** The contract says never throw; one section's
 * query failing takes that section out, not the eight others with it.
 *
 * Every row carries its own `path`, as the palette expects of a module's rows:
 * core's search does not know Studio's routes and should not have to.
 */
final readonly class StudioSuiteSearchProvider implements SuiteSearchProviderInterface
{
    private const int LIMIT = 8;

    /** Characters kept on each side of the match in a message snippet. */
    private const int SNIPPET_RADIUS = 40;

    public function __construct(
        private StudioContext $studioContext,
        private Security $security,
        private SpaceVisibility $visibility,
        private CustomerSpaceRepository $spaceRepository,
        private SpaceContentItemRepository $itemRepository,
        private CustomerRepository $customerRepository,
        private ContractRepository $contractRepository,
        private ContractTemplateRepository $templateRepository,
        private DeliverableRepository $deliverableRepository,
        private DeliverableAccess $deliverableAccess,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
        private SpaceResourceRepository $spaceResourceRepository,
        private SpaceFileRepository $spaceFileRepository,
        private SpaceChatMessageRepository $spaceChatMessageRepository,
        private SearchSnippetBuilder $searchSnippetBuilder,
    ) {}

    public function search(string $query): array
    {
        try {
            $user = $this->security->getUser();
            if (!$user instanceof CoreUserInterface || !$this->studioContext->isSuiteEnabled() || '' === mb_trim($query)) {
                return [];
            }

            $sections = [];

            if ($this->studioContext->areSpacesEnabled() && $this->security->isGranted('studio.spaces.view')) {
                // Null for "every space": computed once for every section.
                $spaceIds = $this->visibleSpaceIds($user);
                $sections['spaces'] = $this->section(fn (): array => $this->spaceRows($query, $spaceIds));
                $sections['space_contents'] = $this->section(fn (): array => $this->itemRows($query, $spaceIds));
                $sections['space_resources'] = $this->section(fn (): array => $this->resourceRows($query, $spaceIds));
                $sections['space_files'] = $this->section(fn (): array => $this->fileRows($query, $spaceIds));
                $sections['space_messages'] = $this->section(fn (): array => $this->messageRows($query, $spaceIds, $user));
            }

            if ($this->studioContext->areCustomersEnabled() && $this->security->isGranted('studio.customers.view')) {
                $sections['customers'] = $this->section(fn (): array => $this->customerRows($query));
            }

            if ($this->studioContext->areContractsEnabled()) {
                if ($this->security->isGranted('studio.contracts.view')) {
                    $sections['contracts'] = $this->section(fn (): array => $this->contractRows($query));
                }

                // The templates screen has its own privilege, and is a tab of
                // the contracts entry: it shares their switch.
                if ($this->security->isGranted('studio.contract_templates.view')) {
                    $sections['contract_templates'] = $this->section(fn (): array => $this->templateRows($query));
                }
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
        return $this->visibility->seesAll() ? null : $this->spaceRepository->findIdsWhereMember($user);
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
            $this->spaceRepository->searchByName($query, $spaceIds, self::LIMIT),
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
            $this->itemRepository->searchByTitle($query, $spaceIds, self::LIMIT),
        );
    }

    /**
     * @param list<int>|null $spaceIds
     *
     * @return list<array<string, mixed>>
     */
    private function resourceRows(string $query, ?array $spaceIds): array
    {
        return array_map(
            fn (SpaceResourceInterface $resource): array => [
                'id' => $resource->getId(),
                'title' => $resource->getLabel(),
                'subtitle' => $this->join([
                    $resource->getSpace()->getName(),
                    $this->translator->trans($resource->getKind()->getLabelKey()),
                ]),
                'path' => $this->spaceTab($resource->getSpace(), 'resources'),
            ],
            $this->spaceResourceRepository->search($query, $spaceIds, self::LIMIT),
        );
    }

    /**
     * @param list<int>|null $spaceIds
     *
     * @return list<array<string, mixed>>
     */
    private function fileRows(string $query, ?array $spaceIds): array
    {
        return array_map(
            function (SpaceFileInterface $file): array {
                $document = $file->getDocument();
                $name = $document->getOriginalName();

                return [
                    'id' => $file->getId(),
                    'title' => $document->getTitle(),
                    'subtitle' => $this->join([
                        $file->getSpace()->getName(),
                        // The file name only when it says something the title
                        // does not: most uploads are titled after it.
                        null !== $name && $name !== $document->getTitle() ? $name : null,
                        $file->isFromClient() ? $this->translator->trans('suite.studio.space_files.sent_by_client') : null,
                    ]),
                    'path' => $this->spaceTab($file->getSpace(), 'files'),
                ];
            },
            $this->spaceFileRepository->search($query, $spaceIds, self::LIMIT),
        );
    }

    /**
     * The messages, each shown by the words around the match.
     *
     * The title is the sentence and not the room: somebody searching the
     * conversation is looking for what was said, and the room and its author
     * are what tells two such sentences apart. The link opens the space on its
     * conversation, in that room, scrolled to that message.
     *
     * @param list<int>|null $spaceIds
     *
     * @return list<array<string, mixed>>
     */
    private function messageRows(string $query, ?array $spaceIds, CoreUserInterface $reader): array
    {
        return array_map(
            fn (SpaceChatMessageInterface $message): array => [
                'id' => $message->getId(),
                'title' => $this->snippet($message->getBody(), $query),
                'subtitle' => $this->join([
                    $message->getSpace()->getName(),
                    $message->getChannel()->getName(),
                    $message->getAuthorLabel(),
                ]),
                'path' => $this->urlGenerator->generate('workspace_space_content', [
                    'id' => $message->getSpace()->getId(),
                    'view' => 'chat',
                    'channel' => $message->getChannel()->getId(),
                    'message' => $message->getId(),
                ]),
            ],
            $this->spaceChatMessageRepository->search($query, $spaceIds, $reader, self::LIMIT),
        );
    }

    /** A space opened on one of its tabs, as the space reads `?view=`. */
    private function spaceTab(CustomerSpaceInterface $space, string $view): string
    {
        return $this->urlGenerator->generate('workspace_space_content', ['id' => $space->getId(), 'view' => $view]);
    }

    /** The message around the match, on one line: the palette row has no room for paragraphs. */
    private function snippet(string $body, string $query): string
    {
        $flat = preg_replace('/\s+/u', ' ', $body) ?? $body;

        return $this->searchSnippetBuilder->build($flat, mb_trim($query), self::SNIPPET_RADIUS);
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
                // Their page, where the whole sheet is read and edited.
                'path' => $this->urlGenerator->generate('suite_studio_customers_show', ['id' => $customer->getId()]),
            ],
            $this->customerRepository->searchByNameOrNumber($query, self::LIMIT),
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
                'title' => $contract->getReference() ?? $this->translator->trans('suite.studio.contracts.draft_title'),
                'subtitle' => $this->join([
                    $contract->getCustomer()->getLegalName(),
                    $contract->getBodyVersion()?->getTemplate()->getName(),
                    $this->translator->trans($contract->getStatus()->getLabel()),
                ]),
                'path' => $this->urlGenerator->generate('suite_studio_contracts_show', ['id' => $contract->getId()]),
            ],
            $this->contractRepository->search($query, self::LIMIT),
        );
    }

    /** @return list<array<string, mixed>> */
    private function templateRows(string $query): array
    {
        return array_map(
            function (ContractTemplateInterface $template): array {
                // The studio's own word for it, not a translation key.
                $category = $template->getCategory()?->getName();

                return [
                    'id' => $template->getId(),
                    'title' => $template->getName(),
                    'subtitle' => $this->join([
                        $this->translator->trans($template->getKind()->getLabel()),
                        $category,
                        $template->isArchived() ? $this->translator->trans('suite.studio.contract_templates.state_archived') : null,
                    ]),
                    'path' => $this->templatePath($template),
                ];
            },
            $this->templateRepository->searchByName($query, self::LIMIT),
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
            return $this->urlGenerator->generate('suite_studio_contract_templates', ['search' => $template->getName()]);
        }

        return $this->urlGenerator->generate('suite_studio_contract_templates_editor', [
            'id' => $template->getId(),
            'versionId' => $version->getId(),
        ]);
    }

    /**
     * The deliverables the reader may open, whichever side they live on.
     *
     * Candidates by title, then each one through {@see DeliverableAccess::canRead()}:
     * a personal deliverable of a colleague, or one in a space the reader is not
     * on, is exactly what a title in a search result must not reveal. Asked for
     * more than the section shows, so filtering still leaves a full list.
     *
     * A presentation is also found by the words on its slides, after the
     * titles: what was searched for is more often a title, and a slide that
     * mentions it is the second-best answer.
     *
     * @return list<array<string, mixed>>
     */
    private function deliverableRows(string $query): array
    {
        $rows = [];
        $seen = [];
        $candidates = [
            ...$this->deliverableRepository->searchByTitle($query, self::LIMIT * 5),
            ...$this->deliverableRepository->searchBySlideText($query, self::LIMIT * 5),
        ];

        foreach ($candidates as $deliverable) {
            if (isset($seen[$deliverable->getId()])) {
                continue;
            }

            $seen[$deliverable->getId()] = true;

            if (!$this->isSearchable($deliverable)) {
                continue;
            }

            $space = $deliverable->getSpace();
            $rows[] = [
                'id' => $deliverable->getId(),
                'title' => $deliverable->getTitle(),
                'subtitle' => $this->join([
                    $space instanceof CustomerSpaceInterface ? $space->getName() : $this->translator->trans('suite.studio.deliverables.scope.'.$deliverable->getScope()->value),
                    $deliverable->getSummary(),
                ]),
                'path' => $space instanceof CustomerSpaceInterface
                    ? $this->urlGenerator->generate('workspace_space_deliverables_edit', ['id' => $space->getId(), 'deliverableId' => $deliverable->getId()])
                    : $this->urlGenerator->generate('suite_studio_deliverables_edit', ['id' => $deliverable->getId()]),
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
