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
 * The lock on a space's Drive tab.
 *
 * **It applies to everyone, developer included**, and that is the only way
 * it means anything. A password the highest role bypasses protects from
 * nobody: it decorates a screen.
 *
 * **Turning it off requires entering it.** Without this rule, anyone
 * reaching the settings removes it in one click, and the protection only
 * holds against a screen left open. With it, forgetting becomes possible: the
 * fallback is `aurora:space:drive-password:clear`, on the server. The
 * authority that unlocks is therefore access to the machine, which is a real
 * level of authority and not a checkbox.
 *
 * **Unlocked for a session, not forever.** Closing with the browser is what
 * is expected of a lock; a cookie lasting weeks would make the initial entry
 * decorative. And per space: opening one does not open the others.
 *
 * **And lockable again for everyone at once.** A session does not remember
 * "open" but the generation it opened; the space carries one, and drawing a
 * new one expires every session at once. That is what allows locking
 * everything again without changing the password, and what makes changing
 * one really lock.
 */
final readonly class DriveLock
{
    private const string SESSION_PREFIX = 'studio.drive.unlocked.';

    /**
     * The minimum a password must weigh.
     *
     * **Here and not in the controller that used to read it.** The lock is
     * what knows what a space password is; the screen only asks for it. The
     * day a second caller sets one, it will not have to find the number again
     * to agree.
     *
     * Eight, the same floor as everywhere else: stricter for an inner door
     * than for the front door could not be justified.
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

    /** Locked, and not yet opened in this session. */
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
     * Opens for this session, if the password is right.
     *
     * Returns false without saying anything more: "wrong password" is the
     * only useful answer, and telling the cases apart would only teach
     * whoever is trying.
     */
    public function unlock(CustomerSpaceInterface $space, string $password): bool
    {
        if (!$this->matches($space, $password)) {
            return false;
        }

        // A generation if needed: spaces already locked before it existed
        // carry none, and without this seeding the right entry would never
        // open anything. The caller saves.
        if (null === $space->getDriveLockGeneration()) {
            $space->setDriveLockGeneration(bin2hex(random_bytes(8)));
        }

        $this->session()?->set($this->key($space), $space->getDriveLockGeneration());

        return true;
    }

    /** Locks again in this session, without touching the password. */
    public function lock(CustomerSpaceInterface $space): void
    {
        $this->session()?->remove($this->key($space));
    }

    /** Long enough to be set. */
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
     * Sets or replaces the password, and locks right away.
     *
     * **Including for whoever just chose it**, and that is a deliberate
     * reversal. Keeping their session open was more comfortable: they had
     * just proved they knew the password, asking again seemed bureaucratic.
     * Except that you lock a door to see it lock. A lock that is set and
     * changes nothing on screen looks like a setting that did not take, and
     * the first reflex is to doubt the product.
     *
     * Typing it again costs five seconds and proves three things at once: that
     * the password is indeed saved, that it is the one you think, and that it
     * locks something.
     */
    public function set(CustomerSpaceInterface $space, string $password): void
    {
        $space->setDrivePassword($this->hasher()->hash($password));
        $this->revoke($space);
    }

    /**
     * Locks again everywhere, without touching the password.
     *
     * **The gesture you want at hand without having to change anything.**
     * A screen left open on a machine no longer under control, someone who
     * was shown the tab, a doubt: it must be possible to ask everyone to enter
     * it again without imposing a new password on those who already know it.
     *
     * Whoever presses it is locked out like the others: not being so would
     * cast doubt on whether the button worked, and they know the password.
     */
    public function revoke(CustomerSpaceInterface $space): void
    {
        $space->setDriveLockGeneration(bin2hex(random_bytes(8)));
        $this->lock($space);
    }

    /** Reopens the tab for good. The caller has already checked the password. */
    public function clear(CustomerSpaceInterface $space): void
    {
        $space->setDrivePassword(null);
        $this->revoke($space);
    }

    /**
     * The hasher the project configures, and not a local choice.
     *
     * A space password has no reason to be weaker than an account password:
     * naming one here would make it drift the day the project changes its
     * own.
     */
    private function hasher(): PasswordHasherInterface
    {
        return $this->hashers->getPasswordHasher(PasswordAuthenticatedUserInterface::class);
    }

    /**
     * The session, if there is one.
     *
     * **Null in the console**, and that is what lets the fallback command call
     * `clear()` like the screen rather than reopening the door its own way.
     * Two ways of clearing a password always end up drifting apart, and the
     * one run less often is the one that is wrong.
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
