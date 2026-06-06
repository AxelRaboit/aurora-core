<?php

declare(strict_types=1);

namespace Aurora\Module\PersonalFinance\DataFixtures;

use Aurora\Core\DataFixtures\CoreDemoFixtures;
use Aurora\Module\PersonalFinance\Budget\Entity\PersonalFinanceBudget;
use Aurora\Module\PersonalFinance\Budget\Entity\PersonalFinanceBudgetInterface;
use Aurora\Module\PersonalFinance\Budget\Entity\PersonalFinanceBudgetItem;
use Aurora\Module\PersonalFinance\Budget\Entity\PersonalFinanceBudgetItemInterface;
use Aurora\Module\PersonalFinance\Budget\Entity\PersonalFinanceBudgetPreset;
use Aurora\Module\PersonalFinance\Budget\Entity\PersonalFinanceBudgetPresetInterface;
use Aurora\Module\PersonalFinance\Budget\Entity\PersonalFinanceBudgetPresetItem;
use Aurora\Module\PersonalFinance\Budget\Entity\PersonalFinanceBudgetPresetItemInterface;
use Aurora\Module\PersonalFinance\Budget\Enum\PersonalFinanceBudgetSectionEnum;
use Aurora\Module\PersonalFinance\Categorization\Entity\PersonalFinanceCategorizationRule;
use Aurora\Module\PersonalFinance\Categorization\Entity\PersonalFinanceCategorizationRuleInterface;
use Aurora\Module\PersonalFinance\Categorization\Support\PatternNormalizer;
use Aurora\Module\PersonalFinance\Category\Entity\PersonalFinanceCategory;
use Aurora\Module\PersonalFinance\Category\Entity\PersonalFinanceCategoryInterface;
use Aurora\Module\PersonalFinance\Goal\Entity\PersonalFinanceGoal;
use Aurora\Module\PersonalFinance\Goal\Entity\PersonalFinanceGoalInterface;
use Aurora\Module\PersonalFinance\Goal\Enum\PersonalFinanceGoalTrackingModeEnum;
use Aurora\Module\PersonalFinance\Goal\Manager\PersonalFinanceGoalManagerInterface;
use Aurora\Module\PersonalFinance\Recurring\Entity\PersonalFinanceRecurringTransaction;
use Aurora\Module\PersonalFinance\Recurring\Entity\PersonalFinanceRecurringTransactionInterface;
use Aurora\Module\PersonalFinance\Recurring\Entity\PersonalFinanceScheduledTransaction;
use Aurora\Module\PersonalFinance\Recurring\Entity\PersonalFinanceScheduledTransactionInterface;
use Aurora\Module\PersonalFinance\Transaction\Entity\PersonalFinanceTransaction;
use Aurora\Module\PersonalFinance\Transaction\Entity\PersonalFinanceTransactionInterface;
use Aurora\Module\PersonalFinance\Transaction\Enum\PersonalFinanceTransactionTypeEnum;
use Aurora\Module\PersonalFinance\Wallet\Entity\PersonalFinanceWallet;
use Aurora\Module\PersonalFinance\Wallet\Entity\PersonalFinanceWalletInterface;
use Aurora\Module\PersonalFinance\Wallet\Entity\PersonalFinanceWalletMember;
use Aurora\Module\PersonalFinance\Wallet\Entity\PersonalFinanceWalletMemberInterface;
use Aurora\Module\PersonalFinance\Wallet\Enum\PersonalFinanceWalletModeEnum;
use Aurora\Module\PersonalFinance\Wallet\Enum\PersonalFinanceWalletRoleEnum;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\Uid\Uuid;

/**
 * Demo personal-finance data for the first demo user: wallets, categories,
 * transactions, budget, goals and recurring rules. Dev/test only.
 */
class PersonalFinanceDemoFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    public function __construct(
        private readonly ?PersonalFinanceGoalManagerInterface $goalManager = null,
    ) {}

    public static function getGroups(): array
    {
        return ['demo'];
    }

    public function getDependencies(): array
    {
        return [CoreDemoFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        assert($manager instanceof EntityManagerInterface);

        $users = [];
        for ($i = 0; $i < CoreDemoFixtures::USER_COUNT; ++$i) {
            $users[] = $this->getReference(CoreDemoFixtures::userRef($i), User::class);
        }

        $this->createPersonalFinance($manager, $users);

        $manager->flush();
    }

    private function createPersonalFinance(EntityManagerInterface $em, array $users): void
    {
        if ([] === $users) {
            return;
        }

        /** @var class-string<PersonalFinanceWallet> $walletClass */
        $walletClass = $em->getClassMetadata(PersonalFinanceWalletInterface::class)->getName();
        /** @var class-string<PersonalFinanceWalletMember> $memberClass */
        $memberClass = $em->getClassMetadata(PersonalFinanceWalletMemberInterface::class)->getName();
        /** @var class-string<PersonalFinanceCategory> $categoryClass */
        $categoryClass = $em->getClassMetadata(PersonalFinanceCategoryInterface::class)->getName();
        /** @var class-string<PersonalFinanceTransaction> $txClass */
        $txClass = $em->getClassMetadata(PersonalFinanceTransactionInterface::class)->getName();
        /** @var class-string<PersonalFinanceBudget> $budgetClass */
        $budgetClass = $em->getClassMetadata(PersonalFinanceBudgetInterface::class)->getName();
        /** @var class-string<PersonalFinanceBudgetItem> $budgetItemClass */
        $budgetItemClass = $em->getClassMetadata(PersonalFinanceBudgetItemInterface::class)->getName();
        /** @var class-string<PersonalFinanceBudgetPreset> $presetClass */
        $presetClass = $em->getClassMetadata(PersonalFinanceBudgetPresetInterface::class)->getName();
        /** @var class-string<PersonalFinanceBudgetPresetItem> $presetItemClass */
        $presetItemClass = $em->getClassMetadata(PersonalFinanceBudgetPresetItemInterface::class)->getName();
        /** @var class-string<PersonalFinanceGoal> $goalClass */
        $goalClass = $em->getClassMetadata(PersonalFinanceGoalInterface::class)->getName();
        /** @var class-string<PersonalFinanceRecurringTransaction> $recClass */
        $recClass = $em->getClassMetadata(PersonalFinanceRecurringTransactionInterface::class)->getName();
        /** @var class-string<PersonalFinanceScheduledTransaction> $schedClass */
        $schedClass = $em->getClassMetadata(PersonalFinanceScheduledTransactionInterface::class)->getName();
        /** @var class-string<PersonalFinanceCategorizationRule> $ruleClass */
        $ruleClass = $em->getClassMetadata(PersonalFinanceCategorizationRuleInterface::class)->getName();

        // Prefer the backend dev@aurora.app account (created by AppFixtures)
        // so demoers land on the seeded data right after `make demo`.
        $owner = $em->getRepository(User::class)->findOneBy([
            'email' => 'dev@aurora.app',
            'type' => UserTypeEnum::Backend,
        ]) ?? $users[0];
        $today = new DateTimeImmutable('today');

        // ── Wallets (+ Owner membership for the Voter to grant access) ────────
        $walletDefs = [
            ['name' => 'Compte courant', 'mode' => PersonalFinanceWalletModeEnum::Budget, 'startBalance' => '2500.00', 'pinned' => true,  'position' => 0],
            ['name' => 'Livret A',       'mode' => PersonalFinanceWalletModeEnum::Simple, 'startBalance' => '5000.00', 'pinned' => true,  'position' => 1],
            ['name' => 'Cash',           'mode' => PersonalFinanceWalletModeEnum::Simple, 'startBalance' => '100.00',  'pinned' => false, 'position' => 2],
        ];

        $wallets = [];
        foreach ($walletDefs as $def) {
            $wallet = new $walletClass();
            $wallet->setOwner($owner)
                   ->setName($def['name'])
                   ->setMode($def['mode'])
                   ->setStartBalance($def['startBalance'])
                   ->setShowOnDashboard($def['pinned'])
                   ->setPosition($def['position']);
            $em->persist($wallet);

            $member = new $memberClass();
            $member->setWallet($wallet)->setUser($owner)->setRole(PersonalFinanceWalletRoleEnum::Owner);
            $em->persist($member);

            $wallets[$def['name']] = $wallet;
        }

        // ── Categories (per wallet, user-created only — system categories
        //     are created lazily by TransferService/BalanceAdjustmentService) ─
        $categoryDefs = [
            'Compte courant' => ['Salaire', 'Freelance', 'Cashback', 'Loyer', 'Courses', 'Restaurant', 'Transport', 'Loisirs', 'Santé', 'Abonnements', 'Vêtements'],
            'Livret A' => ['Épargne'],
            'Cash' => ['Cash divers', 'Pourboires'],
        ];

        /** @var array<string, array<string, PersonalFinanceCategory>> $categories nested by [walletName][categoryName] */
        $categories = [];
        foreach ($categoryDefs as $walletName => $names) {
            $categories[$walletName] = [];
            foreach ($names as $name) {
                $cat = new $categoryClass();
                $cat->setUser($owner)->setWallet($wallets[$walletName])->setName($name);
                $em->persist($cat);
                $categories[$walletName][$name] = $cat;
            }
        }

        // ── Transactions over the last 3 months ───────────────────────────────
        $cc = $wallets['Compte courant'];
        $ccCats = $categories['Compte courant'];

        $txDefs = [];

        // Monthly salary (3 months back through today, 1st of month)
        foreach ([90, 60, 30, 0] as $daysAgo) {
            $date = $today->modify(sprintf('-%s days', $daysAgo))->modify('first day of this month');
            $txDefs[] = ['wallet' => $cc, 'cat' => $ccCats['Salaire'], 'type' => PersonalFinanceTransactionTypeEnum::Income, 'amount' => '2800.00', 'date' => $date, 'desc' => 'Salaire Aurora Tech'];
        }

        // Freelance income — irregular gigs, each month picks up a different
        // mission so the cumulative goal looks realistic over the 4-month
        // window seeded above.
        $freelanceTxs = [
            ['daysAgo' => 80, 'amount' => '1500.00', 'desc' => 'Mission freelance Acme Corp'],
            ['daysAgo' => 50, 'amount' => '800.00',  'desc' => 'Audit technique Wayne Enterprises'],
            ['daysAgo' => 25, 'amount' => '1200.00', 'desc' => 'Refonte site Initech'],
            ['daysAgo' => 5,  'amount' => '600.00',  'desc' => 'Conseil archi Stark Industries'],
        ];
        foreach ($freelanceTxs as $def) {
            $txDefs[] = [
                'wallet' => $cc,
                'cat' => $ccCats['Freelance'],
                'type' => PersonalFinanceTransactionTypeEnum::Income,
                'amount' => $def['amount'],
                'date' => $today->modify(sprintf('-%s days', $def['daysAgo'])),
                'desc' => $def['desc'],
            ];
        }

        // Cashback — small monthly income drip from card rewards / referral codes.
        foreach ([85, 55, 25] as $daysAgo) {
            $txDefs[] = [
                'wallet' => $cc,
                'cat' => $ccCats['Cashback'],
                'type' => PersonalFinanceTransactionTypeEnum::Income,
                'amount' => '12.50',
                'date' => $today->modify(sprintf('-%s days', $daysAgo)),
                'desc' => 'Cashback carte bleue',
            ];
        }

        $txDefs[] = [
            'wallet' => $cc,
            'cat' => $ccCats['Cashback'],
            'type' => PersonalFinanceTransactionTypeEnum::Income,
            'amount' => '25.00',
            'date' => $today->modify('-12 days'),
            'desc' => 'Parrainage banque en ligne',
        ];

        // Rent (5th of each month)
        foreach ([90, 60, 30, 0] as $daysAgo) {
            $date = $today->modify(sprintf('-%s days', $daysAgo))->modify('first day of this month')->modify('+4 days');
            if ($date <= $today) {
                $txDefs[] = ['wallet' => $cc, 'cat' => $ccCats['Loyer'], 'type' => PersonalFinanceTransactionTypeEnum::Expense, 'amount' => '850.00', 'date' => $date, 'desc' => 'Loyer appartement'];
            }
        }

        // Subscriptions (15th of each month)
        foreach ([85, 55, 25] as $daysAgo) {
            $date = $today->modify(sprintf('-%s days', $daysAgo));
            $txDefs[] = ['wallet' => $cc, 'cat' => $ccCats['Abonnements'], 'type' => PersonalFinanceTransactionTypeEnum::Expense, 'amount' => '13.99', 'date' => $date, 'desc' => 'Netflix'];
            $txDefs[] = ['wallet' => $cc, 'cat' => $ccCats['Abonnements'], 'type' => PersonalFinanceTransactionTypeEnum::Expense, 'amount' => '9.99', 'date' => $date->modify('+3 days'), 'desc' => 'Spotify Premium'];
        }

        // Variable spending — 6 groceries, 5 restaurants, 4 transport, 3 leisure across 3 months
        $variablePattern = [
            ['cat' => 'Courses',    'samples' => [['65.40', 'Carrefour'], ['38.20', 'Lidl'], ['72.10', 'Carrefour'], ['41.90', 'Biocoop'], ['58.30', 'Carrefour'], ['29.80', 'Lidl']]],
            ['cat' => 'Restaurant', 'samples' => [['28.50', 'Pizzeria Mario'], ['45.00', 'Sushi Yama'], ['18.40', 'McDonalds'], ['52.00', 'Le Bistrot'], ['22.00', 'Tacos King']]],
            ['cat' => 'Transport',  'samples' => [['62.00', 'Plein essence'], ['45.30', 'Plein essence'], ['58.20', 'Plein essence'], ['12.00', 'Parking centre-ville']]],
            ['cat' => 'Loisirs',    'samples' => [['25.00', 'Cinéma'], ['80.00', 'Concert'], ['45.00', 'Salle de sport']]],
            ['cat' => 'Santé',      'samples' => [['28.00', 'Pharmacie'], ['55.00', 'Consultation médecin']]],
            ['cat' => 'Vêtements',  'samples' => [['89.00', 'Uniqlo'], ['45.50', 'Decathlon']]],
        ];

        foreach ($variablePattern as $group) {
            $cat = $ccCats[$group['cat']];
            foreach ($group['samples'] as $idx => [$amount, $desc]) {
                // Spread across the last 90 days deterministically (no random — fixtures are reproducible).
                // The current sample sets all fit in 0..85 days with the 14-day stride; if a contributor
                // bumps them past 7 samples, clamp at the most recent day rather than pushing into the future.
                $offset = max(0, 85 - $idx * 14);

                $txDefs[] = ['wallet' => $cc, 'cat' => $cat, 'type' => PersonalFinanceTransactionTypeEnum::Expense, 'amount' => $amount, 'date' => $today->modify(sprintf('-%s days', $offset)), 'desc' => $desc];
            }
        }

        // High-volume Restaurant entries — fill the current month so the
        // Budgets drill-down ("Dépenses > Restaurant") demos the infinite
        // scroll right after `make demo`. Deterministic, spread across
        // the month.
        $restaurantSamples = [
            ['12.50', 'Café du coin'], ['18.00', 'McDonalds'], ['22.50', 'Burger King'], ['9.80', 'Boulangerie'],
            ['15.30', 'Sandwich shop'], ['28.50', 'Pizzeria Mario'], ['45.00', 'Sushi Yama'], ['16.20', 'Pizzeria'],
            ['8.50', 'Café'], ['32.00', 'Brasserie'], ['52.00', 'Le Bistrot'], ['11.00', 'Croissanterie'],
            ['19.50', 'Five Guys'], ['24.00', 'Subway'], ['38.50', "Le P'tit Resto"], ['14.00', 'KFC'],
            ['6.50', 'Café'], ['42.30', 'Pizzeria Roma'], ['27.80', 'Tacos King'], ['55.00', 'Restaurant gastronomique'],
        ];
        $monthStart = $today->modify('first day of this month');
        $monthEnd = $today->modify('first day of next month');
        $dayInMonth = (int) $monthEnd->modify('-1 day')->format('d');
        for ($i = 0; $i < 80; ++$i) {
            $sample = $restaurantSamples[$i % count($restaurantSamples)];
            $day = (($i * 7) % $dayInMonth) + 1; // spread deterministically
            $date = $monthStart->modify(sprintf('+%d days', $day - 1));
            if ($date >= $monthEnd) {
                continue;
            }

            $tx = new $txClass();
            $tx->setUser($owner)
               ->setWallet($cc)
               ->setCategory($ccCats['Restaurant'])
               ->setType(PersonalFinanceTransactionTypeEnum::Expense)
               ->setAmount($sample[0])
               ->setDate($date)
               ->setDescription($sample[1]);
            $em->persist($tx);
        }

        // A few cash transactions
        $cash = $wallets['Cash'];
        $txDefs[] = ['wallet' => $cash, 'cat' => $categories['Cash']['Cash divers'], 'type' => PersonalFinanceTransactionTypeEnum::Expense, 'amount' => '15.00', 'date' => $today->modify('-12 days'), 'desc' => 'Boulangerie'];
        $txDefs[] = ['wallet' => $cash, 'cat' => $categories['Cash']['Pourboires'], 'type' => PersonalFinanceTransactionTypeEnum::Expense, 'amount' => '5.00', 'date' => $today->modify('-8 days'), 'desc' => 'Pourboire serveur'];

        foreach ($txDefs as $def) {
            $tx = new $txClass();
            $tx->setUser($owner)
               ->setWallet($def['wallet'])
               ->setCategory($def['cat'])
               ->setType($def['type'])
               ->setAmount($def['amount'])
               ->setDate($def['date'])
               ->setDescription($def['desc']);
            $em->persist($tx);
        }

        // ── Transfer (Compte courant → Livret A, 500€) ───────────────────────
        $transferId = Uuid::v7()->toRfc4122();
        $transferDate = $today->modify('-15 days');

        $livret = $wallets['Livret A'];
        // System categories created on demand (mirrors TransferService::getOrCreateSystem)
        $expenseSystemCat = new $categoryClass();
        $expenseSystemCat->setUser($owner)->setWallet($cc)
                         ->setName(sprintf('Virement vers %s', $livret->getName()))
                         ->setIsSystem(true)
                         ->setSystemKey('transfer_expense_PLACEHOLDER');
        $em->persist($expenseSystemCat);

        $incomeSystemCat = new $categoryClass();
        $incomeSystemCat->setUser($owner)->setWallet($livret)
                        ->setName('Virement reçu')
                        ->setIsSystem(true)
                        ->setSystemKey('transfer_income');
        $em->persist($incomeSystemCat);

        $em->flush(); // need IDs to compute the transfer_expense_{toWalletId} key

        $expenseSystemCat->setSystemKey('transfer_expense_'.$livret->getId());

        $expenseLeg = new $txClass();
        $expenseLeg->setUser($owner)->setWallet($cc)->setCategory($expenseSystemCat)
                   ->setType(PersonalFinanceTransactionTypeEnum::Expense)
                   ->setAmount('500.00')->setDate($transferDate)
                   ->setDescription('Virement épargne')->setTransferId($transferId);
        $em->persist($expenseLeg);

        $incomeLeg = new $txClass();
        $incomeLeg->setUser($owner)->setWallet($livret)->setCategory($incomeSystemCat)
                  ->setType(PersonalFinanceTransactionTypeEnum::Income)
                  ->setAmount('500.00')->setDate($transferDate)
                  ->setDescription('Virement épargne')->setTransferId($transferId);
        $em->persist($incomeLeg);

        // ── Split: 1 grocery split into Courses + Vêtements ──────────────────
        $splitId = Uuid::v7()->toRfc4122();
        $splitDate = $today->modify('-5 days');

        $splitGroceries = new $txClass();
        $splitGroceries->setUser($owner)->setWallet($cc)->setCategory($ccCats['Courses'])
                       ->setType(PersonalFinanceTransactionTypeEnum::Expense)
                       ->setAmount('80.00')->setDate($splitDate)
                       ->setDescription('Carrefour — courses')->setSplitId($splitId);
        $em->persist($splitGroceries);

        $splitClothes = new $txClass();
        $splitClothes->setUser($owner)->setWallet($cc)->setCategory($ccCats['Vêtements'])
                     ->setType(PersonalFinanceTransactionTypeEnum::Expense)
                     ->setAmount('35.00')->setDate($splitDate)
                     ->setDescription('Carrefour — t-shirt')->setSplitId($splitId);
        $em->persist($splitClothes);

        // ── Budget — current month, populated for Compte courant ─────────────
        $budget = new $budgetClass();
        $budget->setUser($owner)->setWallet($cc)
               ->setMonth($today->modify('first day of this month'))
               ->setNotes('Budget mensuel');
        $em->persist($budget);

        $budgetItemDefs = [
            ['section' => PersonalFinanceBudgetSectionEnum::Income,   'label' => 'Salaire',     'planned' => '2800.00', 'cat' => 'Salaire',     'repeat' => true,  'pos' => 0],
            ['section' => PersonalFinanceBudgetSectionEnum::FixedCharges, 'label' => 'Loyer',       'planned' => '850.00',  'cat' => 'Loyer',       'repeat' => true,  'pos' => 0],
            ['section' => PersonalFinanceBudgetSectionEnum::FixedCharges, 'label' => 'Abonnements', 'planned' => '30.00',   'cat' => 'Abonnements', 'repeat' => true,  'pos' => 1],
            ['section' => PersonalFinanceBudgetSectionEnum::Expenses, 'label' => 'Courses',     'planned' => '400.00',  'cat' => 'Courses',     'repeat' => true,  'pos' => 0],
            ['section' => PersonalFinanceBudgetSectionEnum::Expenses, 'label' => 'Restaurant',  'planned' => '120.00',  'cat' => 'Restaurant',  'repeat' => true,  'pos' => 1],
            ['section' => PersonalFinanceBudgetSectionEnum::Expenses, 'label' => 'Transport',   'planned' => '150.00',  'cat' => 'Transport',   'repeat' => true,  'pos' => 2],
            ['section' => PersonalFinanceBudgetSectionEnum::Expenses, 'label' => 'Loisirs',     'planned' => '100.00',  'cat' => 'Loisirs',     'repeat' => true,  'pos' => 3],
            ['section' => PersonalFinanceBudgetSectionEnum::Savings,  'label' => 'Épargne',     'planned' => '500.00',  'cat' => null,          'repeat' => true,  'pos' => 0],
        ];

        foreach ($budgetItemDefs as $def) {
            $item = new $budgetItemClass();
            $item->setBudget($budget)
                 ->setSection($def['section'])
                 ->setLabel($def['label'])
                 ->setPlannedAmount($def['planned'])
                 ->setCategory(null !== $def['cat'] ? $ccCats[$def['cat']] : null)
                 ->setPosition($def['pos'])
                 ->setRepeatNextMonth($def['repeat']);
            $em->persist($item);
        }

        // ── Budget presets — 2 reusable monthly templates ────────────────────
        $presetDefs = [
            [
                'name' => 'Mois standard',
                'description' => 'Structure habituelle : salaire + loyer + courses + loisirs.',
                'items' => [
                    ['section' => PersonalFinanceBudgetSectionEnum::Income,       'label' => 'Salaire',     'planned' => '2800.00', 'cat' => 'Salaire'],
                    ['section' => PersonalFinanceBudgetSectionEnum::FixedCharges, 'label' => 'Loyer',       'planned' => '850.00',  'cat' => 'Loyer'],
                    ['section' => PersonalFinanceBudgetSectionEnum::FixedCharges, 'label' => 'Abonnements', 'planned' => '30.00',   'cat' => 'Abonnements'],
                    ['section' => PersonalFinanceBudgetSectionEnum::Expenses,     'label' => 'Courses',     'planned' => '400.00',  'cat' => 'Courses'],
                    ['section' => PersonalFinanceBudgetSectionEnum::Expenses,     'label' => 'Transport',   'planned' => '150.00',  'cat' => 'Transport'],
                    ['section' => PersonalFinanceBudgetSectionEnum::Savings,      'label' => 'Épargne',     'planned' => '500.00',  'cat' => null],
                ],
            ],
            [
                'name' => 'Mois vacances',
                'description' => 'Budget allégé sur les charges fixes, gonflé sur les loisirs + voyage.',
                'items' => [
                    ['section' => PersonalFinanceBudgetSectionEnum::Income,       'label' => 'Salaire',     'planned' => '2800.00', 'cat' => 'Salaire'],
                    ['section' => PersonalFinanceBudgetSectionEnum::FixedCharges, 'label' => 'Loyer',       'planned' => '850.00',  'cat' => 'Loyer'],
                    ['section' => PersonalFinanceBudgetSectionEnum::Expenses,     'label' => 'Loisirs',     'planned' => '400.00',  'cat' => 'Loisirs'],
                    ['section' => PersonalFinanceBudgetSectionEnum::Expenses,     'label' => 'Restaurant',  'planned' => '350.00',  'cat' => 'Restaurant'],
                    ['section' => PersonalFinanceBudgetSectionEnum::Expenses,     'label' => 'Transport',   'planned' => '300.00',  'cat' => 'Transport'],
                ],
            ],
        ];

        foreach ($presetDefs as $presetDef) {
            $preset = new $presetClass();
            $preset->setUser($owner)->setWallet($cc)->setName($presetDef['name'])->setDescription($presetDef['description']);
            $em->persist($preset);

            foreach ($presetDef['items'] as $position => $itemDef) {
                $presetItem = new $presetItemClass();
                $presetItem->setPreset($preset)
                           ->setSection($itemDef['section'])
                           ->setLabel($itemDef['label'])
                           ->setPlannedAmount($itemDef['planned'])
                           ->setCategory(null !== $itemDef['cat'] ? $ccCats[$itemDef['cat']] : null)
                           ->setPosition($position);
                $em->persist($presetItem);
            }
        }

        // ── Goals — manual + auto-tracked variants (each tracking mode covered) ──
        // The `saved` field on auto-tracked goals is just a seed value; the
        // GoalSyncSubscriber will recompute it the moment any tx touches the
        // linked category. Numbers below are picked so the demo cards look
        // populated even before the user makes their first edit.
        $goalDefs = [
            // Manual savings — no category, deposits via the "Déposer" button
            ['name' => 'Vacances d\'été',     'target' => '1500.00',  'saved' => '600.00',  'deadlineMonths' => 6,    'color' => '#f59e0b', 'wallet' => $livret, 'category' => null,                'mode' => PersonalFinanceGoalTrackingModeEnum::ExpenseOnly],
            ['name' => 'Apport immobilier',   'target' => '15000.00', 'saved' => '4500.00', 'deadlineMonths' => 18,   'color' => '#6366f1', 'wallet' => $livret, 'category' => null,                'mode' => PersonalFinanceGoalTrackingModeEnum::ExpenseOnly],
            ['name' => 'Permis de conduire',  'target' => '1200.00',  'saved' => '1200.00', 'deadlineMonths' => null, 'color' => '#10b981', 'wallet' => null,    'category' => null,                'mode' => PersonalFinanceGoalTrackingModeEnum::ExpenseOnly],

            // ExpenseOnly — "plafond" goals. Target sized for the 4-month seed
            // history so the demo cards show a realistic in-progress state
            // (~80% completed) rather than visually "blown".
            ['name' => 'Plafond Restaurant',  'target' => '2500.00',  'saved' => '0.00',    'deadlineMonths' => null, 'color' => '#ef4444', 'wallet' => $cc,     'category' => $ccCats['Restaurant'], 'mode' => PersonalFinanceGoalTrackingModeEnum::ExpenseOnly],
            ['name' => 'Plafond Loisirs',     'target' => '600.00',   'saved' => '0.00',    'deadlineMonths' => null, 'color' => '#a855f7', 'wallet' => $cc,     'category' => $ccCats['Loisirs'],    'mode' => PersonalFinanceGoalTrackingModeEnum::ExpenseOnly],

            // IncomeOnly — three flavours: payroll (steady), freelance
            // (lumpy), cashback (tiny drip). Each card lands on a distinct
            // visual progress band so the mode is easy to spot in the UI.
            ['name' => 'Salaire cumulé',       'target' => '33600.00', 'saved' => '0.00', 'deadlineMonths' => 12,   'color' => '#22c55e', 'wallet' => $cc, 'category' => $ccCats['Salaire'],   'mode' => PersonalFinanceGoalTrackingModeEnum::IncomeOnly],
            ['name' => 'Revenus freelance',    'target' => '6000.00',  'saved' => '0.00', 'deadlineMonths' => 12,   'color' => '#0ea5e9', 'wallet' => $cc, 'category' => $ccCats['Freelance'], 'mode' => PersonalFinanceGoalTrackingModeEnum::IncomeOnly],
            ['name' => 'Cashback annuel',      'target' => '200.00',   'saved' => '0.00', 'deadlineMonths' => 12,   'color' => '#eab308', 'wallet' => $cc, 'category' => $ccCats['Cashback'],  'mode' => PersonalFinanceGoalTrackingModeEnum::IncomeOnly],
        ];

        $autoTrackedGoals = [];
        foreach ($goalDefs as $def) {
            $goal = new $goalClass();
            $goal->setUser($owner)
                 ->setName($def['name'])
                 ->setTargetAmount($def['target'])
                 ->setSavedAmount($def['saved'])
                 ->setWallet($def['wallet'])
                 ->setCategory($def['category'])
                 ->setColor($def['color'])
                 ->setTrackingMode($def['mode']);
            if (null !== $def['deadlineMonths']) {
                $goal->setDeadline($today->modify('+'.$def['deadlineMonths'].' months'));
            }

            $em->persist($goal);

            if ($def['category'] instanceof PersonalFinanceCategory) {
                $autoTrackedGoals[] = $goal;
            }
        }

        $em->flush();

        // Trigger the sync subscriber once so the auto-tracked goals
        // pick up the seeded transaction history right away (the cards
        // would otherwise show "0.00" until the user touches a tx).
        $goalManager = $this->goalManager ?? null;
        if ($goalManager instanceof PersonalFinanceGoalManagerInterface) {
            foreach ($autoTrackedGoals as $goal) {
                $goalManager->recomputeSavedAmount($goal);
            }
        }

        // ── Recurring rules ───────────────────────────────────────────────────
        $recurringDefs = [
            ['cat' => 'Salaire',     'type' => PersonalFinanceTransactionTypeEnum::Income,  'amount' => '2800.00', 'day' => 1,  'desc' => 'Salaire Aurora Tech', 'active' => true],
            ['cat' => 'Loyer',       'type' => PersonalFinanceTransactionTypeEnum::Expense, 'amount' => '850.00',  'day' => 5,  'desc' => 'Loyer appartement',   'active' => true],
            ['cat' => 'Abonnements', 'type' => PersonalFinanceTransactionTypeEnum::Expense, 'amount' => '13.99',   'day' => 15, 'desc' => 'Netflix',             'active' => true],
        ];

        foreach ($recurringDefs as $def) {
            $rec = new $recClass();
            $rec->setUser($owner)->setWallet($cc)->setCategory($ccCats[$def['cat']])
                ->setType($def['type'])->setAmount($def['amount'])
                ->setDayOfMonth($def['day'])->setDescription($def['desc'])
                ->setActive($def['active'])
                ->setLastGeneratedAt($today->modify('first day of this month')); // already generated for current month
            $em->persist($rec);
        }

        // ── Scheduled (one-off future transactions) ──────────────────────────
        $scheduledDefs = [
            ['cat' => null,          'type' => PersonalFinanceTransactionTypeEnum::Income,  'amount' => '1200.00', 'monthsAhead' => 1, 'desc' => 'Prime annuelle'],
            ['cat' => 'Loyer',       'type' => PersonalFinanceTransactionTypeEnum::Expense, 'amount' => '850.00',  'monthsAhead' => 2, 'desc' => 'Taxe foncière'],
        ];

        foreach ($scheduledDefs as $def) {
            $sched = new $schedClass();
            $sched->setUser($owner)->setWallet($cc)
                  ->setCategory(null !== $def['cat'] ? $ccCats[$def['cat']] : null)
                  ->setType($def['type'])->setAmount($def['amount'])
                  ->setScheduledDate($today->modify('+'.$def['monthsAhead'].' months'))
                  ->setDescription($def['desc']);
            $em->persist($sched);
        }

        // ── Categorization rules — pre-learnt from the variable spending ────
        // Pre-populated so the auto-suggestion demo works the moment the
        // user types a known description; matches what the LearnSubscriber
        // would have produced if events had fired during seeding.
        $ruleDefs = [
            ['desc' => 'Carrefour',     'cat' => $ccCats['Courses'],     'hits' => 3],
            ['desc' => 'Lidl',          'cat' => $ccCats['Courses'],     'hits' => 2],
            ['desc' => 'Biocoop',       'cat' => $ccCats['Courses'],     'hits' => 1],
            ['desc' => 'Pizzeria Mario', 'cat' => $ccCats['Restaurant'],  'hits' => 1],
            ['desc' => 'Plein essence', 'cat' => $ccCats['Transport'],   'hits' => 3],
            ['desc' => 'Netflix',       'cat' => $ccCats['Abonnements'], 'hits' => 3],
            ['desc' => 'Spotify Premium', 'cat' => $ccCats['Abonnements'], 'hits' => 3],
        ];

        foreach ($ruleDefs as $def) {
            $pattern = PatternNormalizer::normalize($def['desc']);
            if (null === $pattern) {
                continue;
            }

            $rule = new $ruleClass();
            $rule->setUser($owner)->setPattern($pattern)
                 ->setCategory($def['cat'])->setHits($def['hits']);
            $em->persist($rule);
        }
    }
}
