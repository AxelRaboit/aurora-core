<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Dashboard;

use Aurora\Core\Dashboard\DashboardStatsProviderInterface;
use Aurora\Module\Studio\Contract\Repository\ContractRepository;
use Aurora\Module\Studio\CustomerSpace\Enum\CustomerSpaceStatusEnum;
use Aurora\Module\Studio\CustomerSpace\Repository\CustomerSpaceRepository;
use Aurora\Module\Studio\Deck\Repository\DeckRepository;
use Aurora\Module\Studio\SpaceContent\Enum\SpaceContentApprovalEnum;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentItemRepository;
use DateTimeImmutable;

use function array_sum;
use function sprintf;

/**
 * Les chiffres du Studio sur le tableau de bord.
 *
 * **Le module où le travail se passe n'y était pas.** L'écran d'arrivée
 * montrait l'éditorial, la médiathèque, le calendrier et les comptes ; les
 * espaces clients, les contrats et les présentations, c'est-à-dire ce qu'on
 * ouvre tous les jours, n'avaient aucun panneau. Chaque module apporte le sien
 * et celui-ci manquait, ce qui ne se voit pas : un tableau de bord incomplet
 * ressemble à un tableau de bord.
 *
 * **Deux nombres disent ce qui attend quelqu'un**, et c'est la question qu'on
 * se pose en arrivant : combien de contenus dorment chez un client, et combien
 * de contrats attendent une signature. Le reste situe : une carte en attente
 * sur trois n'est pas la même nouvelle qu'une sur quarante.
 */
final readonly class StudioStatsProvider implements DashboardStatsProviderInterface
{
    /** La fenêtre de « ce qui sort bientôt ». Une semaine, comme on planifie. */
    private const int UPCOMING_DAYS = 7;

    public function __construct(
        private CustomerSpaceRepository $spaceRepository,
        private SpaceContentItemRepository $itemRepository,
        private ContractRepository $contractRepository,
        private DeckRepository $deckRepository,
    ) {}

    public function getModuleKey(): string
    {
        return 'studio';
    }

    public function getStats(): array
    {
        $spaces = $this->spaceRepository->countGroupedByStatus();
        $approvals = $this->itemRepository->countGroupedByApproval();
        $contracts = $this->contractRepository->countGroupedByStatus();

        $now = new DateTimeImmutable();

        return [
            'studio' => [
                'spaces' => array_sum($spaces),
                'activeSpaces' => $spaces[CustomerSpaceStatusEnum::Active->value] ?? 0,
                'spacesByStatus' => $spaces,

                'items' => array_sum($approvals),
                // Ce qui dort chez un client : le seul nombre de cet écran sur
                // lequel on agit en relançant quelqu'un.
                'awaitingClient' => $approvals[SpaceContentApprovalEnum::Pending->value] ?? 0,
                'changesRequested' => $approvals[SpaceContentApprovalEnum::ChangesRequested->value] ?? 0,
                'itemsByApproval' => $approvals,

                // Les parutions de la semaine, au sens du calendrier : une
                // carte décochée porte une échéance interne et n'en est pas
                // une.
                'upcoming' => $this->itemRepository->countScheduledBetween(
                    $now,
                    $now->modify(sprintf('+%d days', self::UPCOMING_DAYS)),
                ),
                'upcomingDays' => self::UPCOMING_DAYS,

                'contracts' => array_sum($contracts),
                'contractsByStatus' => $contracts,

                'decks' => $this->deckRepository->count([]),
            ],
        ];
    }
}
