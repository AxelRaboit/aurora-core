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
 * Met la bibliothèque à plat, si elle ne l'est pas déjà.
 *
 * Le bouton porte le geste qu'il ferait, pas l'état où l'on est : « Tout
 * afficher à plat » quand on est par dossiers, « Afficher par dossiers »
 * quand on est à plat. Et l'état est retenu d'une visite à l'autre, donc le
 * deuxième scénario cherchait un libellé que le premier venait de faire
 * disparaître.
 */
async function flatten(page) {
    const bouton = page.getByTitle("Tout afficher à plat").first();

    if (await bouton.count() > 0) {
        await bouton.click();
        await page.waitForTimeout(1_200);
    }
}

/**
 * Une prise sur un onglet de l'éditeur de publication.
 *
 * Quatre cartes racontent chacune un onglet - l'entête, la galerie, le
 * référencement, les langues - et montraient toutes la même liste de
 * publications.
 *
 * `exact` sur le nom de l'onglet : « Types de contenu » vit dans le menu
 * latéral et contient « Contenu », donc un nom approchant attrape le menu et
 * la prise ressort sur l'onglet d'à côté - qui ressemble assez pour qu'on ne
 * le voie pas.
 */
function postTabShot(name, tab, extra) {
    return {
        name,
        path: "/backend/editorial/posts/1/edit",
        async prepare(page) {
            await page.waitForTimeout(4_000);
            await page.getByRole("button", { name: tab, exact: true }).first().click();
            await page.waitForTimeout(2_000);

            if (extra) await extra(page);
        },
    };
}

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

/**
 * Ouvrir l'espace « Réseaux sociaux », d'où partent toutes les prises d'un
 * espace.
 *
 * **L'onglet de la liste est retenu d'une visite à l'autre**, et `espaces-
 * prospects` le laisse sur Prospects juste avant. L'espace cherché est chez
 * un client, donc il n'est plus dans la liste, le clic expire au bout de
 * trente secondes et le scénario suivant tombe - seul il passait, dans la
 * série il ratait, ce qui est la signature d'un état partagé. On remet donc
 * l'onglet sur Clients avant de chercher, sans se demander où il en est.
 */
