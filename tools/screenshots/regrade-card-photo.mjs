/**
 * Regrade the photo embedded in a composed card, and only that photo.
 *
 * Service cards are montages: a background, tilted prints, viewfinder
 * corners, an exposure line. When the original photo is corrected in the
 * media library, the montage keeps the old grade, and the card starts to
 * clash with the gallery it announces.
 *
 * **The area is geometric, not colorimetric.** The print's white frame gives
 * its four corners to the pixel, so the correction covers exactly the photo
 * rectangle, tilted like it. A color mask would have bitten into the green
 * background or left a fringe on the white edge; here there is no boundary
 * to miss, and the neighbouring landscape is not touched.
 *
 * **Three levers, measured together.** Removing yellow and lightness raises
 * the apparent saturation, and a uniform saturation cut barely touches the
 * most colorful area - red lips stay red. So the script sweeps the settings
 * and measures the four quantities on each try, rather than correcting one
 * axis at a time.
 *
 * The target is given in plain values: the skin tone and lip chroma of the
 * series the card must look like.
 *
 * Usage:
 *   node tools/screenshots/regrade-card-photo.mjs <carte.png> <sortie.png> [--dry-run]
 */
import { chromium } from "@playwright/test";
import { readFile, writeFile } from "node:fs/promises";

const [entree, sortie] = process.argv.slice(2).filter((a) => !a.startsWith("--"));
const dryRun = process.argv.includes("--dry-run");

if (!entree || !sortie) {
    console.error("Usage : node tools/screenshots/regrade-card-photo.mjs <carte.png> <sortie.png> [--dry-run]");
    process.exit(1);
}

/**
 * The print to correct, measured on card 499: the three corners of the white
 * frame, and the thickness of the margins between the frame and the photo. A
 * polaroid has a thicker bottom margin than the other three.
 */
const TIRAGE = {
    haut: [708, 150],
    droite: [1244, 197],
    gauche: [655, 745],
    marges: { haut: 5, bas: 42, gauche: 9, droite: 13 },
};

/** What the photo must look like: the average of the series in the gallery. */
const CIBLE = { peauL: 57.5, peauB: 14.5, levres: 58.5 };

const browser = await chromium.launch();
const page = await browser.newPage();
await page.goto("about:blank");

const source = `data:image/png;base64,${(await readFile(entree)).toString("base64")}`;

