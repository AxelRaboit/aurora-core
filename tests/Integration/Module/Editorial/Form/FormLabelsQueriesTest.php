<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Form;

use Aurora\Module\Editorial\Form\Entity\Form;
use Aurora\Module\Editorial\Form\Entity\FormField;
use Aurora\Module\Editorial\Form\Entity\FormSubmission;
use Aurora\Module\Editorial\Form\Enum\FormFieldTypeEnum;
use Aurora\Module\Editorial\Form\Repository\FormSubmissionRepository;
use Aurora\Module\Editorial\Form\Serializer\FormSerializerInterface;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;

use function array_filter;
use function array_values;
use function bin2hex;
use function preg_match;
use function random_bytes;

/**
 * A form's answers are labelled without reading each field's label alone.
 *
 * The list of answers, the export and the mail of an answer all label every
 * value with its field's name, and each field's translations were loaded on
 * their own. The form now comes with them.
 */
final class FormLabelsQueriesTest extends IntegrationTestCase
{
    private EntityManagerInterface $entityManager;

    private ?int $formId = null;

    protected function setUp(): void
    {
        parent::setUp();
        static::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        if (null !== $this->formId) {
            $form = $this->entityManager->find(Form::class, $this->formId);
            if (null !== $form) {
                $this->entityManager->remove($form);
                $this->entityManager->flush();
            }
        }

        parent::tearDown();
    }

    public function testTheAnswersListReadsNoLabelAlone(): void
    {
        $form = $this->givenForm();
        $this->entityManager->clear();

        $form = $this->entityManager->find(Form::class, $this->formId);
        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();

        $page = static::getContainer()->get(FormSubmissionRepository::class)->findPaginatedByForm($form, 1, 20);
        $serializer = static::getContainer()->get(FormSerializerInterface::class);
        $rows = array_map(static fn ($submission): array => $serializer->serializeSubmission($submission, 'fr'), $page['items']);

        self::assertCount(4, $rows);
        self::assertSame('Votre nom', $rows[0]['pairs'][0]['label']);

        // `t0` is the alias of Doctrine's one-row loads; the warm-up is DQL.
        $alone = array_filter(
            $holder->getData()['default'] ?? [],
            static fn (array $query): bool => 1 === preg_match('/FROM (core_form_fields|core_form_field_translations) t0 /', (string) $query['sql']),
        );
        self::assertSame([], array_values($alone), 'no field or label is read on its own');
    }

    private function givenForm(): Form
    {
        $form = new Form();
        $form->setActive(true);
        $form->translate('fr')->setTitle('Contact')->setSlug('contact-'.bin2hex(random_bytes(4)));

        $fields = [];
        foreach (['Votre nom', 'Votre e-mail', 'Votre message'] as $position => $label) {
            $field = new FormField();
            $form->addField($field);
            $field->setType(FormFieldTypeEnum::Text)->setRequired(false)->setPosition($position);
            $field->translate('fr')->setLabel($label);
            $fields[] = $field;
        }

        $this->entityManager->persist($form);
        foreach ($fields as $field) {
            $this->entityManager->persist($field);
        }
        $this->entityManager->flush();
        $this->formId = (int) $form->getId();

        for ($submissionIndex = 0; $submissionIndex < 4; ++$submissionIndex) {
            $data = [];
            foreach ($fields as $field) {
                $data[(string) $field->getId()] = 'Réponse '.$submissionIndex;
            }
            $submission = new FormSubmission();
            $submission->setForm($form)->setData($data)->setLocale('fr');
            $this->entityManager->persist($submission);
        }
        $this->entityManager->flush();

        return $form;
    }
}
