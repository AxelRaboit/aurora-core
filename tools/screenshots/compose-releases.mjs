/**
 * Les deux images de la page « Livraison et mises à jour », dessinées plutôt
 * que photographiées.
 *
 * **Elles étaient des captures de GitHub** (la liste des releases et les notes
 * de la dernière), les deux seules prises du tour qui ne venaient pas de
 * l'application. Axel n'en voulait plus : une page de GitHub en anglais, avec
 * son menu et ses boutons « Sign up », ne dit rien de ce que le client
 * reçoit.
 *
 * **Aucun mot dans les images**, seulement des numéros de version, des
 * chiffres et des pictogrammes : la page existe en trois langues et une image
 * ne se traduit pas. Les textes alternatifs de la page portent le sens.
 *
 * **Des versions fictives, choisies pour montrer la règle.** La première
 * mouture lisait les huit dernières entrées du CHANGELOG : c'était la série
 * 0.9.x, où une fonctionnalité faisait monter le dernier chiffre comme un
 * correctif, et la page affichait le défaut qu'elle prétend éviter. La suite
 * ci-dessous suit le versionnage sémantique : un correctif seul monte le
 * troisième chiffre, un ajout ou une amélioration le deuxième, un changement
 * qui demande un geste au client le premier. Le chiffre qui monte prend la
 * couleur de ce qui l'a fait monter, et l'image n'a plus à être reprise après
 * chaque release.
 *
 * Usage :
 *   node tools/screenshots/compose-releases.mjs
 *   node tools/screenshots/push-tour.mjs tour-releases tour-release-notes
 */
import { chromium } from "@playwright/test";
import { mkdtemp, writeFile, rm } from "node:fs/promises";
import { tmpdir } from "node:os";
import { dirname, resolve, join } from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, "../..");
const shotsDir = resolve(root, "var/screenshots");

const WIDTH = 1600;
const HEIGHT = 1000;
const TESTS = "12 000+";

/** Les couleurs de la charte, dans l'ordre des métiers : dev, photo, CM. */
const GREEN = "#34d399";
const YELLOW = "#cd8f31";
const RED = "#bd4a55";
const BLUE = "#60a5fa";

/**
 * La suite montrée, de la plus ancienne à la plus récente. `bump` dit quel
 * chiffre a monté, et il découle de ce que la version contient : `breaking`
 * fait une majeure, `added` ou `improved` une mineure, `fixed` seul un
 * correctif. Vérifié au lancement, pour que l'illustration ne puisse pas
 * contredire la règle qu'elle illustre.
 */
const SERIES = [
    { version: "1.4.0", added: 2, improved: 1, fixed: 0, breaking: 0 },
    { version: "1.4.1", added: 0, improved: 0, fixed: 2, breaking: 0 },
    { version: "1.5.0", added: 1, improved: 3, fixed: 1, breaking: 0 },
    { version: "1.5.1", added: 0, improved: 0, fixed: 1, breaking: 0 },
    { version: "1.5.2", added: 0, improved: 0, fixed: 3, breaking: 0 },
    { version: "2.0.0", added: 2, improved: 1, fixed: 0, breaking: 1 },
    { version: "2.0.1", added: 0, improved: 0, fixed: 1, breaking: 0 },
    { version: "2.1.0", added: 0, improved: 2, fixed: 1, breaking: 0 },
];

/** Le chiffre qu'une version a dû faire monter, d'après ce qu'elle contient. */
const expectedBump = (v) => (v.breaking ? 0 : v.added || v.improved ? 1 : 2);

/** Le chiffre qu'elle fait monter, d'après son numéro et celui d'avant. */
function actualBump(previous, current) {
    const a = previous.split(".").map(Number);
    const b = current.split(".").map(Number);
    const index = b.findIndex((n, i) => n !== a[i]);
    const reset = b.slice(index + 1).every((n) => 0 === n);
    if (b[index] !== a[index] + 1 || !reset) {
        throw new Error(`${previous} -> ${current} n'est pas une montée sémantique`);
    }

    return index;
}

const BUMP_COLORS = [RED, BLUE, YELLOW];

/** Le numéro, chiffre monté en couleur. */
function label(v, bump) {
    const parts = v.version.split(".").map((n, i) => (i === bump ? `<b style="color:${BUMP_COLORS[i]}">${n}</b>` : n));

    return `v${parts.join(".")}`;
}

