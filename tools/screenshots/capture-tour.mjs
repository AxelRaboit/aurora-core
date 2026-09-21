/**
 * Captures the screenshots the public tour of Aurora is illustrated with.
 *
 * The tour at /fr/page/aurora is one card per subject, each with a picture of
 * the screen it describes. Those pictures were taken by hand, one at a time,
 * which is why two of them are duplicates of the same screen and why the
 * accounting module has none at all: its three screens could not even be
 * opened locally until the route-template bug was fixed.
 *
 * So this exists to make them reproducible. Point it at a local instance
 * loaded with `make demo`, run it, and every card's picture is regenerated at
 * the same size, in the same theme, with the same demo data.
 *
 * **Local only, and demo data only.** These images go on a public page. A
 * capture taken against production would put a real customer's name and SIRET
 * on it, the audit log would carry a real address, and the sidebar footer
 * would show a personal email. The fixtures exist precisely so that none of
 * that is ever in frame.
 *
 * Authentication reuses the fixture account the repository's own end-to-end
 * tests sign in with (`dev@aurora.app`, seeded by `AppFixtures` with a
 * password that is a literal in this public repository). Nothing secret is
 * handled here, and nothing but a local instance will accept it.
 *
 * Usage:
 *   node tools/screenshots/capture-tour.mjs                  # everything
 *   node tools/screenshots/capture-tour.mjs contracts trames  # by name
 *
 * Output: var/screenshots/<name>.png, git-ignored.
 */

import { chromium } from "@playwright/test";
import { mkdir } from "node:fs/promises";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const root = resolve(dirname(fileURLToPath(import.meta.url)), "../..");
const outDir = resolve(root, "var/screenshots");

const BASE_URL = process.env.TOUR_BASE_URL ?? "http://127.0.0.1:8000";
const EMAIL = process.env.TOUR_EMAIL ?? "dev@aurora.app";
const PASSWORD = process.env.TOUR_PASSWORD ?? "password";

// The word typed into the search palette. A parameter because the point of
// that picture is a query that answers in several sections at once, and which
// word does that depends on what the demo fixtures happen to hold.
const SEARCH_QUERY = process.env.TOUR_SEARCH_QUERY ?? "aurora";

// The size every existing tour capture already has. Kept identical so a
// regenerated picture drops into a card without the crop moving.
const VIEWPORT = { width: 1600, height: 1000 };

/**
 * One entry per picture.
 *
 * `prepare` runs after the page has loaded and before the shutter: it is where
 * a panel gets opened or a query typed, because several of these screens only
 * say what they do once something is on them.
 */
/**
 * Ouvre une publication de la démonstration.
 *
 * Par son adresse et non par un clic dans la liste : une ligne n'est pas un
 * lien, et son menu d'actions est un popover sans rôle à viser.
 */
const DEMO_POST_ID = process.env.TOUR_POST_ID ?? "1";

async function openPost(page) {
    // `domcontentloaded` et non `load` : l'éditeur garde une connexion ouverte
    // en dev, donc l'événement `load` n'arrive jamais.
    await page.goto(`${BASE_URL}/backend/editorial/posts/${DEMO_POST_ID}/edit`, { waitUntil: "domcontentloaded" });
    await page.waitForTimeout(4_000);
}

/** L'éditeur d'une publication, sur l'onglet demandé. */
const postTab = (name) => async (page) => {
    await openPost(page);
    await page.getByRole("tab", { name }).first().click().catch(async () => {
        await page.getByRole("button", { name }).first().click();
    });
    await page.waitForTimeout(1_500);
};

/**
 * Un espace de la démonstration, sur la vue demandée.
 *
 * Par la liste et nommément, pour la raison que `tour-espaces-clients`
 * explique : les identifiants changent à chaque rechargement des fixtures, et
 * la démonstration porte des espaces vides qui photographient une page qui
 * réussit sans rien montrer.
 *
 * La vue ouverte est retenue d'une visite à l'autre, donc chaque prise
 * reclique la sienne au lieu de compter sur ce qui était affiché avant.
 */
