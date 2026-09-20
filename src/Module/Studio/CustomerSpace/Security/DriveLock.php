<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\Security;

use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

use function bin2hex;
use function mb_strlen;
use function random_bytes;
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
 *
 * **Et refermable pour tout le monde d'un coup.** Une session ne retient pas
 * « ouvert » mais la génération qu'elle a ouverte ; l'espace en porte une, et
 * en tirer une nouvelle périme toutes les sessions à la fois. C'est ce qui
 * permet de tout refermer sans changer le mot de passe, et ce qui fait qu'en
 * changer un referme vraiment.
 */
final readonly class DriveLock
{
    private const string SESSION_PREFIX = 'studio.drive.unlocked.';

    /**
     * Ce qu'un mot de passe doit peser au minimum.
     *
     * **Ici et non dans le contrôleur qui le lisait.** La serrure est ce qui
     * sait ce qu'est un mot de passe d'espace ; l'écran ne fait que le
     * demander. Le jour où un second appelant en pose un, il n'aura pas à
     * retrouver le chiffre pour être d'accord.
     *
     * Huit, le même plancher que partout ailleurs : plus sévère pour une
     * porte intérieure que pour la porte d'entrée ne se justifierait pas.
     */
    public const int MIN_LENGTH = 8;

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
        $generation = $space->getDriveLockGeneration();

        if (null === $generation) {
            return false;
        }

        return $generation === $this->session()?->get($this->key($space));
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

        // Une génération au besoin : les espaces déjà fermés avant que
        // celle-ci existe n'en portent aucune, et sans cet amorçage la bonne
        // saisie n'ouvrirait jamais rien. L'appelant enregistre.
        if (null === $space->getDriveLockGeneration()) {
            $space->setDriveLockGeneration(bin2hex(random_bytes(8)));
        }

        $this->session()?->set($this->key($space), $space->getDriveLockGeneration());

        return true;
    }

    /** Referme dans cette session, sans toucher au mot de passe. */
    public function lock(CustomerSpaceInterface $space): void
    {
        $this->session()?->remove($this->key($space));
    }

    /** Assez long pour être posé. */
    public function isAcceptable(string $password): bool
    {
        return mb_strlen($password) >= self::MIN_LENGTH;
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
        $this->revoke($space);
    }

    /**
     * Referme partout, sans toucher au mot de passe.
     *
     * **Le geste qu'on veut avoir sous la main sans rien avoir à changer.**
     * Un écran resté ouvert sur un poste qu'on ne contrôle plus, quelqu'un à
     * qui on a montré l'onglet, un doute : il faut pouvoir redemander la
     * saisie à tout le monde sans imposer un nouveau mot de passe à ceux qui
     * le connaissent déjà.
     *
     * Celui qui appuie est refermé comme les autres : ne pas l'être ferait
     * douter que le bouton ait agi, et il connaît le mot de passe.
     */
    public function revoke(CustomerSpaceInterface $space): void
    {
        $space->setDriveLockGeneration(bin2hex(random_bytes(8)));
        $this->lock($space);
    }

    /** Rouvre l'onglet pour de bon. L'appelant a déjà vérifié le mot de passe. */
    public function clear(CustomerSpaceInterface $space): void
    {
        $space->setDrivePassword(null);
        $this->revoke($space);
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

    /**
     * La session, s'il y en a une.
     *
     * **Nulle en console**, et c'est ce qui permet à la commande de secours
     * d'appeler `clear()` comme l'écran plutôt que de rouvrir la porte à sa
     * façon. Deux manières d'effacer un mot de passe finissent toujours par
     * diverger, et c'est la moins souvent exécutée qui a tort.
     */
    private function session(): ?SessionInterface
    {
        $request = $this->requests->getCurrentRequest();

        return $request instanceof Request && $request->hasSession() ? $request->getSession() : null;
    }

    private function key(CustomerSpaceInterface $space): string
    {
        return sprintf('%s%d', self::SESSION_PREFIX, (int) $space->getId());
    }
}