const icon = {
    shield: '<path d="M12 3l8 3v6c0 4.5-3.4 8.3-8 9-4.6-.7-8-4.5-8-9V6z"/><path d="M8.5 12l2.5 2.5 4.5-5"/>',
    database: '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
    rocket: '<path d="M5 15c-1.5 1.3-2 5-2 5s3.7-.5 5-2c.7-.8.7-2.1-.1-2.9-.8-.8-2.1-.8-2.9-.1z"/><path d="M12 15l-3-3a22 22 0 0 1 2-4A12.9 12.9 0 0 1 22 2c0 2.7-.8 7.5-6 11a22.4 22.4 0 0 1-4 2z"/><path d="M9 12H4s.6-3 2-4c1.6-1.1 5 0 5 0"/><path d="M12 15v5s3-.6 4-2c1.1-1.6 0-5 0-5"/>',
    pulse: '<path d="M3 12h4l3-8 4 16 3-8h4"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    up: '<path d="M12 19V5M5 12l7-7 7 7"/>',
    wrench: '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
    check: '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
    zap: '<path d="M13 2L4.5 13.5H12L11 22l8.5-11.5H12z"/>',
};

const svg = (name, size, color, width = 2) =>
    `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="${color}" stroke-width="${width}" stroke-linecap="round" stroke-linejoin="round">${icon[name]}</svg>`;

const base = `
    html, body { margin: 0; padding: 0; }
    body {
        width: ${WIDTH}px; height: ${HEIGHT}px; overflow: hidden;
        font-family: "Poppins", ui-sans-serif, system-ui, sans-serif; color: #e5e7eb;
        background:
            radial-gradient(circle at 18% 30%, rgba(52, 211, 153, .14), transparent 45%),
            radial-gradient(circle at 85% 80%, rgba(52, 211, 153, .08), transparent 40%),
            linear-gradient(160deg, #0b1a17, #070b14 60%);
    }
    .grid-bg {
        position: absolute; inset: 0;
        background-image: linear-gradient(rgba(255,255,255,.035) 1px, transparent 1px),
            linear-gradient(90deg, rgba(255,255,255,.035) 1px, transparent 1px);
        background-size: 40px 40px;
    }
    .logo { display: block; width: 92px; height: 92px; margin: -18px 0 -14px -8px; }
`;

/**
 * Le logo d'Aurora, « Lever du jour » (choisi par Axel le 03/10/2026) : deux
 * arcs et un soleil au-dessus de l'horizon, vert, jaune, rouge de l'extérieur
 * vers le centre. Le logo à bandes est celui du site d'Axel, pas d'Aurora.
 */
const LOGO = `<svg class="logo" viewBox="0 0 64 64" aria-hidden="true"><g transform="translate(0 -7.5)">
    <path d="M8.5,50 A23.5 23.5 0 0 1 55.5,50" fill="none" stroke="${GREEN}" stroke-width="7"/>
    <path d="M17.5,50 A14.5 14.5 0 0 1 46.5,50" fill="none" stroke="${YELLOW}" stroke-width="7"/>
    <path d="M23,50 A9 9 0 0 1 41,50 Z" fill="${RED}"/>
    <rect x="4" y="53" width="56" height="3" rx="1.5" fill="#ece2d0" fill-opacity=".35"/>
</g></svg>`;

