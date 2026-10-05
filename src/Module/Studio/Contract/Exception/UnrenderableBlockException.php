<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Exception;

use RuntimeException;
use Symfony\Contracts\Translation\TranslatorInterface;

use function mb_strtoupper;
use function sprintf;

/**
 * A block the contract renderer cannot turn into HTML.
 *
 * Thrown rather than skipped, which is the opposite of what a web page should
 * do. Skipping is right when the cost of a missing block is one absent widget;
 * here the cost is a clause that quietly disappears from a document somebody
 * signs, and a contract that refuses to freeze is a problem somebody fixes
 * while a contract missing an article is one nobody notices.
 *
 * Reaching this means a template carries a block type the contract renderer
 * does not support - an image, an embed, a callout. The fix is either to take
 * it out of the wording or to teach the renderer about it, and both are
 * decisions rather than accidents.
 */
final class UnrenderableBlockException extends RuntimeException
{
    /**
     * Where the block is (counted from one, as a reader counts) and what it
     * is, so the screen can say it in the reader's language: the message
     * itself is for the logs, and it used to be shown as is.
     */
    public int $blockNumber = 0;

    public ?string $blockType = null;

    public static function unknownType(string $type, int $index): self
    {
        $exception = new self(sprintf(
            'Block %d is of type "%s", which a contract cannot render. Remove it from the wording, or teach ContractDocumentRenderer about it.',
            $index + 1,
            '' === $type ? 'unnamed' : $type,
        ));
        $exception->blockNumber = $index + 1;
        $exception->blockType = '' === $type ? null : $type;

        return $exception;
    }

    /** Said to the reader, in their language, naming the wording it is in. */
    public function describe(TranslatorInterface $translator, string $locale): string
    {
        return null === $this->blockType
            ? $translator->trans('suite.studio.contract_templates.errors.malformed_block', ['{number}' => $this->blockNumber, '{locale}' => mb_strtoupper($locale)])
            : $translator->trans('suite.studio.contract_templates.errors.unrenderable_block', ['{number}' => $this->blockNumber, '{locale}' => mb_strtoupper($locale), '{type}' => $this->blockType]);
    }

    public static function malformed(int $index): self
    {
        $exception = new self(sprintf('Block %d is not a block at all. The stored document is malformed.', $index + 1));
        $exception->blockNumber = $index + 1;

        return $exception;
    }
}
