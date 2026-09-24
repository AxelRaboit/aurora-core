/**
 * L'entête du sommaire, avec le tableau de bord dans ses deux thèmes.
 *
 * **L'entête existante sert de fond, elle n'est pas refabriquée.** Son
 * dégradé vert sombre est celui de la maison, et le reconstruire tomberait
 * droit dans le piège connu : estimer un fond par la couleur médiane par
 * anneau donne un halo, parce qu'au rayon zéro le sujet couvre tous les
 * pixels. Reprendre l'image telle quelle rend le dégradé exact, gratuitement.
 *
 * Seul le panneau est redessiné, exactement dans sa boîte, mesurée au pixel
 * sur l'original : le panneau commence à 1240, à 60 du haut, et fait 561 de
 * haut, soit la capture 1600x1000 réduite à 56,1 %. Il déborde par la droite,
 * et c'est voulu - **le téléphone rogne les côtés** et ne garde que les 500
 * pixels centraux d'une toile de 1920, donc ce qui est posé à droite
 * disparaît sur petit écran, comme dans l'entête d'origine.
 *
 * Le panneau est posé un pixel à l'intérieur de sa boîte pour que le filet
 * clair de l'original reste visible tout autour.
 *
 * Aucun texte dans l'image : le premier élément du bandeau porte déjà le
 * `h1` de la page, et l'écrire deux fois le dirait deux fois.
 *
 * Usage :
 *   node tools/screenshots/compose-header-themes.mjs <fond> <sombre> <clair> <sortie>
 */
import { chromium } from "@playwright/test";
import { mkdtemp, writeFile, rm } from "node:fs/promises";
import { tmpdir } from "node:os";
import { dirname, resolve, join } from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));
const shotsDir = resolve(here, "../../var/screenshots");

const [base, dark, light, out] = process.argv.slice(2).filter((a) => !a.startsWith("--") && Number.isNaN(Number(a)));

if (!base || !dark || !light || !out) {
    console.error(
        "Usage : node tools/screenshots/compose-header-themes.mjs <fond> <sombre> <clair> <sortie>",
    );
    process.exit(1);
}

/** Le format d'une entête `full_aligned` en hauteur `lg`. */
const WIDTH = 1920;
const HEIGHT = 682;

/** La boîte du panneau, relevée sur l'entête d'origine. */
const PANEL = { left: 1240, top: 60, height: 561, radius: 12 };

/** La capture source, et donc l'échelle que cette hauteur impose. */
const SOURCE = { width: 1600, height: 1000 };
const SCALE = PANEL.height / SOURCE.height;
const PANEL_WIDTH = Math.round(SOURCE.width * SCALE);

/**
 * La coupure, en pourcentage de la largeur du panneau.
 *
 * Elle est placée au milieu de ce qui **reste visible** sur la toile, pas au
 * milieu du panneau : le panneau fait 898 de large et seuls ses 680 premiers
 * pixels tiennent dans les 1920. Une coupure à mi-panneau serait aux trois
 * quarts de ce qu'on voit, et le thème clair n'aurait plus qu'un liseré.
 */
const args = process.argv.slice(2);
const at = (flag, fallback) =>
    args.includes(flag) ? Number(args[args.indexOf(flag) + 1]) : fallback;

// Réglables, parce que la bonne position dépend de ce que l'écran contient :
// le premier essai coupait « Tableau de bord » au milieu d'un mot, ce qui se
// lit comme un défaut d'affichage et non comme un parti pris.
const seamTop = Math.round((at("--seam-top", 460) / PANEL_WIDTH) * 100);
const seamBottom = Math.round((at("--seam-bottom", 320) / PANEL_WIDTH) * 100);

const url = (name) => pathToFileURL(join(shotsDir, `${name}.png`)).href;

const html = `
<!doctype html>
<meta charset="utf-8">
<style>
    html, body { margin: 0; padding: 0; }
    .canvas { position: relative; width: ${WIDTH}px; height: ${HEIGHT}px; overflow: hidden; }
    .canvas > img.base { position: absolute; inset: 0; width: 100%; height: 100%; display: block; }

    /* Un pixel à l'intérieur de la boîte : le filet clair de l'original fait
       le tour du panneau, et le recouvrir le ferait disparaître. */
    .panel {
        position: absolute;
        left: ${PANEL.left + 1}px;
        top: ${PANEL.top + 1}px;
        width: ${PANEL_WIDTH - 2}px;
        height: ${PANEL.height - 2}px;
        border-radius: ${PANEL.radius}px;
        overflow: hidden;
    }
    .panel img { position: absolute; inset: 0; width: 100%; height: 100%; display: block; }
    .panel .light { clip-path: polygon(${seamTop}% 0, 100% 0, 100% 100%, ${seamBottom}% 100%); }

    /* La couture, dessinée avec le même polygone que la découpe et décalée de
       deux pixels. Un dégradé incliné demanderait de calculer un angle, et un
       angle faux trace un trait qui ne suit pas la coupure. */
    .panel .seam {
        position: absolute;
        inset: 0;
        background: rgba(255, 255, 255, 0.45);
        clip-path: polygon(
            ${seamTop}% 0,
            calc(${seamTop}% + 2px) 0,
            calc(${seamBottom}% + 2px) 100%,
            ${seamBottom}% 100%
        );
    }
</style>
<div class="canvas">
    <img class="base" src="${url(base)}" alt="">
    <div class="panel">
        <img src="${url(dark)}" alt="">
        <img class="light" src="${url(light)}" alt="">
        <div class="seam"></div>
    </div>
</div>
`;

const work = await mkdtemp(join(tmpdir(), "aurora-header-"));
const file = join(work, "compose.html");
await writeFile(file, html, "utf8");

const browser = await chromium.launch();
const tab = await browser.newPage({ viewport: { width: WIDTH, height: HEIGHT } });

await tab.goto(pathToFileURL(file).href, { waitUntil: "load" });

// `load` ne promet pas que les images soient décodées, et une capture prise
// trop tôt sort à moitié vide.
await tab.evaluate(() =>
    Promise.all(
        Array.from(document.images)
            .filter((img) => !img.complete)
            .map((img) => img.decode().catch(() => {})),
    ),
);

const target = join(shotsDir, `${out}.png`);
await tab.screenshot({ path: target });

await browser.close();
await rm(work, { recursive: true, force: true });

console.log(
    `+ ${out} -> var/screenshots/${out}.png (panneau ${PANEL_WIDTH}x${PANEL.height} à ${PANEL.left},${PANEL.top}, coupure ${seamTop}% → ${seamBottom}%)`,
);
