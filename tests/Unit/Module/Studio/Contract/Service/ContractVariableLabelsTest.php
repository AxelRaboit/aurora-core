<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Studio\Contract\Service;

use Aurora\Module\Studio\Contract\Service\ContractVariableCatalogue;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

use function array_key_exists;
use function dirname;
use function is_array;
use function sprintf;
use function str_replace;

/**
 * Chaque jeton du catalogue a un libellé, dans chaque langue du back-office.
 *
 * La clé de libellé est dérivée du jeton, ce qui évite une table de
 * correspondance mais ne prévient de rien : les douze jetons `provider.*` et
 * les trois `contract.amends_*` n'avaient aucune traduction, et le panneau
 * affichait leur clé brute, `suite.studio.contract_templates.variables
 * .provider_name`, à côté de sa valeur d'exemple. Personne ne l'avait vu
 * parce que le panneau se lit en diagonale.
 *
 * L'espagnol est hors sujet ici : le back-office espagnol retombe sur le
 * français, et son fichier ne couvre que les pages publiques.
 */
final class ContractVariableLabelsTest extends TestCase
{
    private const array LOCALES = ['fr', 'en'];

    public function testEveryVariableHasALabelInEveryBackofficeLocale(): void
    {
        $tokens = $this->tokens();

        self::assertNotEmpty($tokens, 'le catalogue ne déclare aucun jeton');

        foreach (self::LOCALES as $locale) {
            $labels = $this->labels($locale);

            foreach ($tokens as $token) {
                $key = str_replace('.', '_', $token);

                self::assertTrue(
                    array_key_exists($key, $labels),
                    sprintf('le jeton {{%s}} n\'a pas de libellé en %s : le panneau afficherait sa clé', $token, $locale),
                );
            }
        }
    }

    /** @return list<string> */
    private function tokens(): array
    {
        $tokens = [];

        foreach ((new ContractVariableCatalogue())->groups() as $group) {
            foreach ($group['variables'] as $variable) {
                $tokens[] = $variable['token'];
            }
        }

        return $tokens;
    }

    /** @return array<string, mixed> */
    private function labels(string $locale): array
    {
        $file = dirname(__DIR__, 6).'/src/Module/Studio/translations/messages.'.$locale.'.yaml';
        $parsed = Yaml::parseFile($file);
        $labels = $parsed['suite']['studio']['contract_templates']['variables'] ?? null;

        self::assertTrue(is_array($labels), sprintf('le bloc des variables est absent en %s', $locale));

        return $labels;
    }
}
