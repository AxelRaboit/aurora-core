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
 * The assertion a service account signs.
 *
 * **Verified for real, not compared to an expected string.** A real RSA key is
 * made here, the assertion is signed with it, and the signature is verified
 * with the matching public key: it is the only check that says Google would
 * accept it. Comparing the token to a literal would only prove it has not
 * changed.
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
     * `aud` is the token exchange endpoint and not the Drive address. The
     * mix-up is common and gives a refusal with no useful explanation.
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

    /** One hour, the most Google accepts. */
    public function testTheAssertionLastsAnHourAtMost(): void
    {
        $claims = $this->json(explode('.', $this->account()->assertion('x', now: 0))[1]);

        self::assertSame(3600, $claims['exp'] - $claims['iat']);
    }

    /**
     * A `+` or a `/` left as they are make an assertion Google rejects once in
     * a thousand, depending on the signed content.
     */
    public function testTheSegmentsAreUrlSafeAndUnpadded(): void
    {
        $assertion = $this->account()->assertion('https://www.googleapis.com/auth/drive.readonly');

        self::assertStringNotContainsString('+', $assertion);
        self::assertStringNotContainsString('/', $assertion);
        self::assertStringNotContainsString('=', $assertion);
    }

    /** A file that is not a service account key is not treated as one. */
    public function testAnythingThatIsNotAServiceAccountKeyIsRefused(): void
    {
        self::assertNull(GoogleServiceAccount::fromJson('pas du json'));
        self::assertNull(GoogleServiceAccount::fromJson('{}'));
        self::assertNull(GoogleServiceAccount::fromJson((string) json_encode([
            // An application OAuth key, downloaded from the same place and not
            // meant for this.
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