const spaceView = (view) => async (page) => {
    await openSpace(page);
    await page.getByRole("button", { name: view, exact: true }).first().click();
    await page.waitForTimeout(2_000);
};

async function openSpace(page) {
    await page.getByRole("link", { name: /Réseaux sociaux/ }).first().click();
    await page.waitForTimeout(3_500);
}

/** Les espaces, d'où toutes les prises d'un espace partent. */
const SPACES = "/backend/studio/spaces";

/**
 * Une capture par carte du tour, nommée comme le document qu'elle remplace.
 *
 * **Le nom est le lien avec la production.** Chaque carte de /fr/page/aurora
 * affiche un document de la médiathèque appelé `tour-<nom>.png` ; une capture
 * qui porte le même nom se remplace par `aurora:ged:replace`, et la carte n'a
 * rien à savoir. Tenir une table de correspondance à côté serait une seconde
 * source de vérité à garder en phase.
 *
 * `prepare` tourne après le chargement et avant le déclencheur : c'est là
 * qu'un panneau s'ouvre ou qu'une requête se tape, parce que plusieurs de ces
 * écrans ne disent ce qu'ils font qu'une fois quelque chose dessus.
 */
const SHOTS = [
    { name: "tour-dashboard", path: "/backend" },
    {
        name: "tour-recherche",
        path: "/backend",
        async prepare(page) {
            // Aucun raccourci clavier ne l'ouvre : le bouton est la seule
            // porte, et il s'annonce, ce qui le rend trouvable ici.
            await page.getByRole("button", { name: "Rechercher…" }).click();

            const field = page.getByPlaceholder(/Rechercher des contenus/);
            await field.waitFor({ state: "visible", timeout: 5_000 });
            await field.fill(SEARCH_QUERY);
            await page.waitForTimeout(1_500);
        },
    },

    { name: "tour-publications", path: "/backend/editorial/posts" },
    { name: "tour-grille", path: "/backend", prepare: postTab(/^Contenu$/) },
    { name: "tour-entete", path: "/backend", prepare: postTab(/En-tête/) },
    { name: "tour-seo", path: "/backend", prepare: postTab(/Moteurs/) },
    { name: "tour-galerie", path: "/backend", prepare: postTab(/Galerie/) },
    {
        name: "tour-traductions",
        path: "/backend",
        // L'espagnol plutôt que le français : la carte parle d'une seule mise
        // en page pour plusieurs langues, et c'est la langue traduite qui le
        // montre. Le sélecteur de langue est au-dessus des onglets.
        async prepare(page) {
            await openPost(page);
            await page.getByRole("button", { name: "es", exact: true }).first().click();
            await page.waitForTimeout(2_000);
            await page.getByRole("tab", { name: /Paramétrage/ }).first().click().catch(() => {});
            await page.waitForTimeout(1_200);
        },
    },

    { name: "tour-types", path: "/backend/editorial/post-types" },
    { name: "tour-taxonomies", path: "/backend/editorial/taxonomies" },
    { name: "tour-menus", path: "/backend/editorial/menus" },
    { name: "tour-commentaires", path: "/backend/editorial/comments" },
    {
        name: "tour-formulaire-champs",
        path: "/backend/editorial/forms",
        // Les champs d'un formulaire, pas la liste des formulaires : c'est
        // l'écran où l'on compose, et donc celui dont la carte parle.
        async prepare(page) {
            await page.getByRole("link", { name: /Modifier|Éditer/ }).first().click()
                .catch(async () => {
                    await page.locator("a[href*='/backend/editorial/forms/']").first().click();
                });
            await page.waitForTimeout(3_000);
        },
    },

    { name: "tour-mediatheque-grille", path: "/backend/ged/documents" },
    /**
     * La fiche d'un document : ses métadonnées et son historique de versions.
     *
     * La seconde image de la carte médiathèque. Elle illustrait déjà la page
     * et ne se refaisait pas : elle datait d'une semaine de plus que toutes
     * les autres.
     */
    {
        name: "tour-mediatheque-document",
        path: "/backend/ged/documents",
        async prepare(page) {
            // Cherché plutôt que cliqué dans la liste : la médiathèque de
            // démonstration tient sur deux pages, et celui-ci n'est pas
            // toujours sur la première. C'est celui-là qu'on veut, parce
            // qu'il porte trois versions et que la carte en parle.
            await page.getByPlaceholder(/Rechercher un document/).fill("Visuel de campagne");
            await page.waitForTimeout(2_000);
            await page.getByText("Visuel de campagne", { exact: false }).first().click();
            await page.waitForTimeout(2_500);
        },
    },
    { name: "tour-notes", path: "/backend/notes/markdown" },
    { name: "tour-calendrier", path: "/backend/planning/calendar" },

    { name: "tour-contrats", path: "/backend/studio/contracts" },
    { name: "tour-trames", path: "/backend/studio/contract-templates" },
    { name: "tour-clients", path: "/backend/studio/customers" },
    {
        name: "tour-avenant-scelle",
        // Le contresigné : le seul état qui montre à la fois le sceau, les deux
        // signatures et la chaîne d'avenants. Atteint par le menu d'une ligne
        // plutôt que par un identifiant en dur, qui dépend de ce que la base
        // contenait déjà quand les fixtures ont tourné.
        path: "/backend/studio/contracts",
        async prepare(page) {
            const row = page.getByRole("row").filter({ hasText: "Contresigné" }).first();
            await row.getByRole("button", { name: /^Actions pour/ }).click();
            await page.locator("a[href*='/backend/studio/contracts/']").first().click();
            await page.waitForLoadState("networkidle");
        },
    },

    /**
     * Le tableau d'un espace client.
     *
     * Par la liste plutôt que par une adresse : les identifiants changent à
     * chaque rechargement des fixtures, et un parcours qui code un
     * `/workspace/8` en dur photographie une page d'erreur le lendemain.
     *
     * **Nommément, et pas le premier venu.** La démonstration porte plusieurs
     * espaces dont un vide ; ouvrir le premier de la liste donnait cinq
     * colonnes qui disent toutes « Rien ici pour le moment », c'est-à-dire
     * une capture qui réussit et ne montre rien.
     *
     * Puis un clic sur Contenus : la vue ouverte est retenue d'une visite à
     * l'autre, donc l'espace peut s'ouvrir sur Notes selon ce qui a été
     * regardé avant.
     */
    { name: "tour-espaces-clients", path: SPACES, prepare: spaceView("Contenus") },

    /**
     * Les autres vues du même espace.
     *
     * **Elles illustrent déjà la carte, et n'étaient pas reproductibles.**
     * Prises à la main une fois, elles ont vieilli sans que rien ne le dise :
     * celle du côté client montrait encore une page qui empilait tout et un
     * invité signé de son adresse e-mail, deux versions après que l'une et
     * l'autre aient disparu. C'est exactement ce que ce fichier existe pour
     * éviter.
     */
    { name: "espace-calendrier", path: SPACES, prepare: spaceView("Calendrier") },
    { name: "espace-fichiers", path: SPACES, prepare: spaceView("Fichiers") },
    { name: "espace-discussion", path: SPACES, prepare: spaceView("Discussion") },
    { name: "espace-notes", path: SPACES, prepare: spaceView("Notes") },
    { name: "espace-informations", path: SPACES, prepare: spaceView("Informations") },
    { name: "espace-liens", path: SPACES, prepare: spaceView("Liens") },

    /** Une fiche ouverte : le titre, la date, ses fichiers et son fil. */
    {
        name: "espace-une-fiche",
        path: SPACES,
        async prepare(page) {
            await openSpace(page);
            await page.getByRole("button", { name: "Contenus", exact: true }).first().click();
            await page.waitForTimeout(1_800);
            await page.getByText("Portrait de l'équipe", { exact: true }).first().click();
            await page.waitForTimeout(1_800);
        },
    },

    /**
     * Un espace ouvert pour un prospect.
     *
     * L'onglet a son propre compteur, et c'est ce que la capture montre : on
     * travaille avec quelqu'un avant qu'il signe.
     */
    {
        name: "espaces-prospects",
        path: SPACES,
        async prepare(page) {
            await page.getByRole("button", { name: /^Prospects/ }).first().click();
            await page.waitForTimeout(1_500);
        },
    },

    /**
     * La page que le client ouvre, par une vraie adresse.
     *
     * **Un lien émis ici même, et non l'aperçu du studio.** L'aperçu porte un
     * bandeau qui prévient que ce n'est pas ce que le client a reçu : vrai
     * dans l'application, trompeur sur une carte qui promet de montrer ce que
     * le client voit. Le lien se crée donc comme le studio le crée, et
     * l'adresse se lit là où l'écran l'affiche une fois - c'est la seule fois
     * où elle existe en clair.
     */
    {
        name: "espace-cote-client",
        path: SPACES,
        async prepare(page) {
            await openSpace(page);

            const espace = new URL(page.url());
            await page.goto(`${espace.origin}${espace.pathname}/access`, { waitUntil: "networkidle" });
            await page.waitForTimeout(1_500);

            await page.getByRole("button", { name: "Créer un lien" }).first().click();
            await page.waitForTimeout(1_000);

            // Par l'exemple du champ et non par son libellé : les champs de
            // cette modale n'ont pas d'identifiant, donc rien ne relie le
            // `<label>` à son `<input>` pour un outil qui lit la page.
            await page.getByPlaceholder("camille@societe.fr").fill("camille@atelier-dupont.example.com");
            await page.getByPlaceholder(/^Camille, /).fill("Camille, gérante");

            await page.getByRole("button", { name: "Créer un lien" }).last().click();
            await page.waitForTimeout(2_500);

            const adresse = (await page.locator("code").first().innerText()).trim();
            await page.goto(adresse, { waitUntil: "networkidle" });
            await page.waitForTimeout(2_500);
        },
    },

    { name: "tour-utilisateurs", path: "/backend/platform/users" },
    { name: "tour-audit", path: "/dev/dashboard/audit" },
    { name: "tour-themes", path: "/backend/configuration/themes" },
    { name: "tour-reglages", path: "/backend/configuration/settings/general" },

    {
        name: "tour-calendrier-semaine",
        path: "/backend/planning/calendar",
        async prepare(page) {
            await page.getByRole("button", { name: /^Semaine$/ }).click();
            await page.waitForTimeout(1_000);

            // La grille s'ouvre sur l'heure courante, donc une capture prise
            // le soir montre un après-midi vide alors que les événements de
            // démonstration sont le matin. La molette au-dessus de la grille
            // est ce que le composant écoute ; fixer `scrollTop` sur un
            // élément deviné, non. Remontée à fond d'abord, puis descendue
            // d'un nombre fixe : descendre d'un delta seul atterrirait
            // ailleurs selon l'heure de la prise.
            await page.mouse.move(1000, 600);
            await page.mouse.wheel(0, -2_000);
            await page.waitForTimeout(300);
            await page.mouse.wheel(0, 530);
            await page.waitForTimeout(600);
        },
    },

    /**
     * Le site public, vu sans session.
     *
     * Le bandeau d'administration s'affiche au-dessus d'un site quand on le
     * visite connecté, et il n'a rien à faire sur une image qui montre ce que
     * voit un visiteur.
     */
    { name: "tour-site-public", path: "/fr", anonymous: true },

    /**
     * Les versions publiées, chez GitHub.
     *
     * **La seule capture qui ne vient pas de l'application.** La carte parle
     * de la façon dont les versions sortent, et c'est la page des releases
     * qui le montre. Publique, donc prise sans session.
     */
    {
        name: "tour-releases",
        url: "https://github.com/AxelRaboit/aurora-core/releases",
        anonymous: true,
        async prepare(page) {
            // Le bandeau de cookies et l'invite de connexion couvrent le haut
            // de la page pour un visiteur non identifié.
            await page.getByRole("button", { name: /Accept|Reject|Refuser/ }).first().click().catch(() => {});
            await page.waitForTimeout(1_500);
        },
    },
];

