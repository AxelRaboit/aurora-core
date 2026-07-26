<?php

declare(strict_types=1);

namespace Aurora\Module\Billing\Ocr\Serializer;

use Aurora\Core\Storage\Service\UploadUrlGenerator;
use Aurora\Module\Billing\Invoice\Entity\TiersInterface;
use Aurora\Module\Billing\Invoice\Repository\InvoiceRepository;
use Aurora\Module\Billing\Ocr\Entity\OcrJobInterface;
use DateTimeInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsAlias(OcrJobSerializerInterface::class)]
class OcrJobSerializer implements OcrJobSerializerInterface
{
    public function __construct(
        protected readonly TranslatorInterface $translator,
        protected readonly InvoiceRepository $invoiceRepository,
        protected readonly UploadUrlGenerator $uploadUrlGenerator,
    ) {}

    public function serialize(OcrJobInterface $job): array
    {
        $status = $job->getStatus();
        $document = $job->getDocument();
        $invoice = $this->invoiceRepository->findOneBy(['ocrJob' => $job]);

        return [
            'id' => $job->getId(),
            // File metadata sourced from the linked GED Document. The keys
            // are kept as `fileName / mediaUrl / mediaMime` for Vue
            // backwards-compat — the consumer doesn't care that the
            // underlying source switched from Media to GED.
            'fileName' => $document->getOriginalName(),
            'mediaUrl' => $this->uploadUrlGenerator->publicUrl($document->getFilePath()),
            'mediaMime' => $document->getMimeType(),
            'documentId' => $document->getId(),
            'status' => $status->value,
            'statusLabel' => $this->translator->trans($status->getLabelKey()),
            'statusColor' => $status->getBadgeColor(),
            'isTerminal' => $status->isTerminal(),
            'progress' => $status->getProgress(),
            'modelUsed' => $job->getModelUsed(),
            'confidence' => $job->getConfidence(),
            'error' => $job->getError(),
            'invoiceId' => $invoice?->getId(),
            'invoiceStatus' => $invoice?->getStatus()->value,
            'invoiceCanValidate' => $invoice?->getStatus()->isEditable() ?? false,
            'invoiceCanDeleteTiers' => $invoice?->getStatus()->isDeletable()
                && $invoice->getTiers() instanceof TiersInterface
                && 1 === $this->invoiceRepository->countForTiers($invoice->getTiers()->getId()),
            'invoiceSupplierName' => $invoice?->getTiers()?->getName(),
            'logs' => $job->getLogs(),
            'createdAt' => $job->getCreatedAt()->format(DateTimeInterface::ATOM),
            'finishedAt' => $job->getFinishedAt()?->format(DateTimeInterface::ATOM),
        ];
    }
}
