<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceNote\Craft\Service;

use Aurora\Module\Studio\SpaceNote\Craft\Setting\CraftSettings;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

use function is_array;
use function is_string;
use function mb_trim;
use function usort;

/**
 * Parle à Craft pour le serveur.
 *
 * **Deux appels, pas un client MCP.** Craft expose bien son serveur MCP en
 * HTTP, derrière un OAuth 2.1 complet ; il expose aussi, par connexion, une
 * adresse REST et un jeton. La seconde porte demande deux requêtes GET là où
 * la première demanderait un client MCP en PHP, un enregistrement dynamique de
 * client, une route de retour et des jetons à rafraîchir - pour le même
 * résultat : lire un document.
 *
 * `GET /documents` donne la liste, `GET /blocks?id=<rootBlockId>` donne le
 * contenu, et l'en-tête `Accept: text/markdown` demande à Craft de faire
 * lui-même le rendu. C'est le point important de tout ce chemin : le Markdown
 * vient de Craft, qui connaît ses propres blocs, et Aurora n'a jamais à
 * interpréter une structure qui ne lui appartient pas.
 *
 * **Les échecs deviennent une liste vide plutôt qu'une exception.** Un import
 * qui ne joint pas Craft est un écran qui ne propose rien, ce qui est une
 * déception ; une exception ici serait une erreur 500 au milieu d'un espace
 * client, ce qui est autre chose.
 */
final readonly class CraftClient
{
    private const int TIMEOUT_SECONDS = 10;

    /**
     * Ce qu'une connexion peut légitimement porter. Au-delà, la liste n'est
     * plus un écran de choix et la connexion a été créée trop large.
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
     * Les documents que la connexion laisse voir.
     *
     * Triés par titre : Craft les rend dans son ordre à lui, qui n'est pas
     * celui d'une liste qu'on parcourt des yeux.
     *
     * @return list<array{id: string, title: string}>
     */
    public function documents(): array
    {
        $payload = $this->get('/documents');

        if (null === $payload) {
            return [];
        }

        // `items`, le nom que donne la spécification publiée par la connexion
        // elle-même (`GET /openapi.json`). Le repli sur la racine couvre une
        // réponse qui serait un tableau nu.
        $rows = is_array($payload['items'] ?? null) ? $payload['items'] : $payload;
        $documents = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            // `rootBlockId` et pas `id` : c'est celui que `/blocks` attend, et
            // l'identifiant qu'une adresse de document affiche est un autre.
            $id = $row['rootBlockId'] ?? $row['id'] ?? null;
            $title = $row['title'] ?? null;
            if (!is_string($id)) {
                continue;
            }

            if ('' === $id) {
                continue;
            }

            // Craft garde la ligne d'un document supprimé, avec son titre.
            // Le proposer à l'import serait proposer une note vide.
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
     * Le contenu d'un document, en Markdown rendu par Craft.
     *
     * Null quand rien n'est venu : l'appelant en fait un message, pas une note
     * vide.
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
     * Les options communes, dont l'en-tête d'authentification.
     *
     * **Le seul endroit où la forme de la clé est écrite.** Craft montre
     * une connexion en « Publique » ou en « Clé API ». Publique, l'adresse
     * seule ouvre tout - et la spécification que la connexion publie déclare
     * dix-neuf opérations d'écriture. En mode clé, l'API répond
     * `401 MISSING_AUTH_HEADER` sans en-tête `Authorization` : c'est mesuré,
     * pas supposé, et c'est cette méthode seule qu'on corrige si la forme
     * change.
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
