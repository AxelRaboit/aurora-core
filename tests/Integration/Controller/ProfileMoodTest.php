<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Controller;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class ProfileMoodTest extends IntegrationTestCase
{
    private KernelBrowser $client;
    private UrlGeneratorInterface $urlGenerator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();

        $userRepository = static::getContainer()->get(UserRepository::class);
        $admin = $userRepository->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');

        $this->urlGenerator = static::getContainer()->get(UrlGeneratorInterface::class);
    }

    public function testSavingValidMessagePersistsAndReturnsIt(): void
    {
        [$status, $body] = $this->postJson('suite_general_profile_mood', [], ['moodMessage' => 'Shipping things ✨']);

        self::assertSame(200, $status);
        self::assertTrue($body['success']);
        self::assertSame('Shipping things ✨', $body['moodMessage']);
        self::assertSame('Shipping things ✨', $this->reloadAdmin()->getMoodMessage());
    }

    public function testEmptyStringClearsTheMoodMessage(): void
    {
        $this->postJson('suite_general_profile_mood', [], ['moodMessage' => 'set']);
        self::assertSame('set', $this->reloadAdmin()->getMoodMessage());

        [$status, $body] = $this->postJson('suite_general_profile_mood', [], ['moodMessage' => '   ']);

        self::assertSame(200, $status);
        self::assertTrue($body['success']);
        self::assertNull($body['moodMessage']);
        self::assertNull($this->reloadAdmin()->getMoodMessage());
    }

    public function testMessageOver160CharsIsRejected(): void
    {
        $tooLong = str_repeat('a', User::MOOD_MESSAGE_MAX_LENGTH + 1);

        [$status, $body] = $this->postJson('suite_general_profile_mood', [], ['moodMessage' => $tooLong]);

        self::assertSame(422, $status);
        self::assertFalse($body['success']);
        self::assertArrayHasKey('moodMessage', $body['errors']);
        self::assertNull($this->reloadAdmin()->getMoodMessage());
    }

    public function testMessageAtExactly160CharsIsAccepted(): void
    {
        $exact = str_repeat('a', User::MOOD_MESSAGE_MAX_LENGTH);

        [$status, $body] = $this->postJson('suite_general_profile_mood', [], ['moodMessage' => $exact]);

        self::assertSame(200, $status);
        self::assertTrue($body['success']);
        self::assertSame($exact, $this->reloadAdmin()->getMoodMessage());
    }

    public function testEntitySetterRejectsOverlongValue(): void
    {
        $user = new User();

        $this->expectException(InvalidArgumentException::class);
        $user->setMoodMessage(str_repeat('a', User::MOOD_MESSAGE_MAX_LENGTH + 1));
    }

    private function reloadAdmin(): User
    {
        $repository = static::getContainer()->get(UserRepository::class);
        // Clear identity map to force a fresh read after the request.
        $repository->getEntityManager()->clear();
        $admin = $repository->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $admin);

        return $admin;
    }

    /** @return array{0: int, 1: array} */
    private function postJson(string $route, array $routeParameters, array $payload): array
    {
        $this->client->request(
            HttpMethodEnum::Post->value,
            $this->urlGenerator->generate($route, $routeParameters),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload),
        );

        return [
            $this->client->getResponse()->getStatusCode(),
            json_decode((string) $this->client->getResponse()->getContent(), true) ?? [],
        ];
    }
}
