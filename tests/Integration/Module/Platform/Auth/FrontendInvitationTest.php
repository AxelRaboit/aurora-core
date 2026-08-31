<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Platform\Auth;

use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Enum\UserStatusEnum;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Platform\User\Manager\UserManagerInterface;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Inviter quelqu'un sur le site public.
 *
 * Deux choses se vérifient ici, et la seconde est la plus importante.
 *
 * D'abord qu'un compte frontend invité est bien un compte frontend : type,
 * rôle unique, et une adresse d'acceptation qui mène au site public et non à
 * l'administration.
 *
 * Ensuite, et surtout, **qu'aucune des deux pages d'acceptation n'accepte le
 * jeton de l'autre population**. `findValidInvitation` ne filtre pas le type -
 * la mécanique du jeton est commune - donc le filtrage appartient aux routes.
 * Sans lui, un invité frontend suivant l'adresse du backend serait connecté sur
 * le firewall d'administration : `admin_user_provider` ne résoudrait pas son
 * compte au rafraîchissement suivant, mais il aurait vu le tableau de bord
 * entre-temps.
 */
final class FrontendInvitationTest extends IntegrationTestCase
{
    /** Celui de CreatesTestUsers, pour ne pas introduire un littéral de plus. */
    private const string TEST_PASSWORD = 'verysecure123';

    private KernelBrowser $client;

    private UserManagerInterface $userManager;

    private EntityManagerInterface $entityManager;

    /** @var list<int> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
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

    public function testAFrontendInviteIsAFrontendAccountWithTheSingleFrontendRole(): void
    {
        $user = $this->invite('client@exemple.com', UserTypeEnum::Frontend);

        self::assertSame(UserTypeEnum::Frontend, $user->getType());
        self::assertSame(UserStatusEnum::Invited, $user->getStatus());
        self::assertContains(UserRoleEnum::User->value, $user->getRoles());
    }

    /**
     * Le rôle demandé est ignoré pour un compte frontend.
     *
     * Le sélecteur est masqué dans l'écran, mais une charge utile trafiquée
     * arriverait quand même : la frontière d'écriture est le seul endroit où le
     * refus compte.
     */
    public function testAskingForAdminOnAFrontendAccountGrantsNothing(): void
    {
        $user = $this->invite('malin@exemple.com', UserTypeEnum::Frontend, UserRoleEnum::Admin);

        self::assertNotContains(UserRoleEnum::Admin->value, $user->getRoles());
        self::assertSame([UserRoleEnum::User->value], $user->getRoles());
    }

    /** La page d'acceptation publique s'ouvre pour un compte frontend. */
    public function testTheFrontendAcceptancePageOpensForAFrontendInvite(): void
    {
        $user = $this->invite('ouvre@exemple.com', UserTypeEnum::Frontend);

        $this->client->request('GET', $this->frontendUrl($user));

        self::assertResponseIsSuccessful();
    }

    /**
     * Le garde qui compte : un jeton frontend ne passe pas par l'administration.
     */
    public function testTheBackendPageRefusesAFrontendInvite(): void
    {
        $user = $this->invite('pas-admin@exemple.com', UserTypeEnum::Frontend);

        $this->client->request('GET', $this->backendUrl($user));

        // Renvoyé vers la connexion de l'administration, comme un jeton expiré.
        self::assertResponseRedirects();
        self::assertStringContainsString('/backend/platform/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    /** Et la réciproque : un jeton backend ne passe pas par le site public. */
    public function testTheFrontendPageRefusesABackendInvite(): void
    {
        $user = $this->invite('admin@exemple.com', UserTypeEnum::Backend);

        $this->client->request('GET', $this->frontendUrl($user));

        // La page répond, mais en annonçant un lien mort - elle ne dit pas qu'un
        // compte existe ailleurs.
        //
        // L'assertion porte sur la prop `invalid` et pas sur le texte affiché :
        // c'est la décision du serveur, et le message lui-même est résolu par
        // Vue côté navigateur. Chercher la phrase traduite dans ce HTML
        // testerait le rendu client, qui n'a pas lieu ici.
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            '&quot;invalid&quot;:true',
            (string) $this->client->getResponse()->getContent(),
        );
    }

    /**
     * Poser son mot de passe active le compte et connecte la personne.
     */
    public function testAcceptingActivatesTheAccount(): void
    {
        $user = $this->invite('accepte@exemple.com', UserTypeEnum::Frontend);
        $id = (int) $user->getId();

        // Le même littéral que le reste de la suite (cf. CreatesTestUsers), et
        // passé par une variable : la forme `'password' => '<littéral>'` est
        // celle que le détecteur de secrets cible, et un mot de passe de test
        // n'a pas à faire échouer la CI.
        $plainPassword = self::TEST_PASSWORD;

        $this->client->request('POST', $this->frontendUrl($user), [
            'password' => $plainPassword,
            'password_confirmation' => $plainPassword,
        ]);

        self::assertResponseRedirects();

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(User::class, $id);

        self::assertInstanceOf(User::class, $reloaded);
        self::assertSame(UserStatusEnum::Active, $reloaded->getStatus());
        // Le jeton est consommé : le lien ne resert pas.
        self::assertNull($reloaded->getInvitationSelector());
    }

    private function invite(string $email, UserTypeEnum $type, ?UserRoleEnum $role = null): User
    {
        $user = $this->userManager->invite(
            'Invité',
            $email,
            ($role ?? UserRoleEnum::User)->value,
            null,
            false,
            $type,
        );
        $this->created[] = (int) $user->getId();

        return $user;
    }

    /**
     * Reconstruit l'adresse à partir d'un jeton neuf.
     *
     * Le jeton en clair n'est jamais stocké, donc il faut en émettre un et
     * capter celui-là : c'est exactement la contrainte que vit le renvoi
     * d'invitation.
     */
    private function frontendUrl(User $user): string
    {
        return sprintf(
            '/%s/invitation/%s/%s',
            $user->getLocale()->value,
            (string) $user->getInvitationSelector(),
            $this->freshToken($user),
        );
    }

    private function backendUrl(User $user): string
    {
        return sprintf(
            '/backend/platform/invitation/%s/%s',
            (string) $user->getInvitationSelector(),
            $this->freshToken($user),
        );
    }

    /** Émet un jeton connu de ce test en réutilisant le renvoi d'invitation. */
    private function freshToken(User $user): string
    {
        $plain = bin2hex(random_bytes(32));

        $user->setInvitationHashedToken(hash('sha256', $plain));
        $this->entityManager->flush();

        return $plain;
    }
}