async function login(page) {
    await page.goto(`${BASE_URL}/backend/platform/login`, { waitUntil: "domcontentloaded" });
    await page.locator("input[type='email'], input[name*='email']").first().fill(EMAIL);
    await page.locator("input[type='password']").first().fill(PASSWORD);
    await page.locator("button[type='submit']").first().click();
    await page.waitForURL(/\/backend/, { timeout: 15_000 });
}

/**
 * Everything that belongs to the developer rather than to the product.
 *
 * The Symfony toolbar is the obvious one. The rest is quieter and shows up
 * only once the picture is beside the others: a focus ring left on whatever
 * was clicked, and the caret blinking in a field.
 */
async function hideChrome(page) {
    await page.addStyleTag({
        content: `
            .sf-toolbar, .sf-minitoolbar, #sfToolbarMainContent, #sfToolbarClearer { display: none !important; }
            *, *::before, *::after { caret-color: transparent !important; }
            :focus-visible { outline: none !important; }
        `,
    });
}

const wanted = process.argv.slice(2);
const shots = wanted.length === 0 ? SHOTS : SHOTS.filter((shot) => wanted.includes(shot.name));

if (shots.length === 0) {
    console.error(`aucune capture nommee ${wanted.join(", ")}`);
    console.error(`disponibles : ${SHOTS.map((shot) => shot.name).join(", ")}`);
    process.exit(1);
}

