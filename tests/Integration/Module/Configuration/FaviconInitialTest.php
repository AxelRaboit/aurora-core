<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Configuration;

use Aurora\Module\Configuration\Setting\Entity\Setting;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The letter in the browser tab belongs to the site, not to the product.
 *
 * This route drew a hard-coded "V" - the initial of the product's first name -
 * so every site ever delivered carried a stranger's letter in its tab, and
 * this one still did months after the rename. It now takes the site's own
 * initial, which is right for a product handed to clients: nobody has to
 * remember to change it.
 *
 * The kernel is deliberately not booted in setUp here: `createClient()` boots
 * its own, and the two cannot coexist.
 */
final class FaviconInitialTest extends IntegrationTestCase
{
    public function testItDrawsTheSitesOwnInitial(): void
    {
        $client = static::createClient();
        $this->setSiteName($client->getContainer()->get(EntityManagerInterface::class), 'Studio Nord');

        $client->request('GET', '/favicon.svg');
        $svg = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('>S<', $svg);
        self::assertStringNotContainsString('>V<', $svg);
    }

    /**
     * The address browsers ask for on their own answers too.
     *
     * No layout links to `/favicon.ico`; browsers fetch it anyway, and without
     * a route each request was a `NotFoundHttpException` logged at ERROR with
     * a stack trace - 2 071 of them in thirty-one days of production logs, for
     * a file nobody was missing. The point of this test is that the noise does
     * not come back the next time somebody tidies the routes.
     */
    public function testTheAddressBrowsersAskForOnTheirOwnAnswersToo(): void
    {
        $client = static::createClient();
        $this->setSiteName($client->getContainer()->get(EntityManagerInterface::class), 'Studio Nord');

        $client->request('GET', '/favicon.ico');

        self::assertResponseIsSuccessful();
        // The same answer as the `.svg` address, bytes included: browsers sniff
        // the content rather than trusting the extension, and serving a
        // different thing per extension is how the two drift apart.
        self::assertStringContainsString('>S<', (string) $client->getResponse()->getContent());
    }

    private function setSiteName(EntityManagerInterface $entityManager, string $name): void
    {
        $setting = $entityManager->getRepository(Setting::class)
            ->findOneBy(['key' => ApplicationParameterEnum::SiteName->value]);

        if (null === $setting) {
            $setting = new Setting();
            $setting->setKey(ApplicationParameterEnum::SiteName->value);
            $entityManager->persist($setting);
        }

        $setting->setValue($name);
        $entityManager->flush();
    }
}
