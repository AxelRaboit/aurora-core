<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Ged;

use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function implode;
use function in_array;
use function is_dir;
use function is_file;
use function preg_match_all;
use function sprintf;
use function str_contains;

/**
 * Ce que la fixture de la médiathèque promet, et qu'elle tenait à moitié.
 *
 * **Elle passe une définition dont le fichier manque au lieu d'échouer**, pour
 * qu'un renommage ne casse pas `make demo`. Le prix est qu'une démonstration
 * plus petite ne se signale pas : c'est à ce test de le dire.
 *
 * Il ne le disait que d'une moitié. Il lisait les clés `'src'` de la
 * bibliothèque et ignorait les clés `'file'` des documents, qui résolvaient
 * leur racine quatre niveaux au-dessus du dépôt au lieu de deux. Quinze
 * documents sur trente-trois n'avaient donc ni fichier, ni poids, ni vignette,
 * et rien ne l'a dit pendant des mois.
 *
 * D'où les deux garanties ci-dessous : la racine, puis les fichiers.
 */
final class DemoFixtureFilesExistTest extends TestCase
{
    /**
     * Les sources que la fixture nomme sans les livrer, volontairement.
     *
     * Une seule, et elle le mérite : trente secondes de vidéo pèsent dix-huit
     * mégaoctets dans un dépôt public, et une vidéo a forcément un sujet -
     * celle d'avant montrait un parc - ce dont la médiathèque de démonstration
     * est justement tenue à l'écart. Le reste est en aplats.
     */
    private const array DELIBERATELY_ABSENT = [
        'videos/sample-30s-720p.mp4',
    ];

    /**
     * Les deux chemins de code visent la même racine, et elle existe.
     *
     * **C'est la garantie qui manquait**, et elle est plus solide que de
     * compter les fichiers : `dirname(__DIR__, 4)` sortait du dépôt vers un
     * dossier qui n'existe sur aucune machine, ce qu'aucune liste de fichiers
     * ne pouvait révéler puisqu'ils étaient tous introuvables ensemble.
     */
    public function testEveryPathTheFixtureBuildsLandsInsideTheRepository(): void
    {
        preg_match_all(
            "/dirname\(__DIR__, (\d+)\)\.'\/test_files'/",
            $this->fixtureSource(),
            $matches,
        );

        self::assertNotEmpty($matches[1], 'La fixture ne construit plus aucun chemin vers test_files.');

        $wrong = [];

        // Le dossier depuis lequel la fixture compte, et non un chemin
        // relatif : `dirname` ne résout pas les `..` qu'on lui donne.
        $fixtureDir = dirname(__DIR__, 4).'/fixtures/Ged';

        foreach ($matches[1] as $levels) {
            $root = dirname($fixtureDir, (int) $levels);

            if (!is_dir($root.'/test_files')) {
                $wrong[] = sprintf('dirname(__DIR__, %s) -> %s', $levels, $root);
            }
        }

        self::assertSame([], $wrong, sprintf(
            'La fixture résout une racine qui ne contient pas test_files/ : %s. Elle passera alors chaque source en silence, et la médiathèque de démonstration sortira faite de lignes vides.',
            implode(', ', $wrong),
        ));
    }

    /**
     * Chaque fichier nommé est livré, ou peut être dessiné.
     *
     * C'est le contrat de la fixture, écrit ici plutôt que sous-entendu : une
     * source absente n'est acceptable que si la définition porte une largeur,
     * auquel cas un aplat prend sa place. Sans largeur - le cas d'un PDF - une
     * source introuvable laisse une ligne sans fichier, ce qu'on refuse.
     */
    public function testEveryNamedSourceIsShippedOrCanBeDrawn(): void
    {
        $source = $this->fixtureSource();
        $root = dirname(__DIR__, 4).'/test_files/';

        preg_match_all("/'(src|file)' => '([^']+)'([^\\]]*)/", $source, $matches, PREG_SET_ORDER);
        self::assertNotEmpty($matches, 'La fixture ne nomme plus aucune source.');

        $missing = [];

        foreach ($matches as [, , $relative, $rest]) {
            // Un chemin de source porte un dossier. Un nom nu est celui d'un
            // fichier que la fixture écrit elle-même - les versions passées
            // d'un document, dessinées sur place - et non d'une source à lire.
            if (!str_contains($relative, '/')) {
                continue;
            }

            if (in_array($relative, self::DELIBERATELY_ABSENT, true) || is_file($root.$relative)) {
                continue;
            }

            // Un aplat tient lieu de source quand la définition dit sa taille.
            if (str_contains($rest, "'w' =>")) {
                continue;
            }

            $missing[] = $relative;
        }

        self::assertSame([], $missing, sprintf(
            'La fixture nomme des fichiers qui ne sont ni dans test_files/ ni dessinables faute de largeur : %s. Ils donneront des documents sans pièce jointe, en silence.',
            implode(', ', $missing),
        ));
    }

    private function fixtureSource(): string
    {
        $source = file_get_contents(dirname(__DIR__, 4).'/fixtures/Ged/GedDemoFixtures.php');
        self::assertIsString($source);

        return $source;
    }
}
