<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Manager;

use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Enum\UserStatusEnum;
use Aurora\Module\Platform\User\Manager\UserManagerInterface;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Créer un compte pour quelqu'un qui arrive plus tard.
 *
 * Ce qui se vérifie ici est une machine à états, pas un champ : un compte
 * pré-provisionné et un compte désactivé après avoir servi portent le **même**
 * statut `Disabled`, et c'est `invitedAt` qui les distingue. L'ouvrir ne fait
 * donc pas la même chose dans les deux cas - dans le premier il faut envoyer
 * l'invitation, dans le second il ne faut surtout pas.
 *
 * Sans ces tests, la confusion serait silencieuse : le compte passerait `Active`
 * avec un mot de passe aléatoire que personne ne connaît, donc utilisable pour
 * personne tout en paraissant ouvert dans la liste.
 */
final class UserManagerPreProvisionedInviteTest extends IntegrationTestCase
{
    private UserManagerInterface $userManager;

    private EntityManagerInterface $entityManager;

    /** @var list<int> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        static::bootKernel();
        $this->userManager = static::getContainer()->get(UserManagerInterface::class);
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            $user = $this->entityManager->find(User::class, $id);
            if (null !== $user) {
                $this->entityManager->remove($user);
            }
        }
        $this->entityManager->flush();
        $this->created = [];

        parent::tearDown();
    }

    /** Le cas ordinaire, pour avoir le point de comparaison. */
    public function testAnOrdinaryInviteIsInvitedAndStamped(): void
    {
        $user = $this->invite('ordinaire@exemple.com');

        self::assertSame(UserStatusEnum::Invited, $user->getStatus());
        self::assertInstanceOf(DateTimeImmutable::class, $user->getInvitedAt());
        self::assertNotNull($user->getInvitationSelector());
    }

    /**
     * Le cœur : rien n'est émis, rien ne part, et `invitedAt` reste nul.
     *
     * Ce nul est la seule chose qui dira plus tard « personne n'a jamais été
     * contacté ». Un jeton émis ici expirerait en 48 heures pour rien.
     */
    public function testAPreProvisionedAccountEmitsNothing(): void
    {
        $user = $this->invite('plus-tard@exemple.com', disabled: true);

        self::assertSame(UserStatusEnum::Disabled, $user->getStatus());
        self::assertNull($user->getInvitedAt());
        self::assertNull($user->getInvitationSelector());
        self::assertNull($user->getInvitationHashedToken());
    }

    /**
     * L'ouvrir envoie l'invitation et le passe `Invited`, pas `Active`.
     *
     * `Active` serait le bug : son mot de passe est un aléa que personne ne
     * connaît, donc le compte paraîtrait ouvert sans que quiconque puisse s'en
     * servir.
     */
    public function testOpeningAPreProvisionedAccountSendsItsInvitation(): void
    {
        $user = $this->invite('ouverture@exemple.com', disabled: true);

        $opened = $this->userManager->toggleDisabled($user);

        self::assertTrue($opened);
        self::assertSame(UserStatusEnum::Invited, $user->getStatus());
        self::assertInstanceOf(DateTimeImmutable::class, $user->getInvitedAt());
        self::assertNotNull($user->getInvitationSelector());
    }

    /**
     * Un compte qui a déjà servi retrouve son accès, et rien n'est renvoyé.
     *
     * C'est l'autre moitié de la distinction : ici `invitedAt` est renseigné, donc
     * la bascule doit rendre l'accès sans réémettre de jeton - sinon désactiver
     * puis réactiver quelqu'un lui enverrait un mail d'invitation incompréhensible
     * et invaliderait le mot de passe qu'il utilise.
     */
    public function testReopeningAnAccountThatHasServedDoesNotReInvite(): void
    {
        $user = $this->invite('deja-servi@exemple.com');
        $this->userManager->consumeInvitation($user, 'un-mot-de-passe-solide');

        self::assertSame(UserStatusEnum::Active, $user->getStatus());
        $invitedAt = $user->getInvitedAt();

        self::assertFalse($this->userManager->toggleDisabled($user));
        self::assertSame(UserStatusEnum::Disabled, $user->getStatus());

        self::assertTrue($this->userManager->toggleDisabled($user));
        self::assertSame(UserStatusEnum::Active, $user->getStatus());
        // Aucun jeton neuf : le mot de passe en place reste le bon.
        self::assertNull($user->getInvitationSelector());
        self::assertEquals($invitedAt, $user->getInvitedAt());
    }

    /** Fermer un compte pré-provisionné à peine ouvert le renvoie à `Disabled`. */
    public function testItCanBeClosedAgainAfterBeingOpened(): void
    {
        $user = $this->invite('refermer@exemple.com', disabled: true);
        $this->userManager->toggleDisabled($user);

        self::assertFalse($this->userManager->toggleDisabled($user));
        self::assertSame(UserStatusEnum::Disabled, $user->getStatus());
        // Il a été contacté : le rouvrir ne le réinvitera plus.
        self::assertInstanceOf(DateTimeImmutable::class, $user->getInvitedAt());
    }

    private function invite(string $email, bool $disabled = false): User
    {
        $user = $this->userManager->invite('Comptable', $email, UserRoleEnum::User->value, null, $disabled);
        $this->created[] = (int) $user->getId();

        return $user;
    }
}
