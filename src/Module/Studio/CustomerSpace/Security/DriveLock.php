<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\Security;

use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

use function sprintf;

/**
 * La serrure de l'onglet Drive d'un espace.
 *
 * **Elle vaut pour tout le monde, développeur compris**, et c'est la seule
 * façon qu'elle veuille dire quelque chose. Un mot de passe que le rôle le
 * plus élevé contourne ne protège de personne : il décore un écran.
 *
 * **Le désactiver demande de le saisir.** Sans cette règle, quiconque atteint
 * les réglages l'enlève en un clic, et la protection ne vaut plus que contre
 * un écran laissé ouvert. Avec elle, l'oubli devient possible : le secours est
 * `aurora:space:drive-password:clear`, sur le serveur. L'autorité qui débloque
 * est donc l'accès à la machine, qui est un vrai niveau d'autorité et pas une
 * case à cocher.
 *
 * **Déverrouillé pour une session, pas pour toujours.** Se fermer avec le
 * navigateur est ce qu'on attend d'une serrure ; un cookie qui durerait des
 * semaines rendrait la saisie initiale décorative. Et par espace : en ouvrir
 * un n'ouvre pas les autres.
 */
final readonly class DriveLock
{
    private const string SESSION_PREFIX = 'studio.drive.unlocked.';

    public function __construct(
        private RequestStack $requests,
        private PasswordHasherFactoryInterface $hashers,
    ) {}

    public function isLocked(CustomerSpaceInterface $space): bool
    {
        return $space->isDriveLocked();
    }

    /** Fermé, et pas encore ouvert dans cette session. */
    public function isClosedFor(CustomerSpaceInterface $space): bool
    {
        return $this->isLocked($space) && !$this->isUnlocked($space);
    }

    public function isUnlocked(CustomerSpaceInterface $space): bool
    {
        $session = $this->requests->getSession();

        return true === $session->get($this->key($space), false);
    }

    /**
     * Ouvre pour cette session, si le mot de passe est le bon.
     *
     * Rend faux sans rien dire de plus : « mauvais mot de passe » est la seule
     * réponse utile, et distinguer les cas n'apprendrait qu'à celui qui
     * essaie.
     */
    public function unlock(CustomerSpaceInterface $space, string $password): bool
    {
        if (!$this->matches($space, $password)) {
            return false;
        }

        $this->requests->getSession()->set($this->key($space), true);

        return true;
    }

    /** Referme dans cette session, sans toucher au mot de passe. */
    public function lock(CustomerSpaceInterface $space): void
    {
        $this->requests->getSession()->remove($this->key($space));
    }

    public function matches(CustomerSpaceInterface $space, string $password): bool
    {
        $stored = $space->getDrivePassword();

        if (null === $stored || '' === $stored || '' === $password) {
            return false;
        }

        return $this->hasher()->verify($stored, $password);
    }

    /**
     * Pose ou remplace le mot de passe, et referme aussitôt.
     *
     * **Y compris pour celui qui vient de le choisir**, et c'est un
     * revirement assumé. Garder sa session ouverte était plus confortable :
     * il venait de prouver qu'il connaissait le mot de passe, lui redemander
     * semblait bureaucratique. Sauf qu'on ferme une porte pour la voir se
     * fermer. Une serrure qu'on pose et qui ne change rien à l'écran ressemble
     * à un réglage qui n'a pas pris, et le premier réflexe est de douter du
     * produit.
     *
     * La ressaisie coûte cinq secondes et prouve trois choses d'un coup : que
     * le mot de passe est bien enregistré, qu'il est celui qu'on croit, et
     * qu'il ferme quelque chose.
     */
    public function set(CustomerSpaceInterface $space, string $password): void
    {
        $space->setDrivePassword($this->hasher()->hash($password));
        $this->lock($space);
    }

    /** Rouvre l'onglet pour de bon. L'appelant a déjà vérifié le mot de passe. */
    public function clear(CustomerSpaceInterface $space): void
    {
        $space->setDrivePassword(null);
        $this->lock($space);
    }

    /**
     * Le hacheur que le projet configure, et non un choix local.
     *
     * Un mot de passe d'espace n'a pas de raison d'être plus faible qu'un mot
     * de passe de compte : en nommer un ici le ferait diverger le jour où le
     * projet change le sien.
     */
    private function hasher(): PasswordHasherInterface
    {
        return $this->hashers->getPasswordHasher(PasswordAuthenticatedUserInterface::class);
    }

    private function key(CustomerSpaceInterface $space): string
    {
        return sprintf('%s%d', self::SESSION_PREFIX, (int) $space->getId());
    }
}
