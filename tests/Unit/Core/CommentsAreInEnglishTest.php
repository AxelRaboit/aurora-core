<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_flip;
use function count;
use function dirname;
use function file_get_contents;
use function implode;
use function in_array;
use function is_array;
use function is_file;
use function mb_strlen;
use function mb_strtolower;
use function mb_substr;
use function min;
use function pathinfo;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function token_get_all;

use const PATHINFO_EXTENSION;
use const PREG_OFFSET_CAPTURE;
use const T_COMMENT;
use const T_DOC_COMMENT;

/**
 * Comments, docblocks and template comments are written in English.
 *
 * **The rule existed, and nothing held it.** It was written down in May, in a
 * memory nobody reads while coding. Work went on in French conversations, and
 * from mid-September close to half of the new comment lines came out in
 * French: on 6 October 2026, 4,200 comments in about 790 files. They were all
 * translated in one pass, and this test keeps the count at zero.
 *
 * Telling two languages apart without a dictionary is a guess, so the test
 * only reacts to a clear case: a comment carrying several French function
 * words ("le", "que", "dans"...) or accents, and clearly more of them than
 * English ones. A short French label can slip through; a French paragraph
 * cannot. Text between quotes is left out, so an English comment may still
 * quote what the screen says in French.
 */
final class CommentsAreInEnglishTest extends TestCase
{
    /** What describes the product, as opposed to what it depends on. */
    private const array SCANNED = ['src', 'fixtures', 'tests', 'tools', 'migrations', 'config', 'templates', '.github'];

    /** Single files at the root of the repository. */
    private const array ROOT_FILES = ['Makefile', 'vite.config.js', 'vitest.config.js', 'playwright.config.js', 'aliases.js', 'vite-plugin-aurora-modules.js'];

    private const array EXTENSIONS = ['php', 'js', 'mjs', 'ts', 'vue', 'css', 'twig', 'yaml', 'yml'];

    /**
     * Words that only French uses this often.
     *
     * English look-alikes are left out on purpose: "on", "son", "plus",
     * "par", "sans" (as in sans-serif), "vers" (versions).
     */
    private const array FRENCH = [
        'le', 'la', 'les', 'des', 'une', 'est', 'sont', 'pour', 'avec', 'dans', 'qui', 'que', 'quand', 'pas',
        'sur', 'ne', 'ce', 'cette', 'ces', 'du', 'au', 'aux', 'et', 'ou', 'mais', 'donc', 'car', 'sinon', 'il',
        'elle', 'ils', 'elles', 'leur', 'leurs', 'sa', 'ses', 'tout', 'tous', 'toute', 'toutes', 'déjà', 'aussi',
        'encore', 'comme', 'sous', 'chez', 'être', 'avoir', 'fait', 'faut', 'peut', 'doit', 'dont', 'où', 'très',
        'même', 'autre', 'autres', 'ici', 'lors', 'afin', 'selon', 'entre', 'depuis', 'après', 'puis', 'alors',
        'jamais', 'toujours', 'rien', 'chaque', 'seul', 'seule',
    ];

    private const array ENGLISH = [
        'the', 'is', 'are', 'for', 'with', 'in', 'which', 'that', 'when', 'not', 'this', 'these', 'those', 'of',
        'to', 'and', 'or', 'but', 'so', 'if', 'it', 'its', 'they', 'their', 'be', 'been', 'has', 'have', 'had',
        'does', 'do', 'did', 'can', 'could', 'should', 'would', 'will', 'must', 'may', 'only', 'also', 'still',
        'just', 'than', 'then', 'there', 'here', 'what', 'where', 'who', 'why', 'how', 'because', 'without',
        'into', 'from', 'by', 'an', 'as', 'at', 'was', 'were', 'any', 'all', 'each', 'every', 'other', 'same',
    ];

    public function testNoCommentIsWrittenInFrench(): void
    {
        $root = dirname(__DIR__, 3);
        $found = [];

        foreach ($this->files($root) as $relative => $path) {
            $source = (string) file_get_contents($path);

            foreach ($this->joinLineComments($this->commentsOf($relative, $source)) as [$line, $text]) {
                if ($this->readsAsFrench($text)) {
                    $found[] = sprintf('%s:%d', $relative, $line);
                }
            }
        }

        self::assertSame([], $found, sprintf(
            "%d comment(s) are written in French:\n  %s\nComments, docblocks and template comments are written in English. French text shown on screen may be quoted between quotes.",
            count($found),
            implode("\n  ", $found),
        ));
    }

    /**
     * The detector itself: a test that never fires proves nothing.
     */
    public function testTheDetectorTellsTheTwoLanguagesApart(): void
    {
        self::assertTrue($this->readsAsFrench('Les contrats dont la référence contient le terme, triés par date.'));
        self::assertTrue($this->readsAsFrench("Une copie se reprend avant d'être montrée, et ne part pas avec les liens."));
        self::assertFalse($this->readsAsFrench('The inverse collection of a taxonomy is only filled when it comes from the database.'));
        self::assertFalse($this->readsAsFrench('Left standing on purpose: the signer fills in « fait à …, le … » exactly as on paper.'));
        self::assertFalse($this->readsAsFrench('@param array<string, mixed> $options'));
    }

