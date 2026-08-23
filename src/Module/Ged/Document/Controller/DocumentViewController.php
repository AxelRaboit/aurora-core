<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Controller;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Enum\HttpStatusEnum;
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
 */
class DocumentViewController extends AbstractController
{
    public function __construct(
        protected readonly UploadUrlGenerator $uploadUrlGenerator,
    ) {}

    #[Route(
        '/document/{id}',
        name: 'ged_document_view',
        requirements: ['id' => '\d+'],
        methods: [HttpMethodEnum::Get->value],
    )]
    public function view(Document $document): RedirectResponse
    {
        $url = $this->uploadUrlGenerator->publicUrl($document->getFilePath());
        if (null === $url) {
            throw new NotFoundHttpException();
        }

        return $this->redirect(
            $url.'?v='.$document->getUpdatedAt()->getTimestamp(),
            HttpStatusEnum::Found->value,
        );
    }
}