const resultat = await page.evaluate(
    async ({ url, tirage, cible, reglages }) => {
        const img = new Image();
        img.src = url;
        await img.decode();

        const f = (v) => { v /= 255; return v > 0.04045 ? ((v + 0.055) / 1.055) ** 2.4 : v / 12.92; };
        const g = (v) => 255 * (v > 0.0031308 ? 1.055 * v ** (1 / 2.4) - 0.055 : 12.92 * v);
        const t = (v) => (v > 0.008856 ? Math.cbrt(v) : 7.787 * v + 16 / 116);
        const ti = (v) => (v ** 3 > 0.008856 ? v ** 3 : (v - 16 / 116) / 7.787);
        const lab = (r, gg, bl) => {
            const [R, G, B] = [f(r), f(gg), f(bl)];
            const X = (0.4124 * R + 0.3576 * G + 0.1805 * B) / 0.95047;
            const Y = 0.2126 * R + 0.7152 * G + 0.0722 * B;
            const Z = (0.0193 * R + 0.1192 * G + 0.9505 * B) / 1.08883;
            return [116 * t(Y) - 16, 500 * (t(X) - t(Y)), 200 * (t(Y) - t(Z))];
        };
        const sat = (r, gg, bl) => { const M = Math.max(r, gg, bl), m = Math.min(r, gg, bl); return 0 === M ? 0 : (M - m) / M; };

        // The print's coordinate frame, and the test for belonging to the photo.
        const { haut, droite, gauche, marges } = tirage;
        const lu = Math.hypot(droite[0] - haut[0], droite[1] - haut[1]);
        const lv = Math.hypot(gauche[0] - haut[0], gauche[1] - haut[1]);
        const ax = [(droite[0] - haut[0]) / lu, (droite[1] - haut[1]) / lu];
        const ay = [(gauche[0] - haut[0]) / lv, (gauche[1] - haut[1]) / lv];
        const dedans = (x, y) => {
            const dx = x - haut[0], dy = y - haut[1];
            const a = dx * ax[0] + dy * ax[1];
            const bb = dx * ay[0] + dy * ay[1];
            return a >= marges.gauche && a <= lu - marges.droite && bb >= marges.haut && bb <= lv - marges.bas;
        };

        const c = document.createElement("canvas");
        c.width = img.width;
        c.height = img.height;
        const ctx = c.getContext("2d");

        /** One pass: corrects the area and returns the measurements that matter. */
        const passe = (dL, dB, seuil, ratio, ecrire) => {
            ctx.clearRect(0, 0, c.width, c.height);
            ctx.drawImage(img, 0, 0);
            const im = ctx.getImageData(0, 0, c.width, c.height);
            const d = im.data;
            const peau = [];
            const rouges = [];

            for (let y = 0; y < c.height; y += 1) {
                for (let x = 0; x < c.width; x += 1) {
                    if (!dedans(x, y)) continue;
                    const i = 4 * (y * c.width + x);
                    const [R, G, B] = [f(d[i]), f(d[i + 1]), f(d[i + 2])];
                    const X = (0.4124 * R + 0.3576 * G + 0.1805 * B) / 0.95047;
                    const Y = 0.2126 * R + 0.7152 * G + 0.0722 * B;
                    const Z = (0.0193 * R + 0.1192 * G + 0.9505 * B) / 1.08883;
                    const [fx, fy, fz] = [t(X), t(Y), t(Z)];
                    const L = 116 * fy - 16 + dL;
                    let A = 500 * (fx - fy);
                    let Bb = 200 * (fy - fz);
                    const C = Math.hypot(A, Bb);
                    if (C > seuil) { const k = (seuil + (C - seuil) * ratio) / C; A *= k; Bb *= k; }
                    Bb += dB;
                    const fy2 = (L + 16) / 116, fx2 = fy2 + A / 500, fz2 = fy2 - Bb / 200;
                    const X2 = ti(fx2) * 0.95047, Y2 = ti(fy2), Z2 = ti(fz2) * 1.08883;
                    const nr = Math.max(0, Math.min(255, g(Math.max(0, 3.2406 * X2 - 1.5372 * Y2 - 0.4986 * Z2))));
                    const ng = Math.max(0, Math.min(255, g(Math.max(0, -0.9689 * X2 + 1.8758 * Y2 + 0.0415 * Z2))));
                    const nb = Math.max(0, Math.min(255, g(Math.max(0, 0.0557 * X2 - 0.204 * Y2 + 1.057 * Z2))));
                    d[i] = nr; d[i + 1] = ng; d[i + 2] = nb;

                    const [mL, mA, mB] = lab(nr, ng, nb);
                    if (nr > 95 && ng > 55 && nb > 40 && nr > ng && ng > nb && nr - nb > 15 && sat(nr, ng, nb) < 0.45) peau.push([mL, mB]);
                    rouges.push({ c: Math.hypot(mA, mB), dom: nr - (ng + nb) / 2 });
                }
            }

            if (ecrire) ctx.putImageData(im, 0, 0);

            rouges.sort((a, b) => b.dom - a.dom);
            const coeur = rouges.slice(0, Math.max(30, Math.round(rouges.length * 0.0015)));
            const med = (v) => { const s = [...v].sort((a, b) => a - b); return s.length ? s[Math.floor(s.length / 2)] : 0; };
            return {
                peauL: +med(peau.map((x) => x[0])).toFixed(1),
                peauB: +med(peau.map((x) => x[1])).toFixed(1),
                levres: +med(coeur.map((x) => x.c)).toFixed(1),
            };
        };

        const avant = passe(0, 0, 1e9, 1, false);

        let meilleur = null;
        for (const dL of reglages.dL) {
            for (const dB of reglages.dB) {
                for (const ratio of reglages.ratio) {
                    const m = passe(dL, dB, reglages.seuil, ratio, false);
                    const ecart = Math.abs(m.peauL - cible.peauL) / 2 + Math.abs(m.peauB - cible.peauB) / 2 + Math.abs(m.levres - cible.levres) / 4;
                    if (!meilleur || ecart < meilleur.ecart) meilleur = { dL, dB, ratio, m, ecart };
                }
            }
        }

        const apres = passe(meilleur.dL, meilleur.dB, reglages.seuil, meilleur.ratio, true);
        return { avant, apres, reglage: { dL: meilleur.dL, dB: meilleur.dB, seuil: reglages.seuil, ratio: meilleur.ratio }, data: c.toDataURL("image/png") };
    },
    {
        url: source,
        tirage: TIRAGE,
        cible: CIBLE,
        reglages: { dL: [-5, -4.5, -4, -3.5, -3], dB: [-2.5, -2, -1.5], seuil: 26, ratio: [0.6, 0.66, 0.72, 0.78] },
    },
);

console.log(`réglage retenu : clarté ${resultat.reglage.dL}   jaune ${resultat.reglage.dB}   seuil ${resultat.reglage.seuil}   compression ${resultat.reglage.ratio}`);
console.log("              peau L   peau b   lèvres");
console.log(`cible          ${String(CIBLE.peauL).padStart(5)}    ${String(CIBLE.peauB).padStart(5)}    ${String(CIBLE.levres).padStart(5)}`);
console.log(`avant          ${String(resultat.avant.peauL).padStart(5)}    ${String(resultat.avant.peauB).padStart(5)}    ${String(resultat.avant.levres).padStart(5)}`);
console.log(`après          ${String(resultat.apres.peauL).padStart(5)}    ${String(resultat.apres.peauB).padStart(5)}    ${String(resultat.apres.levres).padStart(5)}`);

if (dryRun) {
    console.log("Rien n'a été écrit.");
} else {
    await writeFile(sortie, Buffer.from(resultat.data.split(",")[1], "base64"));
    console.log(`+ ${sortie}`);
}

await browser.close();