    /** @return array<string, string> path relative to the root => absolute path */
    private function files(string $root): array
    {
        $files = [];

        foreach (self::ROOT_FILES as $file) {
            if (is_file($root.'/'.$file)) {
                $files[$file] = $root.'/'.$file;
            }
        }

        foreach (self::SCANNED as $scannedDirectory) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$scannedDirectory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if (!$file instanceof SplFileInfo || !in_array($file->getExtension(), self::EXTENSIONS, true)) {
                    continue;
                }

                $relative = mb_substr($file->getPathname(), mb_strlen($root) + 1);

                // Other people's code: dependencies of the tools, and the
                // patched copy of a package kept until upstream ships the fix.
                if (str_contains($relative, '/vendor/') || str_contains($relative, '/node_modules/') || str_starts_with($relative, 'tools/patched/')) {
                    continue;
                }

                $files[$relative] = $file->getPathname();
            }
        }

        return $files;
    }

    /** @return list<array{int, string}> line and text of every comment */
    private function commentsOf(string $path, string $source): array
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        if ('php' === $extension) {
            $comments = [];
            foreach (token_get_all($source) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    $comments[] = [$token[2], $token[1]];
                }
            }

            return $comments;
        }

        if (in_array($extension, ['js', 'mjs', 'ts', 'css'], true)) {
            return $this->cLikeComments($source, 0);
        }

        if ('vue' === $extension) {
            $comments = $this->commentsMatching('/<!--.*?-->/s', $source);
            preg_match_all('#<(script|style)[^>]*>(.*?)</\1>#s', $source, $blocks, PREG_OFFSET_CAPTURE);
            foreach ($blocks[2] as [$body, $offset]) {
                $comments = [...$comments, ...$this->cLikeComments($body, $this->lineAt($source, $offset) - 1)];
            }

            return $comments;
        }

        if ('twig' === $extension) {
            return $this->commentsMatching('/\{#.*?#\}|<!--.*?-->/s', $source);
        }

        // YAML, Makefile: whole comment lines only. A " # " further along the
        // line is as often inside a translated sentence as before a comment.
        return $this->commentsMatching('/^\s*#.*$/m', $source);
    }

    /**
     * Comments of JavaScript or CSS, string literals skipped so that a URL in
     * a string is not taken for a comment.
     *
     * @return list<array{int, string}>
     */
    private function cLikeComments(string $source, int $lineOffset): array
    {
        preg_match_all(
            '~"(?:\\\\.|[^"\\\\\n])*"|\'(?:\\\\.|[^\'\\\\\n])*\'|`(?:\\\\.|[^`\\\\])*`|(/\*.*?\*/|(?<![:\\\\])//[^\n]*)~s',
            $source,
            $tokens,
            PREG_OFFSET_CAPTURE,
        );

        $comments = [];
        foreach ($tokens[1] as $index => $comment) {
            if ('' !== $comment[0] && $comment[1] >= 0 && $tokens[0][$index][1] === $comment[1]) {
                $comments[] = [$lineOffset + $this->lineAt($source, $comment[1]), $comment[0]];
            }
        }

        return $comments;
    }

    /** @return list<array{int, string}> */
    private function commentsMatching(string $pattern, string $source): array
    {
        preg_match_all($pattern, $source, $found, PREG_OFFSET_CAPTURE);

        $comments = [];
        foreach ($found[0] as [$text, $offset]) {
            $comments[] = [$this->lineAt($source, $offset), $text];
        }

        return $comments;
    }

    /**
     * A run of `//` or `#` lines is one comment, read as a whole: a French
     * paragraph cut into lines is still a French paragraph.
     *
     * @param list<array{int, string}> $comments
     *
     * @return list<array{int, string}>
     */
    private function joinLineComments(array $comments): array
    {
        $joined = [];
        $lastLine = -1;

        foreach ($comments as [$line, $text]) {
            $single = 1 === preg_match('~^\s*(//|#)~', $text);

            if ($single && [] !== $joined && $line === $lastLine + 1) {
                $joined[count($joined) - 1][1] .= "\n".$text;
            } else {
                $joined[] = [$line, $text];
            }

            $lastLine = $single ? $line : -1;
        }

        return $joined;
    }

    /**
     * The line a byte offset falls on, counted from 1.
     *
     * Offsets come from `preg_match_all()`, so they count bytes: the
     * multibyte string functions count characters and would drift on every
     * accent before the comment.
     */
    private function lineAt(string $source, int $offset): int
    {
        preg_match_all('/\n/', $source, $newlines, PREG_OFFSET_CAPTURE);

        $line = 1;
        foreach ($newlines[0] as [, $position]) {
            if ($position >= $offset) {
                break;
            }
            ++$line;
        }

        return $line;
    }

    private function readsAsFrench(string $comment): bool
    {
        // Quoted text, code and annotations say nothing about the language
        // the comment is written in.
        $text = (string) preg_replace(
            ['/«[^»]*»/u', '/"[^"\n]*"/', '/`[^`]*`/', '/\{@\w+[^}]*\}/', '/@\w+[^\n]*/', '~https?://\S+~'],
            ' ',
            $comment,
        );

        preg_match_all("/[\\p{L}']+/u", mb_strtolower($text), $words);

        static $frenchWords = array_flip(self::FRENCH);
        static $englishWords = array_flip(self::ENGLISH);

        $french = 0;
        $english = 0;
        foreach ($words[0] as $word) {
            if (isset($frenchWords[$word]) || 1 === preg_match("/^(l|d|qu|n|s|c|j)'\\p{L}/u", $word)) {
                ++$french;
            } elseif (isset($englishWords[$word])) {
                ++$english;
            }
        }

        $accents = preg_match_all('/[éèêàùçôîâûœ]/u', $text);
        $score = $french + 0.7 * min($accents, 3);

        return $score >= 3 && $score > 1.5 * $english;
    }
}
