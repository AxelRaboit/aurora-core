/**
 * Réétalonner le portrait incrusté dans un montage, avec un fondu au bord.
 *
 * Même besoin que `regrade-card-photo.mjs`, mais sur les entêtes : le
 * portrait y est une bande verticale collée au bord de l'image, voisine
 * d'une grille de vignettes qu'il ne faut surtout pas toucher.
 *
 * **La frontière ne se mesure pas proprement** : cherchée par saut de
 * luminance, elle donne vingt-quatre pixels de dispersion, les traits du
 * visage produisant des contrastes aussi forts que le bord du panneau.
 * Quatre méthodes de détection automatique ont échoué pour la même raison.
 *
 * **D'où le fondu.** La correction entre progressivement entre `x1` et `x2`,
 * puis s'applique pleinement. Une erreur de placement de vingt pixels ne
 * produit alors aucune couture : elle décale une rampe douce au lieu de
 * créer une marche.
 *
 * Usage :
 *   node tools/screenshots/regrade-composite-photo.mjs <entrée> <sortie> <x1> <x2> [--dry-run]
 */
import { chromium } from "@playwright/test";
import { readFile, writeFile } from "node:fs/promises";

const args = process.argv.slice(2).filter((a) => !a.startsWith("--"));
const [entree, sortie, X1, X2] = args;
const dryRun = process.argv.includes("--dry-run");

if (!entree || !sortie || !X1 || !X2) {
    console.error("Usage : node tools/screenshots/regrade-composite-photo.mjs <entrée> <sortie> <x1> <x2> [--dry-run]");
    process.exit(1);
}

/** Le réglage validé sur la photo de galerie, et repris tel quel ici. */
const REGLAGE = { dL: -0.8, dB: -1.5, seuil: 26, ratio: 0.68 };

const browser = await chromium.launch();
const page = await browser.newPage();
await page.goto("about:blank");

const resultat = await page.evaluate(
    async ({ url, x1, x2, r }) => {
        const img = new Image();
        img.src = url;
        await img.decode();
        const c = document.createElement("canvas");
        c.width = img.width; c.height = img.height;
        const ctx = c.getContext("2d");
        ctx.drawImage(img, 0, 0);
        const im = ctx.getImageData(0, 0, c.width, c.height);
        const d = im.data;

        const f = (v) => { v /= 255; return v > 0.04045 ? ((v + 0.055) / 1.055) ** 2.4 : v / 12.92; };
        const g = (v) => 255 * (v > 0.0031308 ? 1.055 * v ** (1 / 2.4) - 0.055 : 12.92 * v);
        const t = (v) => (v > 0.008856 ? Math.cbrt(v) : 7.787 * v + 16 / 116);
        const ti = (v) => (v ** 3 > 0.008856 ? v ** 3 : (v - 16 / 116) / 7.787);

        /** La chrominance du coeur des lèvres, seule mesure fiable ici. */
        const levres = () => {
            const rouges = [];
            for (let y = 0; y < c.height; y += 2) {
                for (let x = Math.max(0, x2); x < c.width; x += 2) {
                    const i = 4 * (y * c.width + x);
                    const [R, G, B] = [f(d[i]), f(d[i + 1]), f(d[i + 2])];
                    const X = (0.4124 * R + 0.3576 * G + 0.1805 * B) / 0.95047;
                    const Y = 0.2126 * R + 0.7152 * G + 0.0722 * B;
                    const Z = (0.0193 * R + 0.1192 * G + 0.9505 * B) / 1.08883;
                    rouges.push({ c: Math.hypot(500 * (t(X) - t(Y)), 200 * (t(Y) - t(Z))), dom: d[i] - (d[i + 1] + d[i + 2]) / 2 });
                }
            }
            rouges.sort((a, b) => b.dom - a.dom);
            const coeur = rouges.slice(0, Math.max(30, Math.round(rouges.length * 0.0012)));
            const s = coeur.map((q) => q.c).sort((a, b) => a - b);
            return +s[Math.floor(s.length / 2)].toFixed(1);
        };

        const avant = levres();

        for (let y = 0; y < c.height; y += 1) {
            for (let x = x1; x < c.width; x += 1) {
                const force = x >= x2 ? 1 : (x - x1) / (x2 - x1);
                if (force <= 0) continue;
                const i = 4 * (y * c.width + x);
                const [R, G, B] = [f(d[i]), f(d[i + 1]), f(d[i + 2])];
                const X = (0.4124 * R + 0.3576 * G + 0.1805 * B) / 0.95047;
                const Y = 0.2126 * R + 0.7152 * G + 0.0722 * B;
                const Z = (0.0193 * R + 0.1192 * G + 0.9505 * B) / 1.08883;
                const [fx, fy, fz] = [t(X), t(Y), t(Z)];
                const L = 116 * fy - 16 + r.dL * force;
                let A = 500 * (fx - fy), Bb = 200 * (fy - fz);
                const C = Math.hypot(A, Bb);
                if (C > r.seuil) {
                    const k = (r.seuil + (C - r.seuil) * r.ratio) / C;
                    const kf = 1 + (k - 1) * force;
                    A *= kf; Bb *= kf;
                }
                Bb += r.dB * force;
                const fy2 = (L + 16) / 116, fx2 = fy2 + A / 500, fz2 = fy2 - Bb / 200;
                const X2c = ti(fx2) * 0.95047, Y2 = ti(fy2), Z2 = ti(fz2) * 1.08883;
                d[i] = Math.max(0, Math.min(255, g(Math.max(0, 3.2406 * X2c - 1.5372 * Y2 - 0.4986 * Z2))));
                d[i + 1] = Math.max(0, Math.min(255, g(Math.max(0, -0.9689 * X2c + 1.8758 * Y2 + 0.0415 * Z2))));
                d[i + 2] = Math.max(0, Math.min(255, g(Math.max(0, 0.0557 * X2c - 0.204 * Y2 + 1.057 * Z2))));
            }
        }
        ctx.putImageData(im, 0, 0);
        return { avant, apres: levres(), data: c.toDataURL("image/png") };
    },
    { url: `data:image/png;base64,${(await readFile(entree)).toString("base64")}`, x1: Number(X1), x2: Number(X2), r: REGLAGE },
);

console.log(`${entree.split("/").pop()}  fondu ${X1}->${X2}   lèvres ${resultat.avant} -> ${resultat.apres}  (cible ~58)`);
if (dryRun) console.log("  rien écrit");
else { await writeFile(sortie, Buffer.from(resultat.data.split(",")[1], "base64")); console.log(`  + ${sortie.split("/").pop()}`); }
await browser.close();
