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
 * A Google service account key, and the assertion it signs.
 *
 * **A service account rather than OAuth, and that changes everything.** The
 * usual path to a Drive asks a person to authorise the application in their
 * browser: a consent screen, a callback route, a refresh token to keep alive,
 * and a re-authorisation the day it expires. A service account has none of
 * that - it has an address, and the client shares a folder with it from their
 * Drive, as they would share with a colleague.
 *
 * It is the same shape as the Craft connection, and for the same reason:
 * **the scope is decided at the provider**, not in Aurora. What is not shared
 * does not exist for this account, and removing the share closes the door
 * without touching the configuration.
 *
 * **The signature is made here rather than borrowed.** `google/apiclient`
 * would do the job, and would bring hundreds of service definitions into a
 * public repository shipped to clients for a three-field assertion. What
 * Google asks for fits in one sentence: an RS256 JWT carrying `iss`,
 * `scope`, `aud`, `iat` and `exp`, exchanged for an access token.
 * `openssl` does the rest.
 */
final readonly class GoogleServiceAccount
{
    public const string TOKEN_URI = 'https://oauth2.googleapis.com/token';

    /** One hour at most, and Google refuses beyond that. */
    private const int LIFETIME_SECONDS = 3600;

    private function __construct(
        public string $email,
        #[SensitiveParameter]
        private string $privateKey,
    ) {}

    /**
     * Reads the key as Google delivers it: a JSON downloaded once, which
     * nobody retypes.
     *
     * Null rather than an exception when the content is not a service account
     * key: the integration goes quiet, and the settings screen asks for the
     * file again - which is the only thing that fixes it.
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
     * The signed assertion Google exchanges for an access token.
     *
     * `aud` is the address of the exchange endpoint and not Drive's: it is a
     * detail the documentation repeats, and mixing them up gives a refusal
     * with no useful explanation.
     *
     * @throws InvalidArgumentException when the key does not sign - a key
     *                                  truncated by copy-paste, typically
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
     * URL-safe Base64, as JWT wants it: without padding, and two characters
     * replaced. A `+` or a `/` left as is make an assertion Google rejects
     * once in a thousand, depending on the content.
     */
    private function base64Url(string $raw): string
    {
        return mb_rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