/** La version part du cœur, passe ses contrôles et arrive sur chaque site. */
function pipeline(list) {
    const [current, ...previous] = list;

    const gates = [
        ["shield", TESTS],
        ["database", ""],
        ["rocket", ""],
        ["pulse", "200"],
    ].map(([name, label], i) => `
        <div class="gate" style="top:${190 + i * 165}px">
            <span class="gate-icon">${svg(name, 34, GREEN)}</span>
            ${label ? `<span class="gate-label">${label}</span>` : '<span class="gauge"><i></i></span>'}
            <span class="tick">${svg("check", 16, "#052e22", 3)}</span>
        </div>`).join("");

    const sites = [GREEN, YELLOW, RED].map((color, i) => `
        <div class="site" style="top:${120 + i * 270}px; left:${1090 + (i % 2) * 40}px">
            <div class="bar"><i></i><i></i><i></i><span class="url"></span></div>
            <div class="body">
                <div class="nav"><b style="background:${color}"></b><s></s><s></s><s></s></div>
                <div class="hero" style="background:linear-gradient(120deg, ${color}55, ${color}11)"><s></s><s class="short"></s></div>
                <div class="cols"><span></span><span></span><span></span></div>
            </div>
            <div class="badge">v${current.version} ${svg("check", 16, "#052e22", 3)}</div>
        </div>`).join("");

    const stack = previous.slice(0, 4).map((v, i) =>
        `<div class="old" style="opacity:${0.55 - i * 0.12}">v${v.version}</div>`).join("");

    return `
<style>
    ${base}
    .core {
        position: absolute; left: 90px; top: 250px; width: 360px; padding: 40px;
        border-radius: 28px; background: rgba(17, 24, 39, .85);
        border: 1.5px solid rgba(52, 211, 153, .45); box-shadow: 0 0 80px rgba(52, 211, 153, .18);
    }
    .core h1 { margin: 22px 0 26px; font-size: 54px; font-weight: 700; letter-spacing: -.01em; }
    .current {
        display: inline-flex; padding: 12px 22px; border-radius: 999px; font-size: 34px; font-weight: 700;
        color: #052e22; background: ${GREEN}; box-shadow: 0 0 40px rgba(52, 211, 153, .45);
    }
    .old { margin-top: 14px; font-size: 22px; font-weight: 600; color: #9ca3af; padding-left: 8px; }
    .gate {
        position: absolute; left: 600px; width: 330px; height: 108px; box-sizing: border-box;
        display: flex; align-items: center; gap: 22px; padding: 0 28px; border-radius: 22px;
        background: rgba(17, 24, 39, .9); border: 1.5px solid rgba(255, 255, 255, .1);
    }
    .gate-icon {
        display: inline-flex; width: 62px; height: 62px; border-radius: 18px;
        align-items: center; justify-content: center; background: rgba(52, 211, 153, .12);
    }
    .gate-label { font-size: 34px; font-weight: 700; }
    /* Sans chiffre à montrer, une jauge pleine : l'étape est passée. */
    .gauge { width: 150px; height: 12px; border-radius: 999px; background: rgba(255, 255, 255, .08); overflow: hidden; }
    .gauge i { display: block; height: 100%; width: 100%; border-radius: 999px; background: linear-gradient(90deg, ${GREEN}55, ${GREEN}); }
    .tick {
        position: absolute; right: 24px; display: inline-flex; width: 30px; height: 30px; border-radius: 999px;
        align-items: center; justify-content: center; background: ${GREEN};
    }
    .site {
        position: absolute; width: 400px; height: 230px; border-radius: 18px; overflow: hidden;
        background: #111827; border: 1.5px solid rgba(255, 255, 255, .12); box-shadow: 0 20px 50px rgba(0, 0, 0, .45);
    }
    .bar { height: 30px; display: flex; align-items: center; gap: 7px; padding: 0 14px; background: #1f2937; }
    .bar i { width: 10px; height: 10px; border-radius: 999px; background: #4b5563; }
    .bar .url { margin-left: 14px; height: 12px; width: 170px; border-radius: 6px; background: #374151; }
    .body { padding: 16px 18px; }
    .nav { display: flex; gap: 12px; align-items: center; margin-bottom: 14px; }
    .nav b { width: 26px; height: 12px; border-radius: 4px; }
    .nav s { width: 46px; height: 8px; border-radius: 4px; background: #374151; text-decoration: none; }
    .hero { height: 78px; border-radius: 10px; padding: 16px; box-sizing: border-box; }
    .hero s { display: block; height: 12px; width: 60%; border-radius: 6px; background: rgba(255,255,255,.55); }
    .hero s.short { margin-top: 10px; width: 38%; background: rgba(255,255,255,.3); }
    .cols { display: flex; gap: 12px; margin-top: 14px; }
    .cols span { flex: 1; height: 34px; border-radius: 8px; background: #1f2937; }
    .badge {
        position: absolute; right: 14px; bottom: 14px; display: inline-flex; align-items: center; gap: 8px;
        padding: 7px 14px; border-radius: 999px; font-size: 19px; font-weight: 700; color: #052e22; background: ${GREEN};
    }
    svg.links { position: absolute; inset: 0; }
</style>
<div class="grid-bg"></div>
<svg class="links" width="${WIDTH}" height="${HEIGHT}" fill="none" stroke="${GREEN}" stroke-width="2.5" stroke-dasharray="8 8" opacity=".55">
    <path d="M450 500 C 520 500, 530 245, 600 245"/>
    <path d="M450 500 C 520 500, 530 740, 600 740"/>
    <path d="M765 299 V 355 M765 464 V 520 M765 629 V 685"/>
    <path d="M930 740 C 1010 740, 1010 235, 1090 235"/>
    <path d="M930 740 C 1010 740, 1030 505, 1130 505"/>
    <path d="M930 740 C 1010 740, 1010 775, 1090 775"/>
</svg>
<div class="core">
    ${LOGO}
    <h1>Aurora</h1>
    <div class="current">v${current.version}</div>
    ${stack}
</div>
${gates}
${sites}
`;
}

