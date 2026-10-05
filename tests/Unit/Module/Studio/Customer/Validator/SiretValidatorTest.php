<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Studio\Customer\Validator;

use Aurora\Module\Studio\Customer\Validator\Siret;
use Aurora\Module\Studio\Customer\Validator\SiretValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * The checksum is the point of this validator.
 *
 * Fourteen digits is the easy half and catches nothing that matters: the
 * mistakes that reach a signed contract keep the length. Both real numbers
 * below are checked because a checksum implementation that rejects valid
 * numbers is worse than none - it stops work with no way around it.
 */
final class SiretValidatorTest extends ConstraintValidatorTestCase
{
    public static function validNumbers(): iterable
    {
        // The registry's own textbook example.
        yield 'reference number' => ['73282932000074'];
        // A real one, from the provider block of the contracts this feeds.
        yield 'sole trader' => ['10707150800017'];
    }

    public static function invalidNumbers(): iterable
    {
        yield 'too short' => ['1070715080001'];
        yield 'too long' => ['107071508000170'];
        yield 'letters' => ['1070715080001A'];
        // The reference number with two digits transposed: same length, same
        // digits, and exactly the typo the checksum exists to catch.
        yield 'transposed digits' => ['73282932000047'];
        yield 'all zeroes but one' => ['00000000000001'];
    }

    #[DataProvider('validNumbers')]
    public function testAValidNumberPasses(string $siret): void
    {
        $this->validator->validate($siret, new Siret());

        $this->assertNoViolation();
    }

    #[DataProvider('invalidNumbers')]
    public function testAnInvalidNumberIsRefused(string $siret): void
    {
        $this->validator->validate($siret, new Siret());

        $this->buildViolation('suite.studio.customers.errors.siret_invalid')
            ->assertRaised();
    }

    /**
     * The field is optional, so an empty one is not an invalid one - saying
     * otherwise would make every prospect without a number unsavable.
     */
    public function testAnAbsentNumberIsNotAnError(): void
    {
        $this->validator->validate(null, new Siret());
        $this->validator->validate('', new Siret());

        $this->assertNoViolation();
    }

    protected function createValidator(): SiretValidator
    {
        return new SiretValidator();
    }
}
