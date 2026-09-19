<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Studio\SpaceFile\GoogleDrive;

use Aurora\Module\Studio\SpaceFile\GoogleDrive\Service\GoogleServiceAccount;
use PHPUnit\Framework\TestCase;

use function base64_decode;
use function explode;
use function json_decode;
use function json_encode;
use function mb_str_pad;
use function openssl_pkey_export;
use function openssl_pkey_new;
use function openssl_verify;
use function strtr;

use const OPENSSL_ALGO_SHA256;
use const STR_PAD_RIGHT;

/**
 * L'assertion qu'un compte de service signe.
 *
 * **Vérifiée pour de vrai, pas comparée à une chaîne attendue.** Une vraie clé
 * RSA est fabriquée ici, l'assertion est signée avec, et la signature est
 * vérifiée avec la clé publique correspondante : c'est le seul contrôle qui
 * dise que Google l'accepterait. Comparer le jeton à un littéral prouverait
 * seulement qu'il n'a pas changé.
 */
final class GoogleServiceAccountTest extends TestCase
{
    /** @var array{public: string, private: string} */
    private array $keys;

    protected function setUp(): void
    {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($resource);

        $private = '';
        openssl_pkey_export($resource, $private);

        $details = openssl_pkey_get_details($resource);
        self::assertIsArray($details);

        $this->keys = ['private' => $private, 'public' => (string) $details['key']];
    }

    private function account(): GoogleServiceAccount
    {
        $account = GoogleServiceAccount::fromJson((string) json_encode([
            'type' => 'service_account',
            'client_email' => 'aurora@projet.iam.gserviceaccount.com',
            'private_key' => $this->keys['private'],
        ]));

        self::assertNotNull($account);

        return $account;
    }

    public function testTheSignatureVerifiesAgainstThePublicKey(): void
    {
        $assertion = $this->account()->assertion('https://www.googleapis.com/auth/drive.readonly');

        [$header, $claims, $signature] = explode('.', $assertion);

        self::assertSame(
            1,
            openssl_verify(
                $header.'.'.$claims,
                $this->decode($signature),
                $this->keys['public'],
                OPENSSL_ALGO_SHA256,
            ),
        );
    }

    /**
     * `aud` vaut le point d'échange et non l'adresse du Drive. La confusion
     * est courante et donne un refus sans explication utile.
     */
    public function testTheClaimsAreTheOnesGoogleAsksFor(): void
    {
        $assertion = $this->account()->assertion('https://www.googleapis.com/auth/drive.readonly', now: 1_700_000_000);

        [$header, $claims] = explode('.', $assertion);

        self::assertSame(['alg' => 'RS256', 'typ' => 'JWT'], $this->json($header));
        self::assertSame([
            'iss' => 'aurora@projet.iam.gserviceaccount.com',
            'scope' => 'https://www.googleapis.com/auth/drive.readonly',
            'aud' => GoogleServiceAccount::TOKEN_URI,
            'iat' => 1_700_000_000,
            'exp' => 1_700_003_600,
        ], $this->json($claims));
    }

    /** Une heure, ce que Google accepte au maximum. */
    public function testTheAssertionLastsAnHourAtMost(): void
    {
        $claims = $this->json(explode('.', $this->account()->assertion('x', now: 0))[1]);

        self::assertSame(3600, $claims['exp'] - $claims['iat']);
    }

    /**
     * Un `+` ou un `/` laissés tels quels font une assertion que Google
     * rejette une fois sur mille, au gré du contenu signé.
     */
    public function testTheSegmentsAreUrlSafeAndUnpadded(): void
    {
        $assertion = $this->account()->assertion('https://www.googleapis.com/auth/drive.readonly');

        self::assertStringNotContainsString('+', $assertion);
        self::assertStringNotContainsString('/', $assertion);
        self::assertStringNotContainsString('=', $assertion);
    }

    /** Un fichier qui n'est pas une clé de compte de service ne l'est pas. */
    public function testAnythingThatIsNotAServiceAccountKeyIsRefused(): void
    {
        self::assertNull(GoogleServiceAccount::fromJson('pas du json'));
        self::assertNull(GoogleServiceAccount::fromJson('{}'));
        self::assertNull(GoogleServiceAccount::fromJson((string) json_encode([
            // Une clé OAuth d'application, qu'on télécharge au même endroit et
            // qui ne sert pas à ça.
            'type' => 'authorized_user',
            'client_email' => 'x@y.z',
            'private_key' => $this->keys['private'],
        ])));
    }

    private function decode(string $segment): string
    {
        return (string) base64_decode(strtr(mb_str_pad($segment, (int) (4 * ceil(mb_strlen($segment) / 4)), '=', STR_PAD_RIGHT), '-_', '+/'), true);
    }

    /** @return array<string, mixed> */
    private function json(string $segment): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($this->decode($segment), true);

        return $decoded;
    }
}