await mkdir(outDir, { recursive: true });

const browser = await chromium.launch();
const context = await browser.newContext({
    viewport: VIEWPORT,
    deviceScaleFactor: 1,
    locale: "fr-FR",
    timezoneId: "Europe/Paris",
    // The tour is shown in the dark theme, which is what the twenty-eight
    // existing captures are in.
    colorScheme: "dark",
});
const page = await context.newPage();

await login(page);

/**
 * Le contexte sans session, créé seulement si une capture en demande un.
 *
 * Deux captures montrent ce que voit quelqu'un qui n'est pas connecté : le
 * site public, qui porterait sinon le bandeau d'administration, et la page
 * des versions chez GitHub. Ouvrir ce second navigateur pour les vingt autres
 * serait du temps perdu à chaque lancement.
 */
let anonymous = null;

async function anonymousPage() {
    if (null === anonymous) {
        anonymous = await browser.newContext({
            viewport: VIEWPORT,
            deviceScaleFactor: 1,
            locale: "fr-FR",
            timezoneId: "Europe/Paris",
            colorScheme: "dark",
        });
    }

    return anonymous.newPage();
}

let failed = 0;

for (const shot of shots) {
    const file = resolve(outDir, `${shot.name}.png`);
    const address = shot.url ?? `${BASE_URL}${shot.path}`;

    let target = page;

    try {
        if (true === shot.anonymous) {
            target = await anonymousPage();
        }

        // `networkidle` attend un silence que GitHub n'offre jamais tout à
        // fait ; pour une adresse externe, le document chargé suffit et le
        // `prepare` fait le reste de l'attente.
        await target.goto(address, {
            waitUntil: undefined === shot.url ? "networkidle" : "domcontentloaded",
        });
        await hideChrome(target);

        if (shot.prepare) {
            await shot.prepare(target);
            await hideChrome(target);
        }

        await target.screenshot({ path: file });
        console.log(`+ ${shot.name} -> var/screenshots/${shot.name}.png`);
    } catch (error) {
        failed += 1;
        console.error(`! ${shot.name} : ${error.message.split("\n")[0]}`);
    } finally {
        if (target !== page) {
            await target.close();
        }
    }
}

await browser.close();

process.exit(failed === 0 ? 0 : 1);