async function openSpace(page) {
    await page.getByRole("button", { name: /^Clients/ }).first().click();
    await page.waitForTimeout(1_000);

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
    {
        // La grille et sa palette : quarante-huit colonnes, les zones déjà
        // posées, et en bas tout ce qu'on peut poser. La carte énumère dix
        // sortes de zones et n'en montrait aucune.
        name: "tour-grille-palette",
        path: "/backend/editorial/posts/1/edit",
        async prepare(page) {
            await page.waitForTimeout(4_000);
            // `exact`, sinon le menu latéral gagne : « Types de contenu » contient
            // « contenu », et c'est lui que le premier résultat désigne. La
            // capture sortait alors sur l'onglet Paramétrage, qui est celui
            // d'à côté et qui ressemble assez pour qu'on ne le voie pas.
            await page.getByRole("button", { name: "Contenu", exact: true }).first().click();
            await page.waitForTimeout(2_000);
        },
    },
    // Pas de prise de l'éditeur d'une zone, et j'ai essayé trois fois.
    // L'éditeur s'ouvre sous la grille, et le défilement ne tient pas
    // jusqu'à l'obturateur : la capture ressort sur la grille, c'est-à-dire
    // en double de celle du dessus. Deux images identiques valent moins
    // qu'une seule, et celle de la palette dit déjà ce que la carte promet -
    // vingt-quatre sortes de zones à poser. À reprendre en visant le
    // conteneur qui défile vraiment, qui n'est pas la fenêtre.

    {
        // Le paramétrage d'une publication : son statut, ses dates, son type
        // et son adresse. La carte parle d'un cycle - brouillon, revue,
        // programmation, publication, archivage - et ne montrait que la liste
        // où le statut se lit, jamais l'endroit où il se décide.
        name: "tour-publications-parametrage",
        path: "/backend/editorial/posts/1/edit",
        async prepare(page) {
            // `domcontentloaded` ne suffit pas ici : l'onglet n'existe qu'une
            // fois le composant monté, et l'éditeur garde une connexion
            // ouverte en dev, donc `load` n'arrive jamais.
            await page.waitForTimeout(4_000);
            await page.getByRole("button", { name: "Paramétrage" }).first().click();
            await page.waitForTimeout(1_500);
        },
    },
    {
        // L'historique : ce qui a été enregistré avant est conservé et se
        // compare. La carte le promet noir sur blanc.
        //
        // La prise n'a été possible qu'après avoir donné des révisions au
        // jeu de démonstration : les fixtures écrivent les publications en
        // direct, alors qu'une révision naît d'un enregistrement passé par
        // le gestionnaire. La modale s'ouvrait donc sur « Aucune version
        // enregistrée pour le moment », soit l'image qui réussit sans rien
        // montrer.
        name: "tour-publications-historique",
        path: "/backend/editorial/posts/1/edit",
        async prepare(page) {
            await page.waitForTimeout(4_000);
            await page.getByRole("button", { name: "Actions" }).first().click();
            await page.waitForTimeout(800);
            await page.getByRole("button", { name: "Historique" }).first().click();
            await page.waitForTimeout(2_500);
        },
    },
    {
        // La corbeille, qui traverse les modules : supprimer n'efface pas
        // tout de suite, et l'écran dit combien de temps il reste.
        name: "tour-publications-corbeille",
        path: "/backend/trash",
    },
    postTabShot("tour-entete-reglages", "En-tête"),
    postTabShot("tour-seo-onglet", "Moteurs de recherche"),
    postTabShot("tour-galerie-onglet", "Galerie"),
    {
        // La même publication dans une autre langue : même disposition,
        // mots différents. La carte dit « un onglet par langue » et montrait
        // une page en français.
        name: "tour-multilingue-en",
        path: "/backend/editorial/posts/1/edit",
        async prepare(page) {
            await page.waitForTimeout(4_000);
            await page.getByRole("button", { name: "en", exact: true }).first().click();
            await page.waitForTimeout(2_000);
        },
    },
    {
        // Le tableau de bord sur un autre module que l'éditorial : la carte
        // promet « un panneau par module actif, chacun avec ses chiffres ».
        name: "tour-dashboard-modules",
        path: "/backend",
        async prepare(page) {
            await page.waitForTimeout(3_000);
            await page.getByRole("button", { name: "GED", exact: true }).first().click();
            await page.waitForTimeout(1_500);
        },
    },
    {
        // La recherche ouverte, avec ses résultats groupés par nature.
        name: "tour-recherche-resultats",
        path: "/backend",
        async prepare(page) {
            await page.waitForTimeout(3_000);
            await page.keyboard.press("Control+K");
            await page.waitForTimeout(1_200);
            // « client » et non le mot par défaut : il touche trois natures à
            // la fois - une entrée de navigation, un média, des événements -
            // et c'est le groupement que la carte promet. « aurora » ne
            // ramenait que des médias, soit une liste et pas une démonstration.
            await page.keyboard.type("client", { delay: 60 });
            await page.waitForTimeout(2_500);
        },
    },
    {
        // La modération des commentaires et ses trois états.
        name: "tour-commentaires-moderation",
        path: "/backend/editorial/comments",
    },
    {
        // Les demandes reçues par un formulaire : la carte parle de ce qu'un
        // formulaire sait faire et s'arrêtait à ses champs.
        // Les demandes vivent **sous** les champs, sur la page du
        // formulaire.
        //
        // Deux erreurs avant d'y arriver, et la seconde est partie en
        // production : chercher un onglet « Réponses » qui n'existe pas, puis
        // viser `/submissions`, qui est l'API JSON et non un écran. La prise
        // était un dump de JSON brut, et elle a illustré la carte publique
        // pendant une heure. Une adresse qui répond n'est pas une page.
        name: "tour-formulaire-reponses",
        path: "/backend/editorial/forms/1",
        async prepare(page) {
            await page.waitForTimeout(3_000);
            await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
            await page.waitForTimeout(1_200);
        },
    },
    {
        // Un menu ouvert, avec ses entrées imbriquées : la carte décrit ce
        // qu'une entrée peut viser et montrait la liste des menus.
        name: "tour-menus-entrees",
        path: "/backend/editorial/menus",
        async prepare(page) {
            await page.waitForTimeout(2_500);
            await page.getByRole("link", { name: /Navigation principale/ }).first().click();
            await page.waitForTimeout(2_500);
        },
    },
    {
        // Les termes d'une taxonomie : la carte oppose l'arbre des catégories
        // et les étiquettes à plat, sans montrer ni l'un ni l'autre.
        name: "tour-taxonomies-termes",
        path: "/backend/editorial/taxonomies",
        async prepare(page) {
            await page.waitForTimeout(2_500);
            await page.getByRole("link", { name: /Catégories/ }).first().click();
            await page.waitForTimeout(2_500);
        },
    },
    {
        // Les champs d'un type de contenu : c'est ce que la carte détaille,
        // et la liste des types n'en dit rien.
        name: "tour-types-champs",
        path: "/backend/editorial/post-types",
        async prepare(page) {
            await page.waitForTimeout(2_500);
            await page.getByRole("link", { name: /Article/ }).first().click();
            await page.waitForTimeout(2_500);
        },
    },
    {
        // La palette d'un thème : la carte parle de couleurs déduites et de
        // contrastes, donc c'est là qu'il faut regarder.
        name: "tour-themes-palette",
        path: "/backend/configuration/themes",
        async prepare(page) {
            await page.waitForTimeout(2_500);
            await page.getByRole("link", { name: /Modifier|Éditer/ }).first().click()
                .catch(() => {});
            await page.waitForTimeout(2_500);
        },
    },
    {
        // Les privilèges d'un compte, écran par écran : c'est la promesse
        // centrale de la carte, et elle ne montrait que la liste des comptes.
        name: "tour-privileges",
        path: "/backend/platform/users",
        async prepare(page) {
            await page.waitForTimeout(2_500);
            await page.getByTitle(/^Actions pour Jean Martin/).first().click();
            await page.waitForTimeout(1_000);
            await page.getByRole("button", { name: /^Privilèges/ }).first().click();
            await page.waitForTimeout(2_500);
        },
    },
    {
        // Les réglages et leurs onglets : la carte parle de ce qui se règle
        // sans montrer où.
        name: "tour-reglages-onglets",
        path: "/backend/configuration/settings",
        async prepare(page) {
            await page.waitForTimeout(2_500);
        },
    },
    {
        // Les catégories : une par nature de document, celle qui décide où un
        // fichier est rangé. La carte en parle et ne la montrait pas.
        name: "tour-mediatheque-categories",
        path: "/backend/ged/categories",
    },
    {
        // Les étiquettes, qui traversent les catégories : un même document
        // peut en porter autant qu'il veut, là où il n'a qu'une catégorie.
        name: "tour-mediatheque-etiquettes",
        path: "/backend/ged/tags",
    },
    {
        // La carte promet « le rendu à côté de la source », et son texte de
        // remplacement décrit « une note, son rendu à côté, ses étiquettes et
        // ses liens ». La prise montrait la bibliothèque : des dossiers et des
        // vignettes, c'est-à-dire la seule chose que la carte ne dit pas.
        //
        // Par le nom et non par un identifiant : les fixtures renumérotent à
        // chaque rechargement. « Cabinet Verrier » est la seule note de la
        // démonstration qui réunisse les trois : un bandeau, un lien wiki dans
        // son texte, et un lien entrant, donc un panneau qui montre quelque
        // chose. « Sommaire des clients » a une source plus riche mais rien ne
        // pointe vers elle : le panneau s'ouvrait sur « Aucun lien entrant »,
        // au milieu d'une image qui sert à montrer que les notes se relient.
        name: "tour-notes",
        path: "/backend/notes/markdown",
        async prepare(page) {
            // Un lien et non un bouton : dans la bande « Récemment modifiées »,
            // chaque note est une ancre vers son adresse.
            await page.getByRole("link", { name: /^Cabinet Verrier/ }).first().click();
            await page.waitForTimeout(2_500);
            await page.getByTitle("Édition + aperçu").first().click();
            await page.waitForTimeout(1_000);

            // Le volet d'écriture est rétréci avant d'ouvrir les liens.
            // Sa largeur est retenue d'une visite à l'autre, et la valeur
            // par défaut laissait au rendu deux cent trente pixels une fois
            // le panneau sorti : le tableau de la note y était coupé en
            // plein milieu d'un en-tête, ce qui se lit comme un défaut
            // d'affichage et non comme une colonne qui continue.
            await page.evaluate(() => {
                localStorage.setItem("aurora.notes.markdown.editorWidth", "380");
            });
            await page.reload({ waitUntil: "domcontentloaded" });
            await page.waitForTimeout(2_500);

            await page.getByTitle("Afficher les liens entrants").first().click();
            await page.waitForTimeout(1_500);
        },
    },
    {
        // La bibliothèque, à plat et en mosaïque : chaque note montre le
        // début de son contenu en petit, comme sur une étagère. C'est la
        // première chose qu'on voit en ouvrant le module, et la carte n'en
        // montrait rien.
        name: "tour-notes-bibliotheque",
        path: "/backend/notes/markdown",
        async prepare(page) {
            await flatten(page);
        },
    },
    {
        // La même bibliothèque en vue cartes : la grille dense, sans extrait.
        // Trois façons de regarder le même carnet, et une seule était
        // photographiée.
        name: "tour-notes-vue-cartes",
        path: "/backend/notes/markdown",
        async prepare(page) {
            await flatten(page);
            await page.getByTitle("Cartes").first().click();
            await page.waitForTimeout(1_200);
        },
    },
    {
        // Et en liste : titre, étiquettes, dossier, date. C'est la vue de
        // celui qui cherche une note précise plutôt que de parcourir.
        name: "tour-notes-vue-liste",
        path: "/backend/notes/markdown",
        async prepare(page) {
            await flatten(page);
            await page.getByTitle("Liste").first().click();
            await page.waitForTimeout(1_200);
        },
    },
    {
        // L'habillage d'une note, où les deux choses se décident au même
        // endroit : l'image d'entête, cherchée chez Pexels et recadrée à la
        // molette, et les six apparences. Une seule fenêtre pour les deux,
        // donc une seule image.
        name: "tour-notes-entete",
        path: "/backend/notes/markdown",
        async prepare(page) {
            await page.getByRole("link", { name: /^Sommaire des clients/ }).first().click();
            await page.waitForTimeout(2_500);
            await page.getByTitle(/^Actions pour/).first().click();
            await page.waitForTimeout(800);
            await page.getByRole("button", { name: "Image d'entête" }).first().click();
            await page.waitForTimeout(2_000);
        },
    },
    {
        // Le graphe, que le texte de la carte promet depuis le début sans
        // l'avoir jamais montré. Il s'ouvre depuis le menu d'une note, donc
        // il faut en ouvrir une d'abord.
        name: "tour-notes-graphe",
        path: "/backend/notes/markdown",
        async prepare(page) {
            await page.getByRole("link", { name: /^Sommaire des clients/ }).first().click();
            await page.waitForTimeout(2_500);
            await page.getByTitle(/^Actions pour/).first().click();
            await page.waitForTimeout(700);
            await page.getByRole("button", { name: "Ouvrir le graphe" }).first().click();
            // La construction est animée : elle place les nœuds avant de se
            // stabiliser, et photographier trop tôt donne une pelote.
            await page.waitForTimeout(4_000);
        },
    },
    {
        // La page publique d'une note : l'autre promesse du texte, « montrer
        // une note à quelqu'un qui n'a pas de compte ».
        //
        // L'adresse est demandée au serveur plutôt qu'écrite ici : le jeton
        // est tiré au hasard à chaque chargement des fixtures, donc une
        // adresse en dur serait morte au premier `make demo`.
        name: "tour-notes-partage",
        path: "/backend/notes/markdown",
        async prepare(page) {
            const url = await page.evaluate(async () => {
                const r = await fetch("/backend/notes/markdown/shares/1", { headers: { Accept: "application/json" } });
                const j = await r.json();

                return j?.links?.[0]?.url ?? null;
            });

            if (!url) throw new Error("aucun lien de partage dans la démonstration");

            await page.goto(url, { waitUntil: "networkidle" });
            await page.waitForTimeout(2_000);
        },
    },
    {
        // La vue de lecture : une adresse qui n'affiche **que** la note, sans
        // le menu ni le fil d'Ariane, pour qui a un compte.
        //
        // La prise montrait l'aperçu de l'éditeur, c'est-à-dire la même
        // fenêtre que la première image, en mode rendu. Deux photos du même
        // écran, et la vue qui existe précisément pour montrer une note nue
        // n'était sur aucune. Elle se distingue aussi de la page de partage,
        // qui est l'autre bout : celle-ci se lit sans compte et porte la
        // liste des notes liées quand le lien les emporte.
        //
        // Par l'adresse plutôt que par un clic : le bouton qui y mène est
        // dans le menu de la note, et ouvrir un menu pour photographier ce
        // qu'il y a derrière allonge le scénario sans rien prouver de plus.
        name: "tour-notes-apparence",
        path: "/backend/notes/markdown",
        async prepare(page) {
            await page.getByRole("link", { name: /^Sommaire des clients/ }).first().click();
            await page.waitForTimeout(2_500);

            const id = /\/markdown\/(\d+)/.exec(page.url())?.[1];

            if (undefined === id) throw new Error("la note ne s'est pas ouverte");

            await page.goto(`${BASE_URL}/backend/notes/markdown/${id}/read`, { waitUntil: "networkidle" });
            await page.waitForTimeout(2_000);
        },
    },
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

            // Attendre le bouton plutôt que compter jusqu'à mille cinq cents.
            //
            // Ce scénario passait seul et tombait dans la série complète, sur
            // un `click` expiré au bout de trente secondes : une pause fixe
            // suffit sur une machine au repos et plus sur la même machine au
            // soixante-huitième écran. L'attente porte donc sur ce qu'on
            // attend vraiment, l'application montée et son bouton présent.
            const ouvrir = page.getByRole("button", { name: "Créer un lien" }).first();
            await ouvrir.waitFor({ state: "visible", timeout: 30_000 });
            await ouvrir.click();
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
    {
        // Une seconde page publique, celle qui porte le formulaire : elle
        // montre une autre composition et le rendu d'un formulaire côté
        // visiteur.
        //
        // J'ai d'abord voulu le même écran en mode clair, la carte promettant
        // « un mode sombre et un mode clair ». Deux essais pour rien : le
        // site public n'inclut pas le script d'amorçage du back-office, donc
        // `aurora-theme` n'y est lu par personne, et il ne suit pas non plus
        // `prefers-color-scheme` - la palette est celle du thème actif, et
        // celui de la démonstration est sombre. Il faudrait changer de thème
        // pour le montrer, ce qui est un autre sujet.
        name: "tour-site-public-contact",
        path: "/fr/page/contact",
        anonymous: true,
        async prepare(page) {
            await page.waitForTimeout(2_000);
        },
    },
    {
        // Les notes d'une version, en français et du point de vue de ce qui
        // change à l'écran : c'est ce que la carte promet, et la liste des
        // versions ne le montre pas.
        //
        // Sans session, comme la liste : c'est GitHub, pas l'application.
        name: "tour-release-notes",
        url: "https://github.com/AxelRaboit/aurora-core/releases/latest",
        anonymous: true,
        async prepare(page) {
            await page.waitForTimeout(2_500);
            await page.getByRole("button", { name: /Accept|Reject|Refuser/ }).first().click().catch(() => {});
            await page.waitForTimeout(1_500);
        },
    },
    {
        // Les permissions, côté développeur : la carte parle de ce que
        // l'outil fait par défaut, et l'audit seul n'en montrait qu'une part.
        name: "tour-permissions",
        path: "/dev/dashboard/permissions",
        async prepare(page) {
            await page.waitForTimeout(2_500);
        },
    },

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
            /* La version sous le logo. Sur une instance locale elle vaut
               « dev », et c'est la seule chose de ces images qui dise à un
               client qu'il regarde une machine de développement plutôt que
               le produit. En production elle porterait un numéro, qui ne lui
               apprend rien non plus. */
            [data-app-version] { display: none !important; }
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

/**
 * Refuser de photographier ce qui n'est pas la page attendue.
 *
 * Deux prises sont parties en production sans que personne ne s'en aperçoive :
 * un dump de JSON brut, parce que `/submissions` est l'API et non un écran,
 * et la trace d'exception Symfony d'un 404, chemin de disque compris, sur la
 * carte qui présente le site public. Playwright réussit dans les deux cas :
 * l'adresse répond, donc `goto` est content, et le fichier s'écrit.
 *
 * **Une adresse qui répond n'est pas une page.** On vérifie donc le code, le
 * type de contenu, et la signature de la page d'erreur de Symfony, et on
 * échoue avant d'écrire plutôt que de laisser relire l'image à quelqu'un.
 */
async function assertPage(target, response, address) {
    const status = response?.status();

    if (undefined !== status && status >= 400) {
        throw new Error(`${address} répond ${status}`);
    }

    const type = response?.headers()["content-type"] ?? "";

    if ("" !== type && !type.includes("text/html")) {
        throw new Error(`${address} renvoie ${type.split(";")[0]}, pas une page`);
    }

    const symfony = await target.evaluate(
        () => null !== document.querySelector(".exception-summary, #traces-text, .sf-reset .exception"),
    );

    if (symfony) {
        throw new Error(`${address} affiche une exception Symfony`);
    }
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
        const response = await target.goto(address, {
            waitUntil: undefined === shot.url ? "networkidle" : "domcontentloaded",
        });

        await assertPage(target, response, address);
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
