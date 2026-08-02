<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Comment\Service;

use Aurora\Module\Editorial\Comment\Dto\CommentInputInterface;

/**
 * Decides whether a submission is spam, before anything is written or sent.
 *
 * The reference did this the other way round: a decorator called `submit()`,
 * which persisted the comment, wrote an audit entry **and sent the
 * notification email**, and only then marked the result as spam. So every
 * spam comment mailed the administrator — the exact outcome a spam filter
 * exists to prevent — and with moderation switched off it mailed the address
 * the spammer had typed, turning the site into a relay for anyone who filled
 * the form.
 *
 * A detector the Manager consults first cannot do that, because there is
 * nothing to undo.
 */
final readonly class CommentSpamDetector
{
    /** Beyond this many links, the message is an advert whatever it says. */
    private const int MAX_URLS = 5;

    /**
     * A message carrying links needs at least this much text of its own.
     * "Great post! http://…" is the shape being caught.
     */
    private const int MIN_TEXT_AROUND_URLS = 50;

    private const string URL_PATTERN = '/https?:\/\/\S+/i';

    public function isSpam(CommentInputInterface $input): bool
    {
        // A filled honeypot is not a judgement call: no reader can fill a
        // field the form hides.
        if ('' !== $input->getHoneypot()) {
            return true;
        }

        return $this->looksLikeLinkSpam($input->getContent());
    }

    private function looksLikeLinkSpam(string $content): bool
    {
        $matches = [];
        preg_match_all(self::URL_PATTERN, $content, $matches);
        $urlCount = count($matches[0]);

        if ($urlCount > self::MAX_URLS) {
            return true;
        }

        if (0 === $urlCount) {
            return false;
        }

        $withoutUrls = mb_trim(preg_replace(self::URL_PATTERN, '', $content) ?? '');

        return mb_strlen($withoutUrls) < self::MIN_TEXT_AROUND_URLS;
    }
}
