/**
 * Deux captures du même écran, l'une sombre l'autre claire, en une image.
 *
 * Le bandeau du sommaire dispose ses éléments sur les mêmes 48 colonnes que
 * le reste : y poser deux images côte à côte leur laisserait dix colonnes
 * chacune, soit deux cent cinquante pixels, où un tableau de bord n'est plus
 * qu'une texture. **Une seule image coupée en diagonale occupe la place
 * d'une et montre les deux**, et la coupure se lit d'un coup d'œil parce que
 * les deux moitiés sont le même écran.
 *
 * La composition se fait dans le navigateur qui a pris les captures, plutôt
 * qu'avec un outil d'image de plus : la diagonale est un `clip-path`, la
 * couture un dégradé, et le résultat se relit avec les mêmes yeux que le
 * reste du tour.
 *
 * Usage :
 *   node tools/screenshots/compose-themes.mjs <sombre> <clair> <sortie>
 *
 * Les trois arguments sont des noms de prises, sans chemin ni extension.
 */
import { chromium } from "@playwright/test";
import { mkdtemp, writeFile, rm } from "node:fs/promises";
import { tmpdir } from "node:os";
import { dirname, resolve, join } from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));
const shotsDir = resolve(here, "../../var/screenshots");

const [dark, light, out] = process.argv.slice(2);

if (!dark || !light || !out) {
    console.error(
        "Usage : node tools/screenshots/compose-themes.mjs <sombre> <clair> <sortie>",
    );
    process.exit(1);
}

const WIDTH = 1600;
const HEIGHT = 1000;

/**
 * La coupure part d'en haut à droite et descend vers la gauche.
 *
 * Penchée et non verticale : une verticale se lirait comme deux captures
 * collées, une diagonale comme une seule image révélant son envers. Les
 * pourcentages laissent le menu latéral entier du côté sombre et les
 * graphiques entiers du côté clair, ce qui est ce que chaque moitié a de
 * plus reconnaissable.
 */
const SEAM_TOP = 62;
const SEAM_BOTTOM = 38;

const page = `
<!doctype html>
<meta charset="utf-8">
<style>
    html, body { margin: 0; padding: 0; background: #0b1120; }
    .frame { position: relative; width: ${WIDTH}px; height: ${HEIGHT}px; overflow: hidden; }
    .frame img { position: absolute; inset: 0; width: 100%; height: 100%; display: block; }
    .light { clip-path: polygon(${SEAM_TOP}% 0, 100% 0, 100% 100%, ${SEAM_BOTTOM}% 100%); }
    /* La couture, pour que la diagonale soit un choix et non un défaut de
       découpe.

       **Le même polygone que la découpe, décalé de deux pixels**, et non un
       dégradé incliné : l'angle d'un dégradé se calcule, et un calcul faux
       trace un trait qui ne suit pas la coupure. Premier essai livré ainsi,
       une diagonale partant du coin opposé. Ici la géométrie est copiée, donc
       elle ne peut pas diverger. */
    .seam {
        position: absolute;
        inset: 0;
        background: rgba(255, 255, 255, 0.5);
        clip-path: polygon(
            ${SEAM_TOP}% 0,
            calc(${SEAM_TOP}% + 2px) 0,
            calc(${SEAM_BOTTOM}% + 2px) 100%,
            ${SEAM_BOTTOM}% 100%
        );
    }
</style>
<div class="frame">
    <img src="${pathToFileURL(join(shotsDir, `${dark}.png`)).href}" alt="">
    <img class="light" src="${pathToFileURL(join(shotsDir, `${light}.png`)).href}" alt="">
    <div class="seam"></div>
</div>
`;

const work = await mkdtemp(join(tmpdir(), "aurora-themes-"));
const html = join(work, "compose.html");
await writeFile(html, page, "utf8");

const browser = await chromium.launch();
const tab = await browser.newPage({ viewport: { width: WIDTH, height: HEIGHT } });

await tab.goto(pathToFileURL(html).href, { waitUntil: "load" });

// Les deux images viennent du disque, mais `load` ne promet pas qu'elles
// soient décodées : une capture prise trop tôt sort à moitié vide.
await tab.evaluate(() =>
    Promise.all(
        Array.from(document.images)
            .filter((img) => !img.complete)
            .map((img) => img.decode().catch(() => {})),
    ),
);

const file = join(shotsDir, `${out}.png`);
await tab.screenshot({ path: file });

await browser.close();
await rm(work, { recursive: true, force: true });

console.log(`+ ${out} -> var/screenshots/${out}.png`);
