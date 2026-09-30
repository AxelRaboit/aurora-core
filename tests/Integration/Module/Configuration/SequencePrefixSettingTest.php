<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Configuration;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function json_decode;

/**
 * A numbering prefix goes into every reference it numbers: a contract's
 * `CTR-2026-0001`, its PDF's file name, the subject of its mails. Anything
 * used to be accepted, a space or a slash included.
 */
final class SequencePrefixSettingTest extends IntegrationTestCase
{
    private const string KEY = ApplicationParameterEnum::StudioContractPrefix->value;

    private KernelBrowser $client;

    private SettingRepository $settings;

    private ?string $original = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $dev = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        $this->client->loginUser($dev, 'admin');

        $this->settings = static::getContainer()->get(SettingRepository::class);
        $this->original = $this->settings->get(self::KEY);
    }

    protected function tearDown(): void
    {
        $this->settings->set(self::KEY, $this->original);

        parent::tearDown();
    }

    public function testAPrefixWithASpaceOrASlashIsRefused(): void
    {
        foreach (['CM 26', 'CM/', '', 'TROPLONGPREFIXE'] as $value) {
            $this->client->jsonRequest('POST', '/backend/configuration/settings/update', ['key' => self::KEY, 'value' => $value]);

            self::assertSame(400, $this->client->getResponse()->getStatusCode(), $value);
            self::assertSame('invalid_prefix', json_decode((string) $this->client->getResponse()->getContent(), true)['error']);
        }

        self::assertSame($this->original, $this->settings->get(self::KEY));
    }

    public function testAPrefixIsKeptInCapitals(): void
    {
        $this->client->jsonRequest('POST', '/backend/configuration/settings/update', ['key' => self::KEY, 'value' => ' cm ']);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('CM', json_decode((string) $this->client->getResponse()->getContent(), true)['value']);
        self::assertSame('CM', $this->settings->get(self::KEY));
    }
}
