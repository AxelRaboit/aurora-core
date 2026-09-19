<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceFile\GoogleDrive\Service;

use InvalidArgumentException;
use JsonException;
use SensitiveParameter;

use function base64_encode;
use function is_string;
use function json_decode;
use function json_encode;
use function mb_rtrim;
use function openssl_sign;
use function strtr;
use function time;

use const JSON_THROW_ON_ERROR;
use const OPENSSL_ALGO_SHA256;

/**
 * La clé d'un compte de service Google, et l'assertion qu'elle signe.
 *
 * **Un compte de service plutôt qu'OAuth, et ça change tout.** Le chemin
 * habituel vers un Drive demande à une personne d'autoriser l'application dans
 * son navigateur : un écran de consentement, une route de retour, un jeton de
 * rafraîchissement à garder vivant, et une réautorisation le jour où il
 * expire. Un compte de service n'a rien de tout cela - il a une adresse, et le
 * client partage un dossier avec elle depuis son Drive, comme il partagerait
 * avec un collègue.
 *
 * C'est la même forme que la connexion Craft, et pour la même raison : **la
 * portée se décide chez le fournisseur**, pas dans Aurora. Ce qui n'est pas
 * partagé n'existe pas pour ce compte, et retirer le partage referme la porte
 * sans toucher à la configuration.
 *
 * **La signature est faite ici plutôt qu'empruntée.** `google/apiclient`
 * ferait le travail, et amènerait des centaines de définitions de services
 * dans un dépôt public livré à des clients pour une assertion de trois champs.
 * Ce que Google demande tient en une phrase : un JWT RS256 portant `iss`,
 * `scope`, `aud`, `iat` et `exp`, échangé contre un jeton d'accès. `openssl`
 * fait le reste.
 */
final readonly class GoogleServiceAccount
{
    public const string TOKEN_URI = 'https://oauth2.googleapis.com/token';

    /** Une heure au maximum, et Google refuse au-delà. */
    private const int LIFETIME_SECONDS = 3600;

    private function __construct(
        public string $email,
        #[SensitiveParameter]
        private string $privateKey,
    ) {}

    /**
     * Lit la clé telle que Google la livre : un JSON téléchargé une fois, que
     * personne ne retape.
     *
     * Null plutôt qu'une exception quand le contenu n'est pas une clé de
     * compte de service : l'intégration se tait, et l'écran de réglages
     * redemande le fichier - ce qui est la seule chose qui répare.
     */
    public static function fromJson(#[SensitiveParameter] string $json): ?self
    {
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $email = $decoded['client_email'] ?? null;
        $key = $decoded['private_key'] ?? null;

        if ('service_account' !== ($decoded['type'] ?? null) || !is_string($email) || !is_string($key)) {
            return null;
        }

        return new self($email, $key);
    }

    /**
     * L'assertion signée que Google échange contre un jeton d'accès.
     *
     * `aud` vaut l'adresse du point d'échange et non celle du Drive : c'est un
     * détail que la documentation répète, et le confondre donne un refus sans
     * explication utile.
     *
     * @throws InvalidArgumentException quand la clé ne signe pas - une clé
     *                                  tronquée au copier-coller, typiquement
     */
    public function assertion(string $scope, ?int $now = null): string
    {
        $issuedAt = $now ?? time();

        $header = $this->segment(['alg' => 'RS256', 'typ' => 'JWT']);
        $claims = $this->segment([
            'iss' => $this->email,
            'scope' => $scope,
            'aud' => self::TOKEN_URI,
            'iat' => $issuedAt,
            'exp' => $issuedAt + self::LIFETIME_SECONDS,
        ]);

        $signature = '';

        if (!openssl_sign($header.'.'.$claims, $signature, $this->privateKey, OPENSSL_ALGO_SHA256)) {
            throw new InvalidArgumentException('The service account key did not sign.');
        }

        return $header.'.'.$claims.'.'.$this->base64Url($signature);
    }

    /** @param array<string, mixed> $payload */
    private function segment(array $payload): string
    {
        return $this->base64Url(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * Base64 de l'URL, telle que JWT la veut : sans remplissage, et deux
     * caractères remplacés. Un `+` ou un `/` laissés tels quels font une
     * assertion que Google rejette une fois sur mille, au gré du contenu.
     */
    private function base64Url(string $raw): string
    {
        return mb_rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
