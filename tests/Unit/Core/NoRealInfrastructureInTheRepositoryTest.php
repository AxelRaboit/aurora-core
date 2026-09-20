<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function dirname;
use function file_get_contents;
use function implode;
use function in_array;
use function preg_match_all;
use function sprintf;
use function str_contains;

/**
 * Ce dépôt est public : rien n'y nomme une infrastructure réelle.
 *
 * **La règle existait, et rien ne la tenait.** Un identifiant de vrai dossier
 * Google Drive est entré dans les fixtures et dans un test, relevé d'un écran
 * de réglages pendant une session de travail, et il y est resté jusqu'à ce
 * qu'un œil le remarque - après avoir été poussé, tagué et publié.
 *
 * Un identifiant de dossier n'ouvre rien à lui seul : il faut être partagé
 * pour le lire. Ce n'est donc pas une fuite de secret, c'est une trace : il
 * nomme une infrastructure qui appartient à quelqu'un, dans un dépôt que
 * n'importe qui lit.
 *
 * Le test ne cherche pas « un secret », ce qui serait sans fin. Il cherche les
 * deux formes précises qui sont déjà passées, et il est fait pour grandir
 * quand une troisième passera.
 */
final class NoRealInfrastructureInTheRepositoryTest extends TestCase
{
    /**
     * Les dossiers qui décrivent le produit, par opposition à ceux qui le
     * configurent.
     *
     * `config/` et `.env` portent des valeurs de déploiement par nature ; ce
     * qu'on surveille ici est le code, les fixtures et les tests, où une
     * valeur réelle n'a aucune raison d'apparaître.
     */
    private const array SCANNED = ['src', 'fixtures', 'tests', 'tools'];

    /**
     * Ce qui ressemble à un identifiant Google, et les formes qu'on accepte.
     *
     * Un identifiant Drive fait au moins vingt-cinq caractères de base64 sans
     * signification. Les nôtres en ont une - ils se lisent - donc ils ne
     * déclenchent rien.
     */
    public function testNoGoogleDriveFolderIdentifierIsCommitted(): void
    {
        $found = [];
        $root = dirname(__DIR__, 3);

        foreach (self::SCANNED as $dir) {
            foreach ($this->filesIn($root.'/'.$dir) as $file) {
                $source = (string) file_get_contents($file->getPathname());

                // Un identifiant Drive tel que Google les frappe : au moins
                // vingt-cinq caractères, et au moins un chiffre ET une
                // majuscule ET une minuscule, ce qu'un mot français n'a pas.
                preg_match_all("/'([A-Za-z0-9_-]{25,})'/", $source, $matches);

                foreach ($matches[1] as $candidate) {
                    if ($this->looksGenerated($candidate)) {
                        $found[] = sprintf('%s → %s', $file->getFilename(), $candidate);
                    }
                }
            }
        }

        self::assertSame([], $found, sprintf(
            "Des identifiants d'apparence réelle sont dans le dépôt, qui est public : %s.\nUn identifiant de dossier n'ouvre rien à lui seul, mais il nomme une infrastructure qui appartient à quelqu'un. Utilisez un nom qui se lit, comme « dossier-de-demonstration-aurora ».",
            implode(', ', $found),
        ));
    }

    /** @return list<SplFileInfo> */
    private function filesIn(string $dir): array
    {
        $found = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
            if (!$file instanceof SplFileInfo || !in_array($file->getExtension(), ['php', 'mjs', 'js'], true)) {
                continue;
            }

            // `tools/` porte ses propres dépendances : ce qu'on y trouve
            // appartient à d'autres, et les sommes de contrôle que Composer y
            // écrit ressemblent à tout ce qu'on cherche.
            if (str_contains($file->getPathname(), '/vendor/')) {
                continue;
            }

            $found[] = $file;
        }

        return $found;
    }

    /**
     * Une suite frappée par une machine, et non un nom écrit par quelqu'un.
     *
     * Les trois classes de caractères ensemble, et aucun séparateur qui ferait
     * des mots : « dossier-de-demonstration-aurora » n'a ni chiffre ni
     * majuscule, « 1bbo9FyKEudNl7oeyPX-R5uX41_cPZLk3 » a les trois.
     */
    private function looksGenerated(string $candidate): bool
    {
        return 1 === preg_match('/[a-z]/', $candidate)
            && 1 === preg_match('/[A-Z]/', $candidate)
            && 1 === preg_match('/[0-9]/', $candidate)
            && !str_contains($candidate, ' ');
    }
}
