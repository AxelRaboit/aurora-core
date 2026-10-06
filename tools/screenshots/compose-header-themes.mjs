/**
 * The hub page's header, with the dashboard in its two themes.
 *
 * **Two panels, not a single one cut in two.** The first version shipped with
 * a diagonal splitting a single screen; that was not what was asked. What has
 * to be shown is the same dashboard twice, dark and light, the light one laid
 * on top, offset.
 *
 * **The background can be left transparent**, and it is the best choice: the
 * template sets the gradient on the banner's container and the image on top,
 * so an image without a background lets the banner's own green show through.
 * Nothing is rebuilt, nothing can drift, and the screenshots stand out on the
 * green instead of the black the old image turned to on the right.
 * Pass `none` as the background.
 *
 * **Except that the banner's gradient turns black towards the right**, and
 * that is exactly where the screenshots are: transparent, the image leaves
 * them on dark. `vert` therefore makes a background that keeps green from end
 * to end, with the banner's colours - same start `#064e3b`, same 160-degree
 * angle - but a dark green end instead of near black, plus a soft glow behind
 * the panels. The target density is the house one: green luminance between
 * 28 and 50, the original measured 38.
 *
 * **Otherwise the existing header serves as the background, it is not
 * rebuilt.** Its dark green gradient is the house one, and rebuilding it
 * would fall straight into the known trap: estimating a background by the
 * median colour per ring gives a halo, because at radius zero the subject
 * covers every pixel. Reusing the image as is gives the exact gradient.
 *
 * Direct consequence on the geometry: **the dark panel must fully cover the
 * original's one**, otherwise a piece of the old one shows from underneath.
 * The original sits at 1240,60 over 898x561; this one starts higher and ends
 * at the same place, which hides it.
 *
 * The panel overflows on the right, on purpose: **the phone crops the sides**
 * and keeps only the central 500 pixels of a 1920 canvas. Nothing that
 * matters may be there, and the original header already made that choice.
 *
 * No text in the image: the banner's first element already carries the
 * page's `h1`.
 *
 * Usage:
 *   node tools/screenshots/compose-header-themes.mjs <background> <dark> <light> <output>
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

/** The size of a `full_aligned` header at `lg` height. */
const WIDTH = 1920;
const HEIGHT = 682;

/**
 * The two panels.
 *
 * The dark one covers the footprint of the original's (1240,60, 898x561): it
 * starts higher, keeps the same bottom, and is therefore larger. The light
 * one sits on top, inset on every side, so that dark shows around it: its
 * sidebar on the left, a strip at the top, a thin edge at the bottom.
 *
 * Adjustable, because the right position is judged on the render and not on
 * paper.
 */
const args = process.argv.slice(2);
const at = (flag, fallback) =>
    args.includes(flag) ? Number(args[args.indexOf(flag) + 1]) : fallback;

/** The source screenshot, whose scale follows from the wanted height. */
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

// `load` does not promise that the images are decoded, and a screenshot taken
// too early comes out half empty.
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
