<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Configuration\Theme;

use Aurora\Module\Configuration\Theme\Entity\Theme;
use Aurora\Module\Configuration\Theme\Serializer\ThemeSerializerInterface;
use Aurora\Module\Configuration\Theme\Service\ThemeContext;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;

use function bin2hex;
use function random_bytes;

/**
 * The themes screen shows the header logo a theme points at, picked in the
 * library, rather than asking for a document number to type.
 */
final class ThemeHeaderLogoTest extends IntegrationTestCase
{
    private ?Document $document = null;

    protected function tearDown(): void
    {
        if (null !== $this->document) {
            $entityManager = static::getContainer()->get(EntityManagerInterface::class);
            $managed = $entityManager->find(Document::class, $this->document->getId());
            if (null !== $managed) {
                $entityManager->remove($managed);
                $entityManager->flush();
            }
        }

        parent::tearDown();
    }

    public function testASerializedThemeCarriesTheAddressOfItsHeaderLogo(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $name = bin2hex(random_bytes(8)).'.png';
        $this->document = new Document();
        $this->document->setTitle('Logo '.bin2hex(random_bytes(3)))
            ->setOriginalName('logo.png')
            ->setFilePath('ged/2026/09/'.$name)
            ->setMimeType('image/png')
            ->setStatus(DocumentStatusEnum::Published);
        $entityManager->persist($this->document);
        $entityManager->flush();

        $theme = new Theme();
        $theme->setSlug('essai')->setName('Essai')->setConfig(['header_logo_media_id' => (string) $this->document->getId()]);

        $serialized = static::getContainer()->get(ThemeSerializerInterface::class)->serialize($theme);

        self::assertNotNull($serialized['headerLogoUrl']);
        self::assertStringContainsString($name, $serialized['headerLogoUrl']);
    }

    public function testAThemeWithoutLogoHasNoAddress(): void
    {
        self::assertNull(static::getContainer()->get(ThemeContext::class)->headerLogoUrlFor(new Theme()));
    }
}
