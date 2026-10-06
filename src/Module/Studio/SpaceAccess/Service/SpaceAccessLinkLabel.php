<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceAccess\Service;

use Aurora\Module\Studio\SpaceAccess\Dto\SpaceAccessLinkInput;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;

use function explode;
use function mb_convert_case;
use function mb_trim;
use function str_replace;

use const MB_CASE_TITLE;

/**
 * The name a guest appears under.
 *
 * **Never their address.** It used to sign their messages, comments and
 * files; on a space with several links, each guest could therefore read the
 * others' addresses, without asking for it or being able to prevent it.
 *
 * The label is required when issuing a link since
 * {@see SpaceAccessLinkInput}, so the
 * normal case is a single line. The fallback exists for links issued before
 * this rule: it draws a name from the left part of the address rather than
 * leaving an anonymous message, because two nameless guests in the same thread
 * can no longer be told apart.
 *
 * A class rather than a method on the link entity: three entities use it, and
 * the third copy of a rule is what teaches that it does not belong in each of
 * them.
 */
final readonly class SpaceAccessLinkLabel
{
    public static function of(SpaceAccessLinkInterface $link): string
    {
        $label = mb_trim((string) $link->getLabel());

        if ('' !== $label) {
            return $label;
        }

        return self::fromEmail($link->getRecipientEmail());
    }

    /**
     * "camille.perrot@…" becomes "Camille Perrot".
     *
     * The domain does not come out: it names the company, and it is the part
     * of the address read fastest in a thread.
     */
    private static function fromEmail(string $email): string
    {
        $local = explode('@', $email)[0];
        $words = mb_trim(str_replace(['.', '_', '-', '+'], ' ', $local));

        return '' === $words ? 'Invité' : mb_convert_case($words, MB_CASE_TITLE);
    }
}
