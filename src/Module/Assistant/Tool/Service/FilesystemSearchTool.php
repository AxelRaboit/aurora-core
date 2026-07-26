<?php

declare(strict_types=1);

namespace Aurora\Module\Assistant\Tool\Service;

use Aurora\Module\Assistant\MountPoint\Service\MountPointPathGuard;
use Aurora\Module\Assistant\Tool\Contract\ToolInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function count;
use function in_array;
use function is_string;
use function sprintf;

/**
 * Recursive name + content search across the user's mount points. Fills
 * the gap left by filesystem_read (which only lists one directory or
 * reads one file): without this, the chat model had no way to answer
 * "find the invoice with 'Commercial invoice' in it" — it had to walk
 * the tree one directory at a time, which it doesn't naturally do.
 *
 * Hard caps prevent the tool from eating the LLM context window or
 * blocking on a giant tree:
 *  - 5 000 file stats max per call (skip the rest)
 *  - 30 matching files in the result
 *  - 8 KB scanned per file for content matches
 *  - first 80 chars of the matching line as snippet
 *
 * Binary files and dotfiles/`.git`/`node_modules`/`vendor` are skipped
 * outright so the tool returns useful signal on real source trees.
 */
final readonly class FilesystemSearchTool implements ToolInterface
{
    private const int MAX_FILES_VISITED = 5000;

    private const int MAX_RESULTS = 30;

    private const int MAX_BYTES_PER_FILE = 8 * 1024;

    private const int SNIPPET_LEN = 80;

    // Noise directories skipped during the walk. `var/` is NOT in the
    // list because Symfony projects store user uploads under
    // `var/uploads/` per Aurora storage policy — skipping the whole `var`
    // would hide every invoice / note attachment from the assistant.
    private const array SKIP_DIRS = ['.git', 'node_modules', 'vendor', '.cache', '.idea', 'dist', 'build'];

    public function __construct(
        private MountPointPathGuard $pathGuard,
    ) {}

    public function getName(): string
    {
        return 'filesystem_search';
    }

    public function requiresConfirmation(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Recursively search the user\'s mount points for files whose name OR text content matches a query. Use this when filesystem_read alone is not enough (e.g. "find a file containing X", "where is the README that mentions Y"). Skips binary files, dotfiles, vendor/node_modules.';
    }

    public function getParameterSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Substring to search for (case-insensitive). Matched against both filenames and the first 8KB of text-file contents.',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'Optional: absolute path inside a mount point to scope the search. Defaults to scanning every active mount point of the user.',
                ],
                'mode' => [
                    'type' => 'string',
                    'description' => 'What to match: "name" (filename only, fastest), "content" (file content only), "both" (default — name OR content).',
                    'enum' => ['name', 'content', 'both'],
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $arguments, CoreUserInterface $user): string
    {
        $query = isset($arguments['query']) && is_string($arguments['query']) ? mb_trim($arguments['query']) : '';
        if ('' === $query) {
            return 'Error: missing or empty "query" argument.';
        }

        $mode = isset($arguments['mode']) && is_string($arguments['mode']) ? $arguments['mode'] : 'both';
        if (!in_array($mode, ['name', 'content', 'both'], true)) {
            $mode = 'both';
        }

        $roots = $this->resolveSearchRoots($arguments, $user);
        if (null === $roots) {
            return sprintf('Error: path is outside any active mount point: %s', $arguments['path'] ?? '');
        }

        if ([] === $roots) {
            return 'Error: no active mount points configured for this user. Add one in /backend/assistant/mount-points.';
        }

        $needle = mb_strtolower($query);
        $matches = [];
        $visited = 0;
        $truncated = false;

        foreach ($roots as $root) {
            $directoryIterator = new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS);
            $filtered = new RecursiveCallbackFilterIterator(
                $directoryIterator,
                static function (SplFileInfo $current): bool {
                    $name = $current->getBasename();
                    if (str_starts_with($name, '.')) {
                        return false;
                    }

                    if (!$current->isDir()) {
                        return true;
                    }

                    return !in_array($name, self::SKIP_DIRS, true);
                },
            );

            /** @var SplFileInfo $file */
            foreach (new RecursiveIteratorIterator($filtered) as $file) {
                if (count($matches) >= self::MAX_RESULTS) {
                    $truncated = true;
                    break 2;
                }

                if ($visited >= self::MAX_FILES_VISITED) {
                    $truncated = true;
                    break 2;
                }

                if (!$file->isFile()) {
                    continue;
                }

                ++$visited;
                $match = $this->matchFile($file, $file->getBasename(), $needle, $mode);
                if (null !== $match) {
                    $matches[] = $match;
                }
            }
        }

        if ([] === $matches) {
            return sprintf('No matches for "%s" across %d files scanned.', $query, $visited);
        }

        $header = sprintf('%d match%s for "%s" (%d files scanned%s):', count($matches), 1 === count($matches) ? '' : 'es', $query, $visited, $truncated ? ', truncated' : '');

        return $header."\n".implode("\n", $matches);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return list<string>|null returns null if a specified path is outside the allowed mount points
     */
    private function resolveSearchRoots(array $arguments, CoreUserInterface $user): ?array
    {
        $bases = $this->pathGuard->activeBases($user);

        if (!isset($arguments['path']) || !is_string($arguments['path']) || '' === mb_trim($arguments['path'])) {
            return $bases;
        }

        $requested = realpath(mb_trim($arguments['path']));
        if (false === $requested || !$this->pathGuard->isAllowed($requested, $user)) {
            return null;
        }

        return [$requested];
    }

    /**
     * Returns a formatted match line, or null if the file does not match
     * or is not eligible for the given mode.
     */
    private function matchFile(SplFileInfo $file, string $basename, string $needleLower, string $mode): ?string
    {
        $path = $file->getPathname();
        $nameMatches = str_contains(mb_strtolower($basename), $needleLower);

        if ('name' === $mode) {
            return $nameMatches ? sprintf('  %s', $path) : null;
        }

        if ('both' === $mode && $nameMatches) {
            return sprintf('  %s  (name match)', $path);
        }

        // Content match: only text files
        $size = $file->getSize();
        if ($size <= 0) {
            return null;
        }

        $handle = @fopen($path, 'rb');
        if (false === $handle) {
            return null;
        }

        try {
            $chunk = (string) fread($handle, self::MAX_BYTES_PER_FILE);
        } finally {
            fclose($handle);
        }

        if ('' === $chunk || str_contains($chunk, "\0")) {
            return null;
        }

        $lowerChunk = mb_strtolower($chunk);
        $pos = mb_strpos($lowerChunk, $needleLower);
        if (false === $pos) {
            return null;
        }

        // Snippet on the matching line
        $lineStart = mb_strrpos(mb_substr($chunk, 0, $pos), "\n");
        $lineStart = false === $lineStart ? 0 : $lineStart + 1;

        $lineEnd = mb_strpos($chunk, "\n", $pos);
        $lineEnd = false === $lineEnd ? mb_strlen($chunk) : $lineEnd;

        $line = mb_trim(mb_substr($chunk, $lineStart, $lineEnd - $lineStart));
        if (mb_strlen($line) > self::SNIPPET_LEN) {
            $line = mb_substr($line, 0, self::SNIPPET_LEN - 1).'…';
        }

        $lineNumber = mb_substr_count(mb_substr($chunk, 0, $pos), "\n") + 1;

        return sprintf('  %s:%d  %s', $path, $lineNumber, $line);
    }
}