/** Ce que chaque version a apporté, version après version. */
function timeline(ordered) {
    const max = Math.max(...ordered.map((v) => v.breaking + v.added + v.improved + v.fixed), 1);
    const step = (WIDTH - 260) / (ordered.length - 1);

    const pill = (name, color, n) => n
        ? `<span class="pill" style="color:${color}; background:${color}22; border-color:${color}66">${svg(name, 22, color, 2.6)}${n}</span>`
        : "";

    const columns = ordered.map((v, i) => {
        const last = i === ordered.length - 1;
        const total = v.breaking + v.added + v.improved + v.fixed;
        const bump = 0 === i ? expectedBump(v) : actualBump(ordered[i - 1].version, v.version);
        const height = 60 + (total / max) * 380;

        return `
        <div class="col" style="left:${130 + i * step}px">
            <div class="pills">${pill("zap", RED, v.breaking)}${pill("plus", GREEN, v.added)}${pill("up", BLUE, v.improved)}${pill("wrench", YELLOW, v.fixed)}</div>
            <div class="stem" style="height:${height}px; ${last ? `background:linear-gradient(${GREEN}, ${GREEN}22)` : ""}"></div>
            <div class="dot${last ? " now" : ""}"></div>
            <div class="ver${last ? " now" : ""}">${label(v, bump)}</div>
        </div>`;
    }).join("");

    return `
<style>
    ${base}
    .rail { position: absolute; left: 110px; right: 110px; top: 760px; height: 4px; border-radius: 2px;
        background: linear-gradient(90deg, rgba(255,255,255,.08), ${GREEN}); }
    .col { position: absolute; top: 0; height: 762px; width: 0; display: flex; flex-direction: column;
        align-items: center; justify-content: flex-end; }
    .pills { display: flex; flex-direction: column; align-items: center; gap: 10px; margin-bottom: 18px; }
    .pill { display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; border-radius: 999px;
        border: 1.5px solid; font-size: 26px; font-weight: 700; white-space: nowrap; }
    .stem { width: 3px; border-radius: 2px; background: linear-gradient(rgba(255,255,255,.35), rgba(255,255,255,.05)); }
    .dot { width: 22px; height: 22px; border-radius: 999px; margin-bottom: -11px; background: #1f2937;
        border: 3px solid rgba(255,255,255,.4); flex-shrink: 0; }
    .dot.now { width: 34px; height: 34px; margin-bottom: -17px; background: ${GREEN}; border-color: #ecfdf5;
        box-shadow: 0 0 40px rgba(52, 211, 153, .7); }
    .ver { position: absolute; top: 800px; font-size: 24px; font-weight: 600; color: #9ca3af; white-space: nowrap; }
    .ver.now { font-size: 34px; font-weight: 700; color: #f3f4f6; top: 795px; }
    .ver b { font-weight: 800; }
</style>
<div class="grid-bg"></div>
<div class="rail"></div>
${columns}
`;
}

for (const [i, v] of SERIES.entries()) {
    if (i > 0 && actualBump(SERIES[i - 1].version, v.version) !== expectedBump(v)) {
        throw new Error(`v${v.version} ne monte pas le chiffre que son contenu demande`);
    }
}

// Le cœur et les sites montrent la plus récente, l'historique les précédentes.
const list = [...SERIES].reverse();

const work = await mkdtemp(join(tmpdir(), "aurora-releases-"));
const browser = await chromium.launch();
const tab = await browser.newPage({ viewport: { width: WIDTH, height: HEIGHT } });

for (const [name, body] of [["tour-releases", pipeline(list)], ["tour-release-notes", timeline(SERIES)]]) {
    const html = join(work, `${name}.html`);
    await writeFile(
        html,
        `<!doctype html><meta charset="utf-8"><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap">${body}`,
        "utf8",
    );
    await tab.goto(pathToFileURL(html).href, { waitUntil: "networkidle" });
    await tab.evaluate(() => document.fonts.ready);
    await tab.screenshot({ path: join(shotsDir, `${name}.png`) });
    console.log(`+ ${name} -> var/screenshots/${name}.png`);
}

await browser.close();
await rm(work, { recursive: true, force: true });

console.log(SERIES.map((v) => `  v${v.version} ⚡${v.breaking} +${v.added} ↑${v.improved} ✓${v.fixed}`).join("\n"));
