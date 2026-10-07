<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Core\Locale;

use Aurora\Core\Locale\Service\FrenchElision;
use Aurora\Core\Locale\Service\FrenchElisionTranslator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Yaml\Yaml;

use function is_string;

/**
 * "Livrables de {space}" read "Livrables de Atelier Dupont": the catalogue
 * cannot elide a name it has not seen yet, so the result is elided once the
 * parameters are in.
 */
final class FrenchElisionTest extends TestCase
{
    private const string SOURCES = __DIR__.'/../../../../src';

    /** @return iterable<string, array{string, string}> */
    public static function cases(): iterable
    {
        yield 'a name with a vowel' => ['Livrables de Atelier Dupont', "Livrables d'Atelier Dupont"];
        yield 'an accented vowel' => ['Retirer le rôle Dev de Émilie ?', "Retirer le rôle Dev d'Émilie ?"];
        yield 'a capital De' => ['De Anne', "D'Anne"];
        yield 'a consonant' => ['Équipe de Martin', 'Équipe de Martin'];
        yield 'y, which may not elide' => ['Demande de Yann', 'Demande de Yann'];
        yield 'h, which may not elide' => ['Contrat de Hélène', 'Contrat de Hélène'];
        yield 'a single letter' => ['Index de A à Z', 'Index de A à Z'];
        yield 'a word ending in de' => ['Mode avancé', 'Mode avancé'];
    }

    #[DataProvider('cases')]
    public function testTheRule(string $text, string $expected): void
    {
        self::assertSame($expected, FrenchElision::apply($text));
    }

    public function testTheTranslatorElidesFrenchOnly(): void
    {
        $inner = new Translator('fr');
        $inner->addLoader('array', new ArrayLoader());
        $inner->addResource('array', ['back' => 'Livrables de {space}'], 'fr');
        $inner->addResource('array', ['back' => 'Entregables de {space}'], 'es');

        $translator = new FrenchElisionTranslator($inner);

        self::assertSame("Livrables d'Atelier", $translator->trans('back', ['{space}' => 'Atelier']));
        self::assertSame('Entregables de Atelier', $translator->trans('back', ['{space}' => 'Atelier'], null, 'es'));
    }

    /**
     * The rule rests on this: no static French text has "de" before a vowel,
     * so only an inserted value can trigger it. A sentence added one day with
     * a legitimate "de" before a vowel would be rewritten without warning.
     */
    public function testNoStaticFrenchTextHasDeBeforeAVowel(): void
    {
        $files = new Finder()->files()->in(self::SOURCES)->path('translations')->name('messages.fr.yaml');
        $offending = [];

        foreach ($files as $file) {
            $catalogue = Yaml::parseFile($file->getPathname()) ?? [];

            array_walk_recursive($catalogue, static function (mixed $text, string|int $key) use (&$offending, $file): void {
                if (is_string($text) && FrenchElision::apply($text) !== $text) {
                    $offending[] = $file->getRelativePathname().' '.$key.': '.$text;
                }
            });
        }

        self::assertNotSame(0, $files->count());
        self::assertSame([], $offending);
    }
}
