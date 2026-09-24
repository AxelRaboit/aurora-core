/**
 * L'entête du sommaire, avec le tableau de bord dans ses deux thèmes.
 *
 * **Deux panneaux, pas un seul coupé en deux.** Première version livrée avec
 * une diagonale qui partageait un unique écran ; ce n'était pas la demande.
 * Ce qu'il faut montrer, c'est le même tableau de bord deux fois, en sombre
 * et en clair, le clair posé par-dessus, décalé.
 *
 * **Le fond peut être laissé transparent**, et c'est le meilleur choix : le
 * gabarit pose le dégradé sur le conteneur du bandeau et l'image par-dessus,
 * donc une image sans fond laisse passer le vert du bandeau lui-même. Rien
 * n'est refabriqué, rien ne peut diverger, et les captures se détachent sur
 * le vert au lieu du noir vers lequel l'ancienne image virait à droite.
 * Passer `none` comme fond.
 *
 * **Sauf que le dégradé du bandeau vire au noir vers la droite**, et que
 * c'est précisément là que sont les captures : transparente, l'image les
 * laisse sur du sombre. `vert` fabrique donc un fond qui garde du vert d'un
 * bout à l'autre, avec les couleurs du bandeau - même départ `#064e3b`, même
 * inclinaison de 160 degrés - mais une arrivée verte sombre au lieu du quasi
 * noir, plus une lueur douce derrière les panneaux. La densité visée est
 * celle de la maison : luminance du vert entre 28 et 50, l'original mesurait
 * 38.
 *
 * **Sinon l'entête existante sert de fond, elle n'est pas refabriquée.** Son
 * dégradé vert sombre est celui de la maison, et le reconstruire tomberait
 * droit dans le piège connu : estimer un fond par la couleur médiane par
 * anneau donne un halo, parce qu'au rayon zéro le sujet couvre tous les
 * pixels. Reprendre l'image telle quelle rend le dégradé exact.
 *
 * Conséquence directe sur la géométrie : **le panneau sombre doit recouvrir
 * entièrement celui de l'original**, sinon un bout de l'ancien dépasse par en
 * dessous. L'original occupe 1240,60 sur 898x561 ; celui-ci part plus haut et
 * descend au même endroit, ce qui l'efface.
 *
 * Le panneau déborde par la droite, et c'est voulu : **le téléphone rogne les
 * côtés** et ne garde que les 500 pixels centraux d'une toile de 1920. Rien
 * de ce qui compte ne doit y être, et l'entête d'origine faisait déjà ce
 * choix.
 *
 * Aucun texte dans l'image : le premier élément du bandeau porte déjà le
 * `h1` de la page.
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

const [base, darkName, lightName, out] = process.argv
    .slice(2)
    .filter((a) => !a.startsWith("--") && Number.isNaN(Number(a)));

if (!base || !darkName || !lightName || !out) {
    console.error(
        "Usage : node tools/screenshots/compose-header-themes.mjs <fond> <sombre> <clair> <sortie>",
    );
    process.exit(1);
}

/** Le format d'une entête `full_aligned` en hauteur `lg`. */
const WIDTH = 1920;
const HEIGHT = 682;

/**
 * Les deux panneaux.
 *
 * Le sombre couvre l'empreinte de celui de l'original (1240,60, 898x561) :
 * il part plus haut, garde le même bas, et il est donc plus grand. Le clair
 * se pose dessus, rentré de tous les côtés, pour qu'on voie du sombre autour
 * de lui : sa barre latérale à gauche, une bande en haut, un liseré en bas.
 *
 * Réglables, parce que la bonne place se juge sur le rendu et pas sur le
 * papier.
 */
const args = process.argv.slice(2);
const at = (flag, fallback) =>
    args.includes(flag) ? Number(args[args.indexOf(flag) + 1]) : fallback;

/** La capture source, dont l'échelle découle de la hauteur voulue. */
const SOURCE = { width: 1600, height: 1000 };

const RADIUS = 12;

const dark = {
    left: at("--dark-left", 1240),
    top: at("--dark-top", 30),
    height: at("--dark-height", 591),
};

const light = {
    left: at("--light-left", 1500),
    top: at("--light-top", 150),
    height: at("--light-height", 450),
};

const sized = (panel) => ({
    ...panel,
    width: Math.round((SOURCE.width * panel.height) / SOURCE.height),
});

const DARK = sized(dark);
const LIGHT = sized(light);

const url = (name) => pathToFileURL(join(shotsDir, `${name}.png`)).href;

const panel = (cls, name, box, shadow) => `
    <div class="panel ${cls}" style="
        left: ${box.left}px;
        top: ${box.top}px;
        width: ${box.width}px;
        height: ${box.height}px;
        ${shadow}
    ">
        <img src="${url(name)}" alt="">
    </div>`;

const html = `
<!doctype html>
<meta charset="utf-8">
<style>
    html, body { margin: 0; padding: 0; }
    .canvas { position: relative; width: ${WIDTH}px; height: ${HEIGHT}px; overflow: hidden; }
    .canvas > img.base { position: absolute; inset: 0; width: 100%; height: 100%; display: block; }

    /* Les couleurs du bandeau, mais une arrivée verte : le dégradé d'origine
       finit en #030712, presque noir, et les captures tombent dedans. La
       lueur est posée derrière elles, pas au centre de la toile. */
    .ground {
        position: absolute;
        inset: 0;
        background:
            radial-gradient(60% 90% at 78% 45%, rgba(6, 95, 70, 0.32), transparent 72%),
            linear-gradient(160deg, #054634 0%, #04362a 55%, #03261d 100%);
    }

    .panel {
        position: absolute;
        border-radius: ${RADIUS}px;
        overflow: hidden;
        /* Le même filet clair que l'original portait autour de son panneau. */
        box-shadow: 0 0 0 1px rgba(255, 255, 255, 0.12);
    }
    .panel img { position: absolute; inset: 0; width: 100%; height: 100%; display: block; }
</style>
<div class="canvas">
    ${
        "vert" === base
            ? `<div class="base ground"></div>`
            : "none" === base
              ? ""
              : `<img class="base" src="${url(base)}" alt="">`
    }
    ${panel("dark", darkName, DARK, "")}
    ${panel("light", lightName, LIGHT, "box-shadow: 0 0 0 1px rgba(255,255,255,0.18), -26px 26px 64px rgba(0,0,0,0.72);")}
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
await tab.screenshot({ path: target, omitBackground: "none" === base });

await browser.close();
await rm(work, { recursive: true, force: true });

console.log(
    `+ ${out} -> var/screenshots/${out}.png ` +
        `(sombre ${DARK.width}x${DARK.height} à ${DARK.left},${DARK.top} ; ` +
        `clair ${LIGHT.width}x${LIGHT.height} à ${LIGHT.left},${LIGHT.top})`,
);
