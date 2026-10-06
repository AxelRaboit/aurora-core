<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Craft\Service;

use Aurora\Module\Notes\Craft\Setting\CraftSettings;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

use function is_array;
use function is_string;
use function mb_trim;
use function usort;

/**
 * Talks to Craft for the server.
 *
 * **Two calls, not an MCP client.** Craft does expose its MCP server over
 * HTTP, behind a full OAuth 2.1; it also exposes, per connection, a REST
 * address and a token. The second door takes two GET requests where the
 * first would take an MCP client in PHP, dynamic client registration, a
 * callback route and tokens to refresh - for the same result: reading a
 * document.
 *
 * `GET /documents` gives the list, `GET /blocks?id=<rootBlockId>` gives the
 * content, and the `Accept: text/markdown` header asks Craft to do the
 * rendering itself. That is the key point of this whole path: the Markdown
 * comes from Craft, which knows its own blocks, and Aurora never has to
 * interpret a structure that does not belong to it.
 *
 * **Failures become an empty list rather than an exception.** An import that
 * cannot reach Craft is a screen that offers nothing, which is a
 * disappointment; an exception here would be a 500 error in the middle of the
 * notes, which is something else.
 */
final readonly class CraftClient
{
    private const int TIMEOUT_SECONDS = 10;

    /**
     * What a connection can legitimately carry. Beyond that, the list is no
     * longer a selection screen and the connection was created too wide.
     */
    private const int MAX_DOCUMENTS = 200;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private CraftSettings $settings,
    ) {}

    public function isConfigured(): bool
    {
        return $this->settings->isEnabled();
    }

    /**
     * The documents the connection lets you see.
     *
     * Sorted by title: Craft returns them in its own order, which is not the
     * order of a list you scan with your eyes.
     *
     * **Null when nothing came back, and not an empty list.** The two look
     * alike on screen and do not mean the same thing at all: a connection to
     * which no document has been added yet is fixed in Craft, a wrong key is
     * fixed in the settings. Saying "no document" in the second case is a lie
     * that sends you looking in the wrong place.
     *
     * @return list<array{id: string, title: string}>|null
     */
    public function documents(): ?array
    {
        $payload = $this->get('/documents');

        if (null === $payload) {
            return null;
        }

        // `items`, the name given by the specification published by the
        // connection itself (`GET /openapi.json`). The fallback to the root
        // covers a response that would be a bare array.
        $rows = is_array($payload['items'] ?? null) ? $payload['items'] : $payload;
        $documents = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            // `rootBlockId` and not `id`: it is the one `/blocks` expects, and
            // the id that a document address displays is a different one.
            $id = $row['rootBlockId'] ?? $row['id'] ?? null;
            $title = $row['title'] ?? null;
            if (!is_string($id)) {
                continue;
            }

            if ('' === $id) {
                continue;
            }

            // Craft keeps the row of a deleted document, with its title.
            // Offering it for import would be offering an empty note.
            if (true === ($row['isDeleted'] ?? false)) {
                continue;
            }

            $documents[] = [
                'id' => $id,
                'title' => is_string($title) && '' !== mb_trim($title) ? mb_trim($title) : $id,
            ];

            if (self::MAX_DOCUMENTS === count($documents)) {
                break;
            }
        }

        usort($documents, static fn (array $a, array $b): int => $a['title'] <=> $b['title']);

        return $documents;
    }

    /**
     * The content of a document, as Markdown rendered by Craft.
     *
     * Null when nothing came back: the caller turns it into a message, not an
     * empty note.
     */
    public function markdown(string $rootBlockId): ?string
    {
        if (!$this->isConfigured() || '' === mb_trim($rootBlockId)) {
            return null;
        }

        try {
            $response = $this->httpClient->request(
                'GET',
                $this->settings->endpoint().'/blocks',
                $this->options(['query' => ['id' => $rootBlockId], 'headers' => ['Accept' => 'text/markdown']]),
            );

            $body = $response->getContent();
        } catch (Throwable $throwable) {
            $this->logger->warning('Craft document read failed.', [
                'rootBlockId' => $rootBlockId,
                'exception' => $throwable->getMessage(),
            ]);

            return null;
        }

        return '' === mb_trim($body) ? null : $body;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function get(string $path): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        try {
            $response = $this->httpClient->request(
                'GET',
                $this->settings->endpoint().$path,
                $this->options(['headers' => ['Accept' => 'application/json']]),
            );

            return $response->toArray();
        } catch (Throwable $throwable) {
            $this->logger->warning('Craft request failed.', [
                'path' => $path,
                'exception' => $throwable->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The common options, including the authentication header.
     *
     * **The only place where the shape of the key is written.** Craft shows a
     * connection as "Public" or "API key". Public, the address alone opens
     * everything - and the specification the connection publishes declares
     * nineteen write operations. In key mode, the API answers
     * `401 MISSING_AUTH_HEADER` without an `Authorization` header: this is
     * measured, not assumed, and this method alone is the one to fix if the
     * shape changes.
     *
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function options(array $extra): array
    {
        /** @var array<string, string> $headers */
        $headers = $extra['headers'] ?? [];
        $headers['Authorization'] = 'Bearer '.$this->settings->token();

        return [...$extra, 'headers' => $headers, 'timeout' => self::TIMEOUT_SECONDS];
    }
}
