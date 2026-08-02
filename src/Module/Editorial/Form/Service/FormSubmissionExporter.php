<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\Service;

use Aurora\Module\Editorial\Form\Entity\FormInterface;
use Aurora\Module\Editorial\Form\Repository\FormSubmissionRepository;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams every submission of a form as CSV.
 *
 * Streamed rather than built in memory: an export is the one place where a
 * popular form's whole history is read at once.
 */
final readonly class FormSubmissionExporter
{
    /**
     * Characters that make a spreadsheet treat a cell as a formula.
     *
     * A public form is exactly how a hostile value gets in: anyone can type
     * `=HYPERLINK(...)` into a text field, and it runs when a colleague opens
     * the export. The reference wrote every value through untouched. Prefixing
     * with a quote is the standard neutralisation — the cell still reads as
     * what was typed, it just is not evaluated.
     *
     * @var list<string>
     */
    private const array FORMULA_TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    public function __construct(
        private FormSubmissionRepository $submissionRepository,
        private FormFieldLabeler $labeler,
    ) {}

    public function toCsv(FormInterface $form, string $locale): StreamedResponse
    {
        $submissions = $this->submissionRepository->findAllByForm($form);
        $fields = array_values($form->getFields()->toArray());

        $header = ['reference', 'submitted_at', 'locale', 'ip'];
        foreach ($fields as $field) {
            $header[] = $this->labeler->label($field, $locale);
        }

        $response = new StreamedResponse(function () use ($submissions, $fields, $header): void {
            $handle = fopen('php://output', 'w');
            if (false === $handle) {
                return;
            }

            // The byte-order mark is what makes Excel read the file as UTF-8.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $header, ';', escape: '\\');

            foreach ($submissions as $submission) {
                $row = [
                    (string) $submission->getReference(),
                    $submission->getSubmittedAt()->format('Y-m-d H:i:s'),
                    $submission->getLocale(),
                    (string) $submission->getIp(),
                ];

                foreach ($fields as $field) {
                    $value = $submission->getData()[(string) $field->getId()] ?? '';
                    $row[] = $this->neutralise(is_array($value) ? implode(', ', $value) : (string) $value);
                }

                fputcsv($handle, $row, ';', escape: '\\');
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', sprintf(
            'attachment; filename="%s-%s.csv"',
            $this->labeler->slug($form, $locale),
            date('Y-m-d'),
        ));

        return $response;
    }

    private function neutralise(string $value): string
    {
        foreach (self::FORMULA_TRIGGERS as $trigger) {
            if (str_starts_with($value, $trigger)) {
                return "'".$value;
            }
        }

        return $value;
    }
}
