/**
 * Two screenshots of the same screen, one dark and one light, in one image.
 *
 * The index page banner lays out its elements on the same 48 columns as the
 * rest: putting two images side by side there would leave them ten columns
 * each, so two hundred and fifty pixels, where a dashboard is nothing but a
 * texture. **A single image cut diagonally takes the room of one and shows
 * both**, and the cut reads at a glance because the two halves are the same
 * screen.
 *
 * The composition happens in the browser that took the screenshots, rather
 * than with one more image tool: the diagonal is a `clip-path`, the seam a
 * gradient, and the result is reviewed with the same eyes as the rest of the
 * tour.
 *
 * Usage:
 *   node tools/screenshots/compose-themes.mjs <dark> <light> <output>
 *
 * The three arguments are shot names, without path or extension.
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
 * The cut starts at the top right and goes down to the left.
 *
 * Slanted and not vertical: a vertical line would read as two screenshots
 * stuck together, a diagonal as a single image revealing its other side. The
 * percentages leave the whole side menu on the dark side and the whole charts
 * on the light side, which is what each half has that is most recognizable.
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

// Both images come from the disk, but `load` does not promise they are
// decoded: a screenshot taken too early comes out half empty.
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
