<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\GitHub\Service;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

use function array_column;
use function array_sum;
use function libxml_clear_errors;
use function libxml_use_internal_errors;
use function max;
use function mb_strtolower;
use function mb_trim;
use function min;
use function preg_match;
use function rawurlencode;
use function sprintf;
use function str_replace;
use function usort;

/**
 * La grille de contributions d'un compte GitHub, sur les douze derniers mois.
 *
 * **Lue sur la page publique du profil**, `/users/{login}/contributions` : le
 * fragment que GitHub affiche lui-même, sans clé ni compte. Ce n'est pas une
 * API documentée, son format peut changer sans prévenir. D'où les deux
 * filets : une grille qui ne se lit plus rend `null`, et la dernière grille
 * lue reste servie trente jours plutôt que de laisser un trou dans la page.
 *
 * **Le compte exact d'un jour n'est pas sur la case**, qui ne porte qu'une
 * intensité de 0 à 4 : il est dans l'infobulle associée, « 20 contributions
 * on April 5th. ».
 *
 * Rien ne se demande à GitHub plus d'une fois toutes les six heures par compte,
 * et une panne n'est pas redemandée à chaque visite : une page publique ne
 * doit pas attendre GitHub à chaque affichage parce que GitHub ne répond pas.
 */
final readonly class GitHubContributions
{
    private const string URL = 'https://github.com/users/%s/contributions';

    private const string CACHE_KEY = 'editorial.github.contributions.';

    private const int TTL_SECONDS = 21_600;

    /** Le délai avant de réessayer un compte qui n'a pas répondu. */
    private const int RETRY_SECONDS = 600;

    /** Combien de temps une ancienne grille remplace une grille illisible. */
    private const int STALE_SECONDS = 2_592_000;

    private const int TIMEOUT_SECONDS = 5;

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return array{total: int, days: list<array{date: string, level: int, count: int, row: int, col: int}>}|null
     */
    public function forLogin(string $login): ?array
    {
        $key = self::CACHE_KEY.mb_strtolower($login);

        return $this->cache->get($key, function (ItemInterface $item) use ($login, $key): ?array {
            $fresh = $this->fetch($login);

            if (null !== $fresh) {
                $item->expiresAfter(self::TTL_SECONDS);
                $this->cache->delete($key.'.stale');
                $this->cache->get($key.'.stale', static function (ItemInterface $stale) use ($fresh): array {
                    $stale->expiresAfter(self::STALE_SECONDS);

                    return $fresh;
                });

                return $fresh;
            }

            $item->expiresAfter(self::RETRY_SECONDS);

            return $this->cache->get($key.'.stale', static function (ItemInterface $stale, bool &$save): ?array {
                $save = false;

                return null;
            });
        });
    }

    /**
     * @return array{total: int, days: list<array{date: string, level: int, count: int, row: int, col: int}>}|null
     */
    private function fetch(string $login): ?array
    {
        try {
            $response = $this->httpClient->request('GET', sprintf(self::URL, rawurlencode($login)), [
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS,
                'max_redirects' => 0,
                'headers' => ['X-Requested-With' => 'XMLHttpRequest'],
            ]);

            if (200 !== $response->getStatusCode()) {
                return null;
            }

            $grid = self::parse($response->getContent());
        } catch (Throwable $throwable) {
            $this->logger->warning('GitHub contributions could not be fetched.', [
                'login' => $login,
                'exception' => $throwable,
            ]);

            return null;
        }

        if (null === $grid) {
            $this->logger->warning('GitHub contributions page could not be read.', ['login' => $login]);
        }

        return $grid;
    }

    /**
     * @return array{total: int, days: list<array{date: string, level: int, count: int, row: int, col: int}>}|null
     */
    public static function parse(string $html): ?array
    {
        if ('' === mb_trim($html)) {
            return null;
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return null;
        }

        $xpath = new DOMXPath($document);

        $counts = [];
        foreach ($xpath->query('//tool-tip[@for]') ?: [] as $tip) {
            if (!$tip instanceof DOMElement) {
                continue;
            }

            $counts[$tip->getAttribute('for')] = 1 === preg_match('/^([\d,]+)\s+contribution/', mb_trim($tip->textContent), $match)
                ? (int) str_replace(',', '', $match[1])
                : 0;
        }

        $days = [];
        foreach ($xpath->query('//td[@data-date][@data-level]') ?: [] as $cell) {
            if (!$cell instanceof DOMElement) {
                continue;
            }

            $date = $cell->getAttribute('data-date');
            // id = contribution-day-component-{ligne}-{colonne}, ligne 0 = dimanche.
            if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }

            if (1 !== preg_match('/-(\d+)-(\d+)$/', $cell->getAttribute('id'), $position)) {
                continue;
            }

            $days[] = [
                'date' => $date,
                'level' => max(0, min(4, (int) $cell->getAttribute('data-level'))),
                'count' => $counts[$cell->getAttribute('id')] ?? 0,
                'row' => (int) $position[1],
                'col' => (int) $position[2],
            ];
        }

        if ([] === $days) {
            return null;
        }

        usort($days, static fn (array $a, array $b): int => [$a['col'], $a['row']] <=> [$b['col'], $b['row']]);

        return [
            'total' => array_sum(array_column($days, 'count')),
            'days' => $days,
        ];
    }
}
