<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Controller;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Enum\HttpStatusEnum;
use Aurora\Core\Storage\Access\UploadAccessDecider;
use Aurora\Core\Storage\Access\UploadAccessEnum;
use Aurora\Core\Storage\Service\UploadUrlGenerator;
use Aurora\Module\Ged\Document\Entity\Document;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Stable canonical URL for a GED document: /document/{id} always redirects
 * to the current file regardless of renames, re-uploads or future crops.
 * Mirrors MediaViewController - the permalink stays valid even when the
 * underlying file path changes.
 *
 * The id is a sequence, so this address is enumerable by anyone who can
 * count, and it is asked the same question `/uploads/{path}` is asked before
 * it answers. Asked *here* rather than left to the redirect target, even
 * though that target would now refuse too: a 302 states the file's path and
 * that the id names something, and both are what counting is for.
 */
class DocumentViewController extends AbstractController
{
    public function __construct(
        protected readonly UploadUrlGenerator $uploadUrlGenerator,
        protected readonly UploadAccessDecider $accessDecider,
    ) {}

    #[Route(
        '/document/{id}',
        name: 'ged_document_view',
        requirements: ['id' => '\d+'],
        methods: [HttpMethodEnum::Get->value],
    )]
    public function view(Document $document): RedirectResponse
    {
        $filePath = $document->getFilePath();
        $url = $this->uploadUrlGenerator->publicUrl($filePath);
        if (null === $url || null === $filePath) {
            throw new NotFoundHttpException();
        }

        // The decider, not the status column, so this permalink and the file
        // it points at can never disagree: one rule, asked twice.
        if (UploadAccessEnum::Denied === $this->accessDecider->decide($filePath)) {
            throw new NotFoundHttpException();
        }

        return $this->redirect(
            $url.'?v='.$document->getUpdatedAt()->getTimestamp(),
            HttpStatusEnum::Found->value,
        );
    }
}
