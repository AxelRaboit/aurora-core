<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Enum\ThumbnailFitEnum;
use Aurora\Module\Editorial\Post\Service\ThumbnailPresenter;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The thumbnail's crop has two possible sources, and which one wins is the
 * whole of this class.
 *
 * The document's focal point is about the file - a face is in the same place
 * wherever that photo appears. The publication's is about *this* card, which
 * becomes a different question the moment a wide photo has to work in a narrow
 * frame.
 */
final class ThumbnailPresenterTest extends IntegrationTestCase
{
    private ThumbnailPresenter $presenter;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        static::bootKernel();

        $this->presenter = static::getContainer()->get(ThumbnailPresenter::class);
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testAPostWithNoThumbnailPresentsNoUrl(): void
    {
        $presented = $this->presenter->present($this->post());

        self::assertNull($presented['url']);
        self::assertSame('object-cover', $presented['objectFitClass'], 'the default, even with nothing to fit');
    }

    public function testTheFitBecomesTheClassATemplateCanUse(): void
    {
        foreach ([
            [ThumbnailFitEnum::Cover, 'object-cover'],
            [ThumbnailFitEnum::Contain, 'object-contain'],
            [ThumbnailFitEnum::Fill, 'object-fill'],
        ] as [$fit, $class]) {
            $post = $this->post();
            $post->setThumbnailFit($fit);

            self::assertSame($class, $this->presenter->present($post)['objectFitClass']);
            self::assertSame($fit->value, $this->presenter->present($post)['fit']);
        }
    }

    public function testWithoutAnOverrideTheDocumentsPointIsUsed(): void
    {
        $document = $this->document(0.25, 0.75);
        $post = $this->post($document);

        self::assertSame('25% 75%', $this->presenter->present($post)['focalPosition']);
    }

    public function testThePublicationsOwnPointWins(): void
    {
        $document = $this->document(0.25, 0.75);
        $post = $this->post($document);
        $post->setThumbnailFocal(0.1, 0.9);

        self::assertSame('10% 90%', $this->presenter->present($post)['focalPosition']);
    }

    /**
     * Half an override is not a position: letting one axis come from the
     * publication and the other from the document would put the crop somewhere
     * nobody chose.
     */
    public function testOneAxisAloneIsNotAnOverride(): void
    {
        $document = $this->document(0.25, 0.75);
        $post = $this->post($document);
        $post->setThumbnailFocal(0.1, null);

        self::assertNull($post->getThumbnailFocalX());
        self::assertSame('25% 75%', $this->presenter->present($post)['focalPosition']);
    }

    public function testClearingTheOverrideFallsBackToTheDocument(): void
    {
        $document = $this->document(0.25, 0.75);
        $post = $this->post($document);

        $post->setThumbnailFocal(0.1, 0.9);
        $post->setThumbnailFocal(null, null);

        self::assertSame('25% 75%', $this->presenter->present($post)['focalPosition']);
    }

    /** A coordinate outside the picture is not a point on it. */
    public function testAnOutOfRangeOverrideIsClampedToThePicture(): void
    {
        $post = $this->post($this->document(0.5, 0.5));
        $post->setThumbnailFocal(-2.0, 5.0);

        self::assertSame('0% 100%', $this->presenter->present($post)['focalPosition']);
    }

    public function testCentreIsTheAnswerWhenNobodySaysOtherwise(): void
    {
        self::assertSame('50% 50%', $this->presenter->present($this->post($this->document()))['focalPosition']);
    }

    private function post(?Document $thumbnail = null): Post
    {
        $postType = new PostType();
        $postType->setSlug('thumb-'.bin2hex(random_bytes(4)));
        $postType->setLabel('Thumb');

        $this->entityManager->persist($postType);

        $post = new Post();
        $post->setPostType($postType);
        $post->setThumbnail($thumbnail);

        return $post;
    }

    private function document(?float $focalX = null, ?float $focalY = null): Document
    {
        $document = new Document();
        $document->setTitle('Photo');
        $document->setFilePath('ged/2026/08/photo.jpg');
        $document->setFileName('photo.jpg');
        $document->setMimeType('image/jpeg');
        $document->setFocalX($focalX);
        $document->setFocalY($focalY);

        $this->entityManager->persist($document);

        return $document;
    }
}
