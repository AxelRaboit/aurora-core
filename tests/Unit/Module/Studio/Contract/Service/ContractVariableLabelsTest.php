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
 * Every token of the catalogue has a label, in every back office language.
 *
 * The label key is derived from the token, which avoids a lookup table but
 * warns of nothing: the twelve `provider.*` tokens and the three
 * `contract.amends_*` ones had no translation, and the panel showed their raw
 * key, `suite.studio.contract_templates.variables
 * .provider_name`, next to its sample value. Nobody had noticed because the
 * panel is skimmed.
 *
 * Spanish is out of scope here: the Spanish back office falls back to French,
 * and its file only covers the public pages.
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
