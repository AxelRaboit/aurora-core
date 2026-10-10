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
import { createHash } from "node:crypto";
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
 * Flattens the library, if it is not flat already.
 *
 * The button carries the action it would perform, not the current state:
 * "Tout afficher à plat" when the view is by folder, "Afficher par dossiers"
 * when it is flat. And the state is remembered from one visit to the next, so
 * the second scenario looked for a label the first had just made disappear.
 */
/**
 * Opens a note by its title, found in the server's list.
 *
 * By title and not by id: the fixtures renumber on every reload. And through
 * the list rather than a click: the "Récemment modifiées" strip does not show
 * every note, and the tree collapses their folders.
 */
async function openNoteByTitle(page, title) {
    const id = await page.evaluate(async (wanted) => {
        const r = await fetch("/suite/notes/markdown/list", { headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" } });
        const j = await r.json();

        return j.notes.find((n) => wanted === n.title)?.id ?? null;
    }, title);

    if (null === id) throw new Error(`la note « ${title} » manque à la démonstration`);

    await page.goto(`${BASE_URL}/suite/notes/markdown/${id}`, { waitUntil: "domcontentloaded" });
    await page.waitForTimeout(2_500);

    return id;
}

/**
 * The note in edit mode with its preview alongside, and the "Sur cette note"
 * panel open. The editing pane is narrowed first: its width is remembered from
 * one visit to the next, and the default value left the preview two hundred
 * and thirty pixels once the panel was out, so the note's table was cut right
 * in the middle of a header.
 */
/** The notebook's search screen (4.7.0), from the library, on `query`. */
async function openNotesSearch(page, query) {
    await page.locator("[data-library-search-all]").first().click();
    await page.locator("[data-note-search-field]").first().fill(query);
    await page.locator("[data-note-search-result]").first().waitFor();
    await page.waitForTimeout(800);
}

async function openNoteWithPanel(page, title) {
    await openNoteByTitle(page, title);
    await page.getByTitle("Édition + aperçu").first().click();
    await page.waitForTimeout(800);
    await page.evaluate(() => {
        localStorage.setItem("aurora.notes.markdown.editorWidth", "420");
    });
    await page.reload({ waitUntil: "domcontentloaded" });
    await page.waitForTimeout(2_500);
    await page.getByTitle("Afficher le plan et les liens").first().click();
    await page.waitForTimeout(1_200);
}

/**
 * The address of the demo's live writing link on a note (4.3.0), asked from
 * the server: tokens are drawn at random on every fixture load.
 */
async function liveLinkUrl(page, noteId) {
    const url = await page.evaluate(async (id) => {
        const r = await fetch(`/suite/notes/markdown/shares/${id}`, { headers: { Accept: "application/json" } });
        const j = await r.json();

        return (j?.links ?? []).find((link) => link.canWrite && link.coediting)?.url ?? null;
    }, noteId);

    if (!url) throw new Error("aucun lien en direct dans la démonstration (fixture writableLinkFor)");

    return url;
}

/**
 * Puts the caret of a co-editing field after `marker`, the way a click would,
 * so that the others see it where it means something rather than at the top.
 * The arrow keys afterwards are what the room listens to.
 */
async function placeCaret(field, marker) {
    await field.click();
    const found = await field.evaluate((element, wanted) => {
        const at = element.value.indexOf(wanted);
        if (at < 0) return false;
        element.focus();
        element.setSelectionRange(at + wanted.length, at + wanted.length);

        return true;
    }, marker);

    if (!found) throw new Error(`« ${marker} » manque au texte de la note`);

    await field.press("ArrowRight");
    await field.press("ArrowLeft");
}

/** The days the journal shot wrote, removed after it. */
let journalNotes = [];

/**
 * The guest of the live link, in a browser of their own with no session: the
 * room needs two people, and a guest is the case the link exists for.
 */
let coeditGuest = null;

async function openCoeditGuest(url) {
    coeditGuest = await browser.newContext({
        viewport: VIEWPORT,
        deviceScaleFactor: 1,
        locale: "fr-FR",
        timezoneId: "Europe/Paris",
        colorScheme: "dark",
    });
    const guest = await coeditGuest.newPage();
    await guest.goto(url, { waitUntil: "networkidle" });
    await guest.locator("[data-share-live-badge]").waitFor({ timeout: 15_000 });

    return guest;
}

/**
 * Leaves the room before closing: a context closed outright never says it is
 * leaving, and the guest stayed in the room for fifty seconds, a second "IN"
 * on the next shot.
 */
async function closeCoeditGuest() {
    if (null !== coeditGuest) {
        for (const tab of coeditGuest.pages()) {
            await tab.goto("about:blank");
        }
        await coeditGuest.close();
        coeditGuest = null;
    }
}

async function flatten(page) {
    const bouton = page.getByTitle("Tout afficher à plat").first();

    if (await bouton.count() > 0) {
        await bouton.click();
        await page.waitForTimeout(1_200);
    }
}

/**
 * Scrolls the page so that `element` starts `top` pixels from the top.
 *
 * For the blocks of a long public page: `scrollIntoViewIfNeeded` pins them to
 * the edge, under the sticky header, or leaves them where they are if they
 * barely overflow. Measured afterwards, because a block that opens (the
 * appointment form) has changed height in the meantime.
 */
/**
 * Hides the grid zones that end above the one being shot.
 *
 * A block framed in the middle of the demo's long pages had the bottom of
 * its neighbour above in the picture: a world map over the booking, the
 * axis of a chart over the poll. Moving the block up does not fix it: the
 * public header shows or hides with the scroll direction, so it either
 * covers the block's title or uncovers the neighbour. Hidden, not removed:
 * the page keeps its layout, and the space reads as the page background.
 */
async function hideZonesAbove(element) {
    await element.evaluate((node) => {
        const grid = node.closest(".aurora-grid");
        const zone = [...grid.children].find((child) => child.contains(node));
        const top = zone.getBoundingClientRect().top;
        for (const child of grid.children) {
            if (child.getBoundingClientRect().bottom <= top + 1) child.style.visibility = "hidden";
        }
    });
}

/**
 * Opens a row's action sheet and picks one of its actions.
 *
 * The sheet is a window of its own, outside `main`, and its entries carry a
 * description under their label: matched on the start of the name. An entry
 * that leads somewhere ("Modifier" on a post) is a link, not a button.
 */
async function rowAction(page, row, action) {
    await page.locator("main").getByTitle(row).first().click();
    const sheet = page.locator(".fixed.inset-0.z-50");
    await sheet.getByRole("button", { name: action }).or(sheet.getByRole("link", { name: action })).first().click();
    await page.waitForTimeout(1_500);
}

async function placeAt(page, element, top) {
    await element.scrollIntoViewIfNeeded();
    const box = await element.boundingBox();
    await page.evaluate((delta) => window.scrollBy(0, delta), box.y - top);
    // Zones slide in as they enter the screen.
    await page.waitForTimeout(1_500);
}

/**
 * A shot of one tab of the post editor.
 *
 * Four cards each describe a tab (the header, the gallery, SEO, the
 * languages) and all showed the same list of posts.
 *
 * `exact` on the tab name: "Types de contenu" lives in the side menu and
 * contains "Contenu", so an approximate name catches the menu and the shot
 * comes out on the neighbouring tab, which looks close enough that nobody
 * notices.
 */
function postTabShot(name, tab, extra) {
    return {
        name,
        path: "/suite/editorial/posts/1/edit",
        async prepare(page) {
            await page.waitForTimeout(4_000);
            await page.locator("main").getByRole("tab", { name: tab, exact: true }).first().click();
            await page.waitForTimeout(2_000);

            if (extra) await extra(page);
        },
    };
}

/**
 * Opens a demo post.
 *
 * By its URL and not by a click in the list: a row is not a link, and its
 * actions menu is a popover with no role to target.
 */
const DEMO_POST_ID = process.env.TOUR_POST_ID ?? "1";

async function openPost(page) {
    // `domcontentloaded` and not `load`: the editor keeps a connection open
    // in dev, so the `load` event never fires.
    await page.goto(`${BASE_URL}/suite/editorial/posts/${DEMO_POST_ID}/edit`, { waitUntil: "domcontentloaded" });
    await page.waitForTimeout(4_000);
}

/** The editor of a post, on the requested tab. */
const postTab = (name) => async (page) => {
    await openPost(page);
    await page.locator("main").getByRole("tab", { name }).first().click();
    await page.waitForTimeout(1_500);
};

/**
 * A demo space, on the requested view.
 *
 * Through the list and by name, for the reason `tour-espaces-clients`
 * explains: ids change on every fixture reload, and the demo holds empty
 * spaces that photograph a page that succeeds while showing nothing.
 *
 * The open view is remembered from one visit to the next, so each shot clicks
 * its own again instead of relying on what was displayed before.
 */
const spaceView = (view) => async (page) => {
    await openSpace(page);
    await spaceSection(page, view).click();
    await page.waitForTimeout(2_000);
    await hideReviewBanner(page);
};

/**
 * Closes the "contenu attend l'avis du client" banner, for the visit.
 *
 * It sits above every view of a space while something waits for the client,
 * and the demo has such a content since 3.0.0 (only the Review step counts).
 * On the board it is the point of the picture; above the files, the notes or
 * the discussion it only pushes what the card shows down the page. Closing it
 * is not remembered, so the board shots still have it.
 */
async function hideReviewBanner(page) {
    const close = page.locator("main").getByRole("button", { name: REVIEW_BANNER_HIDE });

    if (await close.count() > 0) {
        await close.first().click();
        await page.waitForTimeout(500);
    }
}

/**
 * An entry of a space's rail, by its label.
 *
 * Since 0.9.328 the sections of a space are a rail of buttons, and an entry
 * carries a counter when something is waiting ("Contenus 3"): its accessible
 * name is no longer the label alone, and an `exact: true` no longer finds it.
 * The label, followed or not by a number, and nothing else.
 */
function spaceSection(page, label) {
    // Inside `main`: the side menu has its own "Réglages" too.
    return page.locator("main").getByRole("button", { name: new RegExp(`^${label}(\\s+\\d+)?$`) }).first();
}

/**
 * Expands the page's "Comment ça marche" boxes.
 *
 * Shots keep them collapsed (see the context's init script); those that show
 * a box open it here. One click on a single one is enough: the choice is
 * shared, they all open.
 */
async function openGuides(page) {
    await page.locator("[data-guide] summary").first().click();
    await page.waitForTimeout(800);
}

/** The two Studio audit templates, as the fixtures name them. */
const AUDIT_MODEL = "Modèle · Audit des réseaux sociaux";
const AUDIT_PRESENTATION = "Audit en présentation";

/**
 * The id of a Studio deliverable, by its title: it changes on every
 * `make demo-reset`, and a hardcoded URL (numbers 13 and 14 of a local
 * database) gave a 404 on a fresh database. The list the page reads itself
 * gives it, under the session already open.
 */
async function deliverableId(page, title) {
    const response = await page.request.get(`${BASE_URL}/suite/studio/deliverables/lists`);
    const lists = await response.json();
    const found = [...(lists.shared ?? []), ...(lists.personal ?? [])].find((row) => row.title === title);

    if (!found) throw new Error(`Le livrable « ${title} » n'est pas dans la démo : \`make demo-reset\` le recharge.`);

    return found.id;
}

/**
 * The demo contract's signing page, at the address the fixtures pin.
 *
 * Derived from the same phrases as `StudioDemoFixtures::pinSigningLink`:
 * the token only ever exists in the email, and no secret-looking literal
 * belongs in a public repository.
 */
const SIGNING_URL = (() => {
    const sha = (phrase) => createHash("sha256").update(phrase).digest("hex");

    return `${BASE_URL}/contracts/${sha("aurora-demo-signing-selector").slice(0, 32)}/${sha("aurora-demo-signing-token")}`;
})();

/**
 * Answers the two calls the signing page makes on its own.
 *
 * "Opened" would move the demo contract to "Ouvert", and the code would go
 * out by email: answered here, the page behaves as for the client and the
 * demo is left as it was. The masked address is the one the server writes.
 */
async function stubSigningCalls(page) {
    await page.route(/\/opened$/, (route) => route.fulfill({ json: { success: true } }));
    await page.route(/\/code$/, (route) => route.fulfill({ json: { success: true, sentTo: "je*********@aurora.app" } }));
}

/**
 * Picks an option in an AppSelect: its options are rendered elsewhere in the
 * page, so they are found visible and by their text, not inside the field.
 */
async function pickOption(page, field, text) {
    await field.click();
    await page.locator(".multiselect__option:visible").filter({ hasText: text }).first().click();
    await page.waitForTimeout(600);
}

/**
 * Three phone screens of the suite, side by side, on the frame the public
 * site's phone shot uses.
 *
 * A phone context of its own, signed in with the main context's session, so
 * the screens are the ones the rules write for: cards, "…", the page bar.
 */
async function phoneTriptych(page, steps) {
    const phone = await browser.newContext({
        viewport: { width: 390, height: 844 },
        deviceScaleFactor: 1,
        isMobile: true,
        hasTouch: true,
        colorScheme: "dark",
        locale: "fr-FR",
        timezoneId: "Europe/Paris",
        reducedMotion: "reduce",
        storageState: await context.storageState(),
    });
    await phone.addInitScript(() => window.localStorage.setItem("aurora.guides.open", "0"));

    const shots = [];
    for (const step of steps) {
        const tab = await phone.newPage();
        await step(tab);
        await hideChrome(tab);
        await tab.waitForTimeout(1_500);
        shots.push((await tab.screenshot()).toString("base64"));
    }
    await phone.close();

    await page.setContent(`<!doctype html><html><body style="margin:0;height:1000px;display:flex;align-items:center;justify-content:center;gap:56px;background:radial-gradient(ellipse at 50% 40%,#12302a,#030712 75%)">${shots
        .map((shot) => `<div style="padding:10px;border-radius:46px;background:#0b0f17;box-shadow:0 30px 60px rgba(0,0,0,.6),inset 0 0 0 1px rgba(255,255,255,.12)"><img src="data:image/png;base64,${shot}" style="display:block;width:390px;height:844px;border-radius:36px"></div>`)
        .join("")}</body></html>`);
    await page.waitForTimeout(300);
}

/**
 * Opens the "Réseaux sociaux" space, where every shot of a space starts.
 *
 * **The list's tab is remembered from one visit to the next**, and `espaces-
 * prospects` leaves it on Prospects just before. The space we look for
 * belongs to a client, so it is no longer in the list, the click times out
 * after thirty seconds and the next scenario fails. Alone it passed, in the
 * series it failed, which is the signature of shared state. So the tab is set
 * back to Clients before searching, without checking where it is.
 */
async function openSpace(page) {
    await page.getByRole("button", { name: /^Clients/ }).first().click();
    await page.waitForTimeout(1_000);

    await page.getByRole("link", { name: /Réseaux sociaux/ }).first().click();
    await page.waitForTimeout(3_500);
}

/**
 * The contents of a space, as a kanban or a list.
 *
 * **The shape is remembered from one visit to the next**: a shot in list mode
 * left the next one on the list, and the board photographed was no longer a
 * board. So each shot states its own.
 */
const contents = (shape) => async (page) => {
    await openSpace(page);
    await spaceSection(page, "Contenus").click();
    await page.waitForTimeout(1_500);
    await page.locator("main").getByRole("button", { name: shape, exact: true }).first().click();
    await page.waitForTimeout(1_500);
};

/** A board card opened, by its title. */
const openCard = (title) => async (page) => {
    await contents("Tableau")(page);
    await page.locator("main").getByText(title, { exact: true }).first().click();
    await page.getByRole("dialog").first().waitFor();
    await page.waitForTimeout(1_500);
};

/** The accessible name of the review banner's close button. */
const REVIEW_BANNER_HIDE = "Masquer ce bandeau";

/** The spaces, where every shot of a space starts. */
const SPACES = "/suite/studio/spaces";

/**
 * The page the client opens, through a real URL: a link issued the way the
 * studio issues it, then followed. Shared by the shot of the client-side space
 * and the shot of its deliverables.
 */
/**
 * The address of the links the client-side shots issue, and only theirs: the
 * demo's own Camille uses `camille@atelier-dupont.fr`.
 */
const CLIENT_SIDE_EMAIL = "camille@atelier-dupont.example.com";

/**
 * Deletes the links `openClientSide` issued.
 *
 * Every shot of the client side issues one, since its address is only shown
 * once, and the demo kept them: the space's access screen listed five
 * "Camille, gérante" by the time it was photographed. Run after each of
 * those shots, through the screen, so the demo stays as the fixtures left it.
 */
async function removeClientSideLinks(page) {
    await page.goto(`${BASE_URL}${SPACES}`, { waitUntil: "networkidle" });
    await openSpace(page);
    await page.getByRole("link", { name: "Accès client", exact: true }).first().click();
    await page.getByRole("button", { name: "Nouveau lien d'accès" }).first().waitFor();

    const rows = page.locator("main li").filter({ hasText: CLIENT_SIDE_EMAIL });

    while ((await rows.count()) > 0) {
        await rows.first().getByTitle(/^Actions pour/).click();
        await page.locator(".fixed.inset-0.z-50").getByRole("button", { name: /^Supprimer/ }).first().click();
        await page.getByRole("dialog").last().getByRole("button", { name: /^Supprimer/ }).click();
        await page.waitForTimeout(1_200);
    }
}

async function openClientSide(page) {
    await openSpace(page);

    const espace = new URL(page.url());
    await page.goto(`${espace.origin}${espace.pathname}/access`, { waitUntil: "networkidle" });

    // Wait for the button rather than count to fifteen hundred.
    //
    // This scenario passed alone and failed in the full series, on a
    // `click` that timed out after thirty seconds: a fixed pause is
    // enough on an idle machine and no longer on the same machine at
    // the sixty-eighth screen. So the wait is on what we really wait
    // for, the app mounted and its button present.
    const ouvrir = page.getByRole("button", { name: "Nouveau lien d'accès" }).first();
    await ouvrir.waitFor({ state: "visible", timeout: 30_000 });
    await ouvrir.click();
    await page.waitForTimeout(1_000);

    // By the field's placeholder and not by its label: the fields of
    // this modal have no id, so nothing ties the `<label>` to its
    // `<input>` for a tool that reads the page.
    await page.getByPlaceholder("camille@societe.fr").fill(CLIENT_SIDE_EMAIL);
    await page.getByPlaceholder(/^Camille, /).fill("Camille, gérante");

    await page.getByRole("dialog").getByRole("button", { name: "Créer le lien" }).click();
    await page.waitForTimeout(2_500);

    const adresse = (await page.locator("code").first().innerText()).trim();
    await page.goto(adresse, { waitUntil: "networkidle" });
    await page.waitForTimeout(2_500);
}


/**
 * The demo post shared by link only (`ReadingLinkDemoFixtures`), with the
 * reading link it was sent with.
 */
const READING_POST = "Dossier de presse";

/**
 * Opens that post's editor from the list, searched by title: its id changes
 * on every fixture reload. Expects the list filtered on it (the shot's path).
 */
async function openReadingPost(page) {
    await page.waitForTimeout(1_500);
    await rowAction(page, `Actions pour ${READING_POST}`, /^Modifier/);
    await page.waitForURL(/\/suite\/editorial\/posts\/\d+\/edit/);
    // The editor keeps a connection open in dev: no `load`, a pause.
    await page.waitForTimeout(3_000);
}

/** Opens one of Jean Martin's row actions, on the accounts list. */
async function userAction(page, action) {
    await page.waitForTimeout(1_500);
    await rowAction(page, "Actions pour Jean Martin", action);
    await page.getByRole("dialog").first().waitFor();
    await page.waitForTimeout(1_000);
}

/** Where presentations are listed since 3.0.0: the shared deliverables. */
const PRESENTATIONS = "/suite/studio/deliverables?scope=shared";

/**
 * A presentation opened from the list, by its title: ids change on every
 * fixture reload.
 */
function openDeck(title) {
    return async (page) => {
        await page.locator("main").getByRole("link", { name: new RegExp(title) }).first().click();
        await page.waitForLoadState("domcontentloaded");
        await page.waitForTimeout(2_500);
    };
}

/**
 * One capture per tour card, named after the document it replaces.
 *
 * **The name is the link with production.** Each card of /fr/page/aurora
 * shows a media library document called `tour-<name>.png`; a capture with the
 * same name is swapped in with `aurora:ged:replace`, and the card needs to
 * know nothing. Keeping a mapping table alongside would be a second source of
 * truth to keep in sync.
 *
 * `prepare` runs after loading and before the shutter: it is where a panel
 * gets opened or a query typed, because several of these screens only say
 * what they do once something is on them.
 */
const SHOTS = [
    { name: "tour-dashboard", path: "/suite" },
    {
        // The same screen in light theme, for the right half of the index
        // page's banner.
        //
        // The back-office theme lives in `localStorage` under
        // `aurora-theme`, and `useTheme` puts it back on `<html>` when the
        // app starts. So it is written **before** loading: setting it
        // afterwards, on the document, would be overwritten by the
        // composable a fraction of a second later.
        name: "tour-dashboard-clair",
        path: "/suite",
        async prepare(page) {
            await page.waitForTimeout(2_500);
        },
        async before(page) {
            await page.addInitScript(() => {
                window.localStorage.setItem("aurora-theme", "light");
            });
        },
        async after(page) {
            // Put back as it was found: every other shot is in dark mode,
            // and a preference that survives would switch the next one
            // without warning.
            await page.addInitScript(() => {
                window.localStorage.setItem("aurora-theme", "dark");
            });
        },
    },
    {
        name: "tour-recherche",
        path: "/suite",
        async prepare(page) {
            // No keyboard shortcut opens it: the button is the only way in,
            // and it announces itself, which makes it findable here.
            await page.getByRole("button", { name: "Rechercher…" }).click();

            const field = page.getByPlaceholder(/^Rechercher dans la suite/);
            await field.waitFor({ state: "visible", timeout: 5_000 });
            await field.fill(SEARCH_QUERY);
            await page.waitForTimeout(1_500);
        },
    },

    { name: "tour-publications", path: "/suite/editorial/posts" },
    {
        // The grid of the demo's richest page, twenty-five zones: on the
        // welcome page, five zones said little of what a grid holds.
        name: "tour-grille",
        path: `/suite/editorial/posts?search=${encodeURIComponent("Nouveaux blocs")}`,
        async prepare(page) {
            await page.waitForTimeout(1_500);
            await rowAction(page, "Actions pour Nouveaux blocs", /^Modifier/);
            await page.waitForTimeout(3_000);
            await page.locator("main").getByRole("tab", { name: "Contenu", exact: true }).first().click();
            await page.waitForTimeout(2_000);
        },
    },
    { name: "tour-entete", path: "/suite", prepare: postTab(/En-tête/) },
    {
        // The site-wide SEO settings, and no longer the post tab: the banner
        // and the body of the card showed that same tab twice. The post tab
        // stays in the body (`tour-seo-onglet`); the banner shows what every
        // page inherits. The demo leaves these fields empty, so they are
        // typed in and not saved.
        name: "tour-seo",
        path: "/suite/configuration/settings/seo",
        async prepare(page) {
            await page.waitForTimeout(2_000);
            const fields = page.locator("main input[type='text'], main textarea");
            await fields.nth(0).fill("{title} · {siteName}");
            await fields.nth(1).fill("Le site de démonstration d'Aurora : publications, médiathèque, formulaires et espaces clients.");
            await fields.nth(2).fill("@aurora");
            await fields.nth(2).blur();
            // Saved on the local demo: since 4.9.0 the settings screens count
            // the changes waiting to be saved, and the shot read "3
            // modifications en attente" next to an active Save button.
            // Greyed out when the demo already holds these values (a second
            // run): nothing to save then.
            const save = page.locator("main").getByRole("button", { name: "Enregistrer", exact: true });
            if (await save.isEnabled()) {
                await save.click();
                // Until the "Réglages sauvegardés" toast has gone.
                await page.waitForTimeout(5_500);
            }
        },
    },
    { name: "tour-galerie", path: "/suite", prepare: postTab(/Galerie/) },
    {
        // The gallery as a visitor sees it, under the page: the banner of the
        // card, since the editor tab already illustrates its body.
        name: "tour-galerie-site",
        path: "/fr/page/bienvenue",
        anonymous: true,
        async prepare(page) {
            const gallery = page.locator("section.not-prose").filter({ has: page.locator("[data-gallery-open]") }).last();
            await placeAt(page, gallery, 120);
        },
    },
    {
        name: "tour-traductions",
        path: "/suite",
        // Spanish rather than French: the card talks about a single layout
        // for several languages, and the translated language is what shows
        // it. The language selector sits above the tabs.
        async prepare(page) {
            await openPost(page);
            // The dot on an empty language joins the button's name.
            await page.locator("main").getByRole("button", { name: /^es\b/ }).first().click();
            await page.waitForTimeout(2_000);
            await page.locator("main").getByRole("tab", { name: /Paramétrage/ }).first().click();
            await page.waitForTimeout(1_200);
        },
    },

    { name: "tour-types", path: "/suite/editorial/post-types" },
    { name: "tour-taxonomies", path: "/suite/editorial/taxonomies" },
    {
        // The main navigation, the menu a visitor sees on every page. The
        // list opens on the first menu, "Compte", whose two entries say
        // little.
        name: "tour-menus",
        path: "/suite/editorial/menus",
        async prepare(page) {
            await page.waitForTimeout(2_000);
            await page.locator("#sidemenu").getByRole("link", { name: /Navigation principale/ }).first().click();
            await page.waitForTimeout(2_500);
        },
    },
    {
        // The comments as a visitor reads them, under an article: replies in
        // a thread and reactions. Moderation, the suite side, illustrates
        // the body of the card (`tour-commentaires-moderation`); both used to
        // show the moderation list.
        name: "tour-commentaires",
        path: "/fr/article/ecrire-premier-article",
        anonymous: true,
        async prepare(page) {
            const title = page.getByRole("heading", { name: /^Commentaires/ }).first();
            await placeAt(page, title, 120);
        },
    },
    {
        // The list of forms, since 0.9.320: a menu entry, and behind it a
        // table that says for each one whether it is online, how many
        // questions it asks and whether it receives answers.
        name: "tour-formulaires-liste",
        path: "/suite/editorial/forms",
        async prepare(page) {
            await page.locator("main").getByRole("table").first().waitFor();
            await page.waitForTimeout(1_200);
        },
    },
    {
        // Creation: a title and a starting point. The "Demande de devis"
        // template is chosen, to show that a template can carry steps.
        name: "tour-formulaire-modeles",
        path: "/suite/editorial/forms",
        async prepare(page) {
            // The list's main button since 3.1.0, no longer in « Actions ».
            await page.locator("main").getByRole("button", { name: /Nouveau formulaire/ }).first().click();
            const dialog = page.getByRole("dialog").filter({ hasText: "Point de départ" }).first();
            await dialog.waitFor();
            await dialog.getByRole("textbox").first().fill("Demande de devis");
            await dialog.getByRole("radio", { name: /Demande de devis/ }).first().click();
            await page.waitForTimeout(800);
        },
    },
    {
        name: "tour-formulaire-champs",
        // The questions of a form, one question open in the panel and the
        // preview below: it is the screen where the form is built, and the
        // card talks about it. Since 0.9.320 each form has its own page; no
        // more detour through the list, nor a fallback that would photograph
        // the previous screen if the click missed.
        path: "/suite/editorial/forms/1",
        async prepare(page) {
            await page.waitForTimeout(2_500);
            await page.locator("main").getByRole("button", { name: /Type de projet/ }).first().click();
            await page.waitForTimeout(1_500);
        },
    },

    {
        // The grid, chosen explicitly: a fresh browser opens the media
        // library as a list on a wide screen, and the card shows the
        // thumbnails.
        name: "tour-mediatheque-grille",
        path: "/suite/ged/documents",
        async prepare(page) {
            await page.locator("main").getByRole("button", { name: "Vue cartes" }).click();
            await page.waitForTimeout(2_000);
        },
    },
    /**
     * A document's detail page: its metadata and its version history.
     *
     * The second picture of the media library card. It already illustrated
     * the page and was never retaken: it was a week older than all the
     * others.
     */
    {
        name: "tour-mediatheque-document",
        path: "/suite/ged/documents",
        async prepare(page) {
            // Searched for rather than clicked in the list: the demo media
            // library spans two pages, and this one is not always on the
            // first. It is the one we want, because it carries three
            // versions and the card talks about them.
            // Since 3.1.0 its placeholder is short again ("Rechercher un
            // document…"); the guide says it also reads text and tags.
            // In card view: a list row does not open on click, a card does,
            // and the mode is remembered from one visit to the next.
            await page.locator("main").getByRole("button", { name: "Vue cartes" }).click();
            await page.waitForTimeout(1_000);
            await page.getByPlaceholder(/^Rechercher un document/).fill("Visuel de campagne");
            await page.waitForTimeout(2_000);
            await page.locator("main").getByText("Visuel de campagne", { exact: false }).first().click();
            await page.waitForTimeout(2_500);
        },
    },
    {
        // The grid and its palette: forty-eight columns, the zones already
        // placed, and at the bottom everything that can be placed. The card
        // lists ten kinds of zones and showed none.
        name: "tour-grille-palette",
        path: "/suite/editorial/posts/1/edit",
        async prepare(page) {
            await page.waitForTimeout(4_000);
            // `exact`, otherwise the side menu wins: "Types de contenu"
            // contains "contenu", and it is what the first result points to.
            // The capture then came out on the Paramétrage tab, which is the
            // neighbouring one and looks close enough that nobody notices.
            await page.locator("main").getByRole("tab", { name: "Contenu", exact: true }).first().click();
            await page.waitForTimeout(2_000);
            // Down to the bottom of the palette: it holds 45 types, and the
            // top of the tab only showed three rows of them.
            await page
                .locator("main")
                .getByRole("button", { name: "Pile", exact: true })
                .last()
                .evaluate((el) => el.scrollIntoView({ block: "end" }));
            await page.mouse.wheel(0, 60);
            await page.waitForTimeout(1_000);
        },
    },
    // A zone's editor is `tour-grille-zone`, further down: three attempts
    // here scrolled an inner box, when it is the window that scrolls.

    {
        // A post's settings: its status, its dates, its type and its URL.
        // The card talks about a cycle (draft, review, scheduling,
        // publishing, archiving) and only showed the list where the status
        // is read, never the place where it is decided.
        name: "tour-publications-parametrage",
        path: "/suite/editorial/posts/1/edit",
        async prepare(page) {
            // `domcontentloaded` is not enough here: the tab only exists once
            // the component is mounted, and the editor keeps a connection
            // open in dev, so `load` never fires.
            await page.waitForTimeout(4_000);
            await page.locator("main").getByRole("tab", { name: "Paramétrage" }).first().click();
            await page.waitForTimeout(1_500);
        },
    },
    {
        // The history: what was saved before is kept and can be compared.
        // The card promises it in black and white.
        //
        // The shot only became possible after giving revisions to the demo
        // dataset: the fixtures write posts directly, whereas a revision is
        // born from a save that goes through the manager. So the modal
        // opened on "Aucune version enregistrée pour le moment", the
        // picture that succeeds while showing nothing.
        name: "tour-publications-historique",
        path: "/suite/editorial/posts/1/edit",
        async prepare(page) {
            await page.waitForTimeout(4_000);
            await page.getByRole("button", { name: "Actions" }).first().click();
            await page.waitForTimeout(800);
            await page.getByRole("button", { name: "Historique" }).first().click();
            await page.waitForTimeout(2_500);
        },
    },
    {
        // The trash, which spans the modules: deleting does not erase right
        // away, and the screen says how much time is left.
        name: "tour-publications-corbeille",
        path: "/suite/editorial/posts",
        // Deleting a post, from its row: the window says it goes to the
        // trash. The trash itself is the banner of its own card, and this
        // used to be the same picture. Never confirmed.
        async prepare(page) {
            await page.waitForTimeout(1_500);
            await rowAction(page, "Actions pour Les tarifs de l'an dernier", /^Supprimer/);
        },
    },
    // The card promises the header settings and the shot showed none: the
    // preview fills the whole window and the controls (placement, height,
    // width, gradient, fade, buttons) start below the fold. So we scroll
    // down to "Hauteur", which leaves the bottom of the preview at the top
    // of the frame: you see what you adjust and what it gives.
    postTabShot("tour-entete-reglages", "En-tête", async (page) => {
        await page.getByText("Hauteur", { exact: true }).first().scrollIntoViewIfNeeded();
        await page.waitForTimeout(1_200);
    }),
    postTabShot("tour-seo-onglet", "Moteurs de recherche"),
    {
        // The Galleries module, and no longer the editor tab: the tour page
        // showed that same tab twice, banner and body. This second entry
        // point only touches the gallery, which lets someone be trusted with
        // the photos without opening the rest of the post to them.
        name: "tour-galeries-module",
        path: `/suite/editorial/post-galleries/${DEMO_POST_ID}/edit`,
        async prepare(page) {
            await page.waitForTimeout(3_500);
        },
    },
    {
        // The same post in another language: same layout, different words.
        // The card says "un onglet par langue" and showed a page in French.
        name: "tour-multilingue-en",
        path: "/suite/editorial/posts/1/edit",
        async prepare(page) {
            await page.waitForTimeout(4_000);
            await page.getByRole("button", { name: "en", exact: true }).first().click();
            await page.waitForTimeout(2_000);
        },
    },
    {
        // The dashboard on a module other than editorial: the card promises
        // "un panneau par module actif, chacun avec ses chiffres".
        name: "tour-dashboard-modules",
        path: "/suite",
        async prepare(page) {
            await page.waitForTimeout(3_000);

            // **In the content, not in the whole page.** "GED" names both
            // the dashboard tab and a section of the side menu, and
            // `.first()` took the section: the menu expanded, shifted, the
            // Éditorial tab stayed open, and the shot showed the previous
            // screen under a name that promised the other one.
            // `exact: true` cannot help, the two labels are identical. The
            // menu lives outside `<main>`, so limiting the search to it
            // tells them apart for good.
            await page.locator("main").getByRole("tab", { name: "GED", exact: true }).first().click();
            await page.waitForTimeout(1_500);
        },
    },
    {
        // The search open, with its results grouped by kind.
        name: "tour-recherche-resultats",
        path: "/suite",
        async prepare(page) {
            await page.waitForTimeout(3_000);
            await page.keyboard.press("Control+K");
            await page.waitForTimeout(1_200);
            // "client" and not the default word: it hits three kinds at once
            // (a navigation entry, a media item, events), and that grouping
            // is what the card promises. "aurora" only brought back media,
            // a list and not a demonstration.
            await page.keyboard.type("client", { delay: 60 });
            await page.waitForTimeout(2_500);
        },
    },
    {
        // Comment moderation and its three states.
        name: "tour-commentaires-moderation",
        path: "/suite/editorial/comments",
    },
    {
        // The submissions a form received: the card talks about what a form
        // can do and stopped at its fields.
        // Submissions live **below** the fields, on the form's page.
        //
        // Two mistakes before getting there, and the second went to
        // production: looking for a "Réponses" tab that does not exist, then
        // targeting `/submissions`, which is the JSON API and not a screen.
        // The shot was a raw JSON dump, and it illustrated the public card
        // for an hour. A URL that answers is not a page.
        name: "tour-formulaire-reponses",
        // The Réponses tab, by its URL: since 0.9.320 submissions have
        // their own tab, and it is in the URL fragment.
        path: "/suite/editorial/forms/1#submissions",
        async prepare(page) {
            await page.waitForTimeout(3_000);
        },
    },
    {
        // A menu open, with its nested entries: the card describes what an
        // entry can point to and showed the list of menus.
        name: "tour-menus-entrees",
        path: "/suite/editorial/menus",
        async prepare(page) {
            await page.waitForTimeout(2_500);
            await page.getByRole("link", { name: /Navigation principale/ }).first().click();
            await page.waitForTimeout(2_500);
            // An entry open, with what it can point to: the banner of the
            // card already shows the list of this same menu.
            await rowAction(page, "Actions pour Accueil", /^Modifier/);
        },
    },
    {
        // The terms of a taxonomy: the card contrasts the category tree with
        // flat tags, without showing either.
        name: "tour-taxonomies-termes",
        path: "/suite/editorial/taxonomies",
        async prepare(page) {
            await page.waitForTimeout(2_500);
            // The page opens the first taxonomy, the categories, by default:
            // nothing to click, but we check it really is that one, so as to
            // never photograph another list while believing it shows this one.
            await page.locator("main h2", { hasText: "Catégories" }).first().waitFor();
            await page.waitForTimeout(2_500);
            // A term open, its name and address in each language: the list
            // of terms is the banner of the card.
            await rowAction(page, "Actions pour Guides", /^Modifier/);
        },
    },
    {
        // The fields of a content type: it is what the card details, and the
        // list of types says nothing about them.
        name: "tour-types-champs",
        path: "/suite/editorial/post-types",
        async prepare(page) {
            await page.waitForTimeout(2_500);
            await page.locator('#sidemenu a[href*="/suite/editorial/post-types/"]', { hasText: "Article" }).first().click();
            await page.waitForTimeout(2_500);
            // A field open, a list of choices: its type, its choices, required
            // or not, per language or not. The list of fields is the banner.
            await rowAction(page, "Actions pour Niveau", /^Modifier/);
        },
    },
    {
        // A theme's palette: the card talks about derived colours and
        // contrasts, so that is where to look.
        name: "tour-themes-palette",
        path: "/suite/configuration/themes",
        async prepare(page) {
            await page.waitForTimeout(2_500);
            // The theme shipped with Aurora, always there. No fallback: a
            // missed click photographed the list of themes instead of the
            // palette, a duplicate of tour-themes.
            await page.locator("main").getByRole("button", { name: "Actions pour Default" }).click();
            await page.getByRole("button", { name: "Modifier", exact: true }).click();
            await page.waitForTimeout(2_500);
        },
    },
    {
        // The light and dark greys (1.4.0): the two columns, their family,
        // the preview and the shades.
        name: "tour-reglages-apparence",
        path: "/suite/configuration/settings/appearance",
        async prepare(page) {
            await page.waitForTimeout(2_500);
        },
    },
    {
        // The email colours (1.4.0), below the language, in their own tab.
        name: "tour-reglages-emails",
        path: "/suite/configuration/settings/email",
        async prepare(page) {
            await page.waitForTimeout(2_000);
        },
    },
    {
        // An account's privileges, screen by screen: it is the card's central
        // promise, and it only showed the list of accounts.
        name: "tour-privileges",
        path: "/suite/platform/users",
        async prepare(page) {
            await page.waitForTimeout(2_500);
            await page.getByTitle(/^Actions pour Jean Martin/).first().click();
            await page.waitForTimeout(1_000);
            await page.getByRole("button", { name: /^Privilèges/ }).first().click();
            await page.waitForTimeout(2_500);
        },
    },
    {
        // The settings and their tabs: the card talks about what can be set
        // without showing where.
        name: "tour-reglages-onglets",
        path: "/suite/configuration/settings",
        async prepare(page) {
            await page.waitForTimeout(2_500);
        },
    },
    {
        // An integration on the shared template (0.9.328): what it does, its
        // state, the connection card, and the how-to alongside.
        name: "tour-integrations",
        path: "/suite/configuration/settings/pexels",
        async prepare(page) {
            await page.waitForTimeout(2_000);
            await openGuides(page);
        },
    },
    {
        // A "Comment ça marche" box open, on a simple screen: each screen has
        // its own, next to what it explains.
        name: "tour-encarts",
        path: "/suite/ged/categories",
        async prepare(page) {
            await page.waitForTimeout(1_500);
            await openGuides(page);
        },
    },
    {
        // The categories: one per kind of document, the one that decides
        // where a file is filed. The card talks about it and did not show it.
        name: "tour-mediatheque-categories",
        path: "/suite/ged/categories",
    },
    {
        // Tags, which cut across categories: a document can carry as many as
        // it wants, whereas it has only one category.
        name: "tour-mediatheque-etiquettes",
        path: "/suite/ged/tags",
    },
    {
        // The card promises "le rendu à côté de la source", and its alt text
        // describes "une note, son rendu à côté, ses étiquettes et ses
        // liens". The shot showed the library: folders and thumbnails, that
        // is, the one thing the card does not mention.
        //
        // By name and not by id: the fixtures renumber on every reload.
        // "Site de l'agence" is the only demo note that brings all three
        // together: a banner, a wiki link in its text, and an incoming link,
        // so a panel that shows something. "Sommaire de l'agence" has a
        // richer source but less points to it: the panel opened on
        // "Aucun lien entrant", in the middle of a picture meant to show
        // that notes link to each other.
        name: "tour-notes",
        path: "/suite/notes/markdown",
        async prepare(page) {
            await openNoteWithPanel(page, "Site de l'agence");
            await page.locator('[data-side-tab="backlinks"]').first().click();
            await page.waitForTimeout(1_000);
        },
    },
    {
        // The note's outline (0.9.331): its headings indented by level, a
        // click that leads to them, and at the bottom the word count and
        // reading time. The firm's note has headings on three levels, so an
        // outline that unfolds.
        name: "tour-notes-plan",
        path: "/suite/notes/markdown",
        async prepare(page) {
            await openNoteWithPanel(page, "Site de l'agence");
            await page.locator('[data-side-tab="outline"]').first().click();
            await page.locator("[data-note-outline]").first().waitFor();
            await page.waitForTimeout(800);
        },
    },
    {
        // The version history (0.9.331), on the oldest of the three the demo
        // creates: its diff with the current text shows the most, lines
        // removed as well as added.
        name: "tour-notes-historique",
        path: "/suite/notes/markdown",
        async prepare(page) {
            await openNoteByTitle(page, "Site de l'agence");
            await page.locator("main").getByRole("button", { name: "Actions", exact: true }).first().click();
            await page.waitForTimeout(500);
            await page.getByText("Historique des versions", { exact: true }).first().click();
            await page.locator("[data-revision-diff]").first().waitFor();
            await page.locator("[data-note-revisions] li button").last().click();
            await page.waitForTimeout(1_200);
        },
    },
    {
        // Starting from a template (0.9.331): "Ajouter" offers the notes
        // marked as templates, and the instruction about today's date.
        name: "tour-notes-modele",
        path: "/suite/notes/markdown",
        async prepare(page) {
            await page.getByTitle("Ajouter", { exact: true }).first().click();
            await page.waitForTimeout(800);
            await page.locator('[data-add-kind="note"]').click();
            await page.locator("[data-add-name] input, input[data-add-name]").first().fill("Brief Boulangerie Fournier");
            await page.locator("[data-add-template] .multiselect").first().click();
            await page.waitForTimeout(400);
            await page.locator(".multiselect__option:visible").filter({ hasText: "Brief de projet" }).first().click();
            await page.waitForTimeout(600);
        },
    },
    {
        // The library, flat and as a mosaic: each note shows the start of
        // its content in small, as on a shelf. It is the first thing you see
        // when opening the module, and the card showed none of it.
        name: "tour-notes-bibliotheque",
        path: "/suite/notes/markdown",
        async prepare(page) {
            await flatten(page);
        },
    },
    {
        // The same library in card view: the dense grid, without excerpts.
        // Three ways to look at the same notebook, and only one was
        // photographed.
        name: "tour-notes-vue-cartes",
        path: "/suite/notes/markdown",
        async prepare(page) {
            await flatten(page);
            await page.getByTitle("Cartes").first().click();
            await page.waitForTimeout(1_200);
        },
    },
    {
        // And as a list: title, tags, folder, date. It is the view for
        // someone looking for a specific note rather than browsing.
        name: "tour-notes-vue-liste",
        path: "/suite/notes/markdown",
        async prepare(page) {
            await flatten(page);
            await page.getByTitle("Liste").first().click();
            await page.waitForTimeout(1_200);
        },
    },
    {
        // Taking notes away (3.4.0): a folder's menu, open on « Exporter ce
        // dossier (zip) », over the library by folder, whose title row
        // carries the export of what is shown. By folder and not flat: the
        // previous scenarios leave the library flat, and a flat library
        // shows no folder card to open a menu on.
        name: "tour-notes-export",
        path: "/suite/notes/markdown",
        async prepare(page) {
            const byFolder = page.getByTitle("Afficher par dossiers").first();
            if (await byFolder.count() > 0) {
                await byFolder.click();
                await page.waitForTimeout(1_200);
            }

            await page.locator("main").getByRole("button", { name: "Actions pour Agence" }).first().click();
            await page.getByText("Exporter ce dossier (zip)", { exact: true }).first().waitFor();
            await page.waitForTimeout(600);
        },
    },
    {
        // A note's styling, where both things are decided in the same
        // place: the header image, searched on Pexels and cropped with the
        // scroll wheel, and the six appearances. A single window for both,
        // so a single picture.
        name: "tour-notes-entete",
        path: "/suite/notes/markdown",
        async prepare(page) {
            await page.getByRole("link", { name: /^Sommaire de l'agence/ }).first().click();
            await page.waitForTimeout(2_500);
            // **In the content, not in the whole page.** Since each row of
            // the tree carries its own action sheet, "Actions pour…" also
            // exists in the side menu, and `.first()` caught the first row
            // there instead of the open note. The tree lives outside
            // `<main>`, which tells them apart.
            await page.locator("main").getByTitle(/^Actions/).first().click();
            await page.waitForTimeout(800);
            await page.getByRole("button", { name: "Image d'entête" }).first().click();
            await page.waitForTimeout(2_000);
        },
    },
    {
        // The graph, which the card's text has promised from the start
        // without ever showing it. It opens from a note's menu, so one has
        // to be opened first.
        name: "tour-notes-graphe",
        path: "/suite/notes/markdown",
        async prepare(page) {
            await page.getByRole("link", { name: /^Sommaire de l'agence/ }).first().click();
            await page.waitForTimeout(2_500);
            await page.locator("main").getByTitle(/^Actions/).first().click();
            await page.waitForTimeout(700);
            await page.getByRole("button", { name: "Ouvrir le graphe" }).first().click();
            // The layout is animated: it places the nodes before settling,
            // and photographing too early gives a tangle.
            await page.waitForTimeout(4_000);
        },
    },
    {
        // A note's public page: the text's other promise, "montrer une note
        // à quelqu'un qui n'a pas de compte".
        //
        // The URL is asked from the server rather than written here: the
        // token is drawn at random on every fixture load, so a hardcoded
        // URL would be dead at the first `make demo`.
        //
        // The note too is found by its title. The scenario asked for the
        // shares of note 1, which was the index as long as the demo had
        // never changed; a demo reloaded on top of an old one keeps the old
        // note under that number, and the card published four lines with no
        // image and no linked notes while the real index, its cover and its
        // "avec les notes liées" link sat right next to it.
        name: "tour-notes-partage",
        path: "/suite/notes/markdown",
        async prepare(page) {
            await page.getByRole("link", { name: /^Sommaire de l'agence/ }).first().click();
            await page.waitForTimeout(2_500);

            const id = /\/markdown\/(\d+)/.exec(page.url())?.[1];

            if (undefined === id) throw new Error("la note ne s'est pas ouverte");

            const url = await page.evaluate(async (noteId) => {
                const r = await fetch(`/suite/notes/markdown/shares/${noteId}`, { headers: { Accept: "application/json" } });
                const j = await r.json();

                return j?.links?.[0]?.url ?? null;
            }, id);

            if (!url) throw new Error("aucun lien de partage dans la démonstration");

            await page.goto(url, { waitUntil: "networkidle" });
            await page.waitForTimeout(2_000);
        },
    },
    {
        // The share window of a note (4.3.0): a link that writes, and under it
        // the live co-editing box with its "Bêta" badge, ticked. The list
        // below carries the demo's live link with its "En direct" badge.
        name: "tour-notes-lien-partage",
        path: "/suite/notes/markdown",
        async prepare(page) {
            await openNoteByTitle(page, "Site de l'agence");
            await page.locator("main").getByRole("button", { name: "Actions", exact: true }).first().click();
            await page.waitForTimeout(500);
            await page.getByText("Partager", { exact: true }).first().click();
            await page.locator("[data-share-can-write] input[type=checkbox]").check();
            await page.locator("[data-share-coediting] input[type=checkbox]").check();
            await page.locator("[data-share-coediting-badge]").first().waitFor();
            await page.waitForTimeout(800);
        },
    },
    {
        // Writing together (4.3.0), on the suite's side: a guest of the live
        // link in the room, their face in the header and their caret, named,
        // in the text. Nothing is typed: a caret placed is enough to show,
        // and the demo note stays as the other shots expect it.
        name: "tour-notes-coedition",
        path: "/suite/notes/markdown",
        async prepare(page) {
            const id = await openNoteByTitle(page, "Site de l'agence");
            await page.getByTitle("Édition + aperçu").first().click();
            await page.waitForTimeout(800);

            const guest = await openCoeditGuest(await liveLinkUrl(page, id));
            await placeCaret(guest.locator("[data-share-content-field]"), "## ");

            await page.locator("[data-note-collaborator]").first().waitFor({ timeout: 15_000 });
            await placeCaret(page.locator("main textarea").first(), "# ");
            await page.waitForTimeout(2_000);
        },
        async after() {
            await closeCoeditGuest();
        },
    },
    {
        // The same room from the guest's side (4.3.0): the share page opened
        // straight on the field, edit / split / preview, "Écriture en direct",
        // the account's face and its caret, and the light / dark switch.
        name: "tour-notes-partage-ecriture",
        path: "/suite/notes/markdown",
        async prepare(page) {
            const id = await openNoteByTitle(page, "Site de l'agence");
            await page.getByTitle("Édition + aperçu").first().click();
            await page.waitForTimeout(800);

            const guest = await openCoeditGuest(await liveLinkUrl(page, id));
            await placeCaret(page.locator("main textarea").first(), "## ");
            await guest.locator("[data-note-collaborator]").first().waitFor({ timeout: 15_000 });
            await placeCaret(guest.locator("[data-share-content-field]"), "# ");
            // Placing the caret scrolls the field into view, and the header
            // (the live badge, the modes, the theme switch) is what the
            // picture is about.
            await guest.evaluate(() => window.scrollTo(0, 0));
            await guest.waitForTimeout(2_000);
            await hideChrome(guest);

            // The guest's page is the picture: shown in the main tab, which is
            // the one the loop photographs.
            const shot = (await guest.screenshot()).toString("base64");
            await page.setContent(`<!doctype html><html><body style="margin:0"><img src="data:image/png;base64,${shot}" style="display:block;width:1600px;height:1000px"></body></html>`);
            await page.waitForTimeout(300);
        },
        async after() {
            await closeCoeditGuest();
        },
    },
    {
        // The editor's cheat sheet (4.6.0): every syntax, its shortcut, and
        // « Insérer » at the caret. Opened over the rich note, so what shows
        // behind is the kind of text it explains.
        name: "tour-notes-aide-memoire",
        path: "/suite/notes/markdown",
        async prepare(page) {
            await openNoteByTitle(page, "Bilan de la saison");
            await page.locator("main").getByRole("button", { name: "Aide-mémoire" }).first().click();
            await page.locator("[data-note-help-search]").first().waitFor();
            await page.waitForTimeout(800);
        },
    },
    {
        // A note's emoji over its banner and its properties under the title
        // (4.6.0), on the firm's sheet: status, due date, budget, who follows
        // it, a box and a link, each typed.
        name: "tour-notes-proprietes",
        path: "/suite/notes/markdown",
        async prepare(page) {
            await openNoteByTitle(page, "Site de l'agence");
            await page.locator("[data-note-properties]").first().waitFor();
            await page.waitForTimeout(800);
        },
    },
    {
        // The rendering (4.6.0), in the reading view of the season's review:
        // the table of contents, highlights and colours, a formula, a
        // diagram, foldable callouts and dated tasks, as they read.
        name: "tour-notes-rendu",
        path: "/suite/notes/markdown",
        async prepare(page) {
            const id = await openNoteByTitle(page, "Bilan de la saison");
            await page.goto(`${BASE_URL}/suite/notes/markdown/${id}/read`, { waitUntil: "domcontentloaded" });
            await page.locator(".note-preview .md-mermaid svg").first().waitFor({ timeout: 15_000 });
            await page.locator(".note-preview .katex").first().waitFor();
            await placeAt(page, page.locator(".note-preview h2", { hasText: "En bref" }).first(), 90);
            await page.waitForTimeout(800);
        },
    },
    {
        // Every task of every note (4.6.0): what is left, what is late in
        // red, by note, ticked right there. The demo dates them from the day
        // it is loaded.
        name: "tour-notes-taches",
        path: "/suite/notes/markdown",
        async prepare(page) {
            await page.locator("[data-library-tasks]").first().click();
            await page.locator("[data-note-task]").first().waitFor();
            await page.waitForTimeout(800);
        },
    },
    {
        // The journal as a calendar (4.6.0): today's note, and a dot on each
        // day of the month that has one. Three days written before, so the
        // month is not empty; opening a day's note writes it only once.
        name: "tour-notes-journal",
        path: "/suite/notes/markdown",
        async before(page) {
            await page.goto(`${BASE_URL}/suite/notes/markdown`, { waitUntil: "domcontentloaded" });
            journalNotes = await page.evaluate(async () => {
                const today = new Date();
                const days = [1, 3, 6, 8].filter((day) => day < today.getDate());
                const ids = [];
                for (const day of days) {
                    const date = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, "0")}-${String(day).padStart(2, "0")}`;
                    const response = await fetch("/suite/notes/markdown/daily", {
                        method: "POST",
                        headers: { Accept: "application/json", "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest" },
                        body: JSON.stringify({ date }),
                    });
                    ids.push((await response.json())?.note?.id);
                }

                return ids.filter(Boolean);
            });
        },
        // Gone for good afterwards: empty notes dated today would top the
        // library's « recently modified » in every later shot (09/10/2026).
        async after(page) {
            await page.evaluate(async (ids) => {
                const headers = { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" };
                for (const id of ids) {
                    await fetch(`/suite/notes/markdown/${id}/delete`, { method: "POST", headers });
                    await fetch(`/suite/notes/markdown/${id}/force-delete`, { method: "POST", headers });
                }
                // And the « Journal » folder the first of them filed, left empty.
                const folders = (await (await fetch("/suite/notes/markdown/folders", { headers })).json())?.folders ?? [];
                for (const folder of folders.filter((one) => "Journal" === one.name && null == one.parentId && 0 === (one.noteCount ?? 0) && 0 === (one.folderCount ?? 0))) {
                    await fetch(`/suite/notes/markdown/folders/${folder.id}/delete`, { method: "POST", headers });
                    await fetch(`/suite/notes/markdown/folders/${folder.id}/force-delete`, { method: "POST", headers });
                }
            }, journalNotes);
            journalNotes = [];
        },
        async prepare(page) {
            await page.locator("[data-library-daily-note]").first().click();
            await page.locator("[data-note-journal]").first().waitFor();
            await page.waitForTimeout(800);
        },
    },
    {
        // A folder as a table (4.6.0): one column per property the notes
        // use, sorted by a click on a heading. The agency's folder, whose
        // sheets carry a status, a due date, a budget and who follows them.
        name: "tour-notes-tableau",
        path: "/suite/notes/markdown",
        async prepare(page) {
            // From the agency site's sheet to its folder through the breadcrumb,
            // without a reload: the « person » column needs the people's
            // names, which a version before 4.6.2 only asks for once a note
            // has opened.
            await openNoteByTitle(page, "Site de l'agence");
            await page.locator("[data-note-crumb]", { hasText: "Agence" }).first().click();
            await page.waitForTimeout(1_500);
            await page.getByTitle("Tableau").first().click();
            await page.locator("[data-note-table]").first().waitFor();
            await page.locator('[data-note-table-sort="Échéance"]').first().click();
            await page.waitForTimeout(800);
        },
        async after(page) {
            // The view is remembered: the next shots expect the mosaic.
            await page.evaluate(() => window.localStorage.removeItem("aurora.notes.library.view"));
        },
    },
    {
        // Comments on a passage (4.6.0): the firm's sheet with its threads
        // beside it, the passages underlined in the rendering, a reply that
        // mentions Marie, and the client's own comment left through the
        // writing link.
        name: "tour-notes-commentaires",
        path: "/suite/notes/markdown",
        async prepare(page) {
            await openNoteByTitle(page, "Site de l'agence");
            await page.getByTitle("Édition + aperçu").first().click();
            await page.waitForTimeout(800);
            await page.locator("[data-note-comments-toggle]").first().click();
            await page.locator("[data-note-comment-thread]").first().waitFor();
            const mark = page.locator(".note-preview mark.md-comment-mark").first();
            await mark.waitFor();
            await mark.evaluate((element) => element.scrollIntoView({ block: "center" }));
            await page.waitForTimeout(1_000);
        },
    },
    {
        // A note as slides (4.6.0), full screen: one slide per heading, here
        // the season's figures.
        name: "tour-notes-presentation",
        path: "/suite/notes/markdown",
        async prepare(page) {
            await openNoteByTitle(page, "Bilan de la saison");
            await page.locator("main").getByRole("button", { name: "Actions", exact: true }).first().click();
            await page.waitForTimeout(500);
            await page.locator("[role=dialog] button:visible", { hasText: "Présenter" }).first().click();
            await page.locator("[data-note-presentation]").first().waitFor();
            for (let step = 0; step < 3; step += 1) {
                await page.keyboard.press("ArrowRight");
                await page.waitForTimeout(300);
            }
            await page.waitForTimeout(1_200);
        },
        async after(page) {
            await page.keyboard.press("Escape").catch(() => {});
        },
    },
    {
        // The comments from the client's side (4.6.0): the writing link's
        // page, the passages underlined, the threads beside the note, and a
        // name to give before writing one.
        name: "tour-notes-partage-commentaires",
        path: "/suite/notes/markdown",
        async prepare(page) {
            const id = await openNoteByTitle(page, "Site de l'agence");
            const guest = await openCoeditGuest(await liveLinkUrl(page, id));
            await guest.locator("[data-share-comments]").first().click();
            await guest.locator("[data-note-comment-thread]").first().waitFor();
            await guest.evaluate(() => window.scrollTo(0, 0));
            await guest.waitForTimeout(1_500);
            await hideChrome(guest);

            const shot = (await guest.screenshot()).toString("base64");
            await page.setContent(`<!doctype html><html><body style="margin:0"><img src="data:image/png;base64,${shot}" style="display:block;width:1600px;height:1000px"></body></html>`);
            await page.waitForTimeout(300);
        },
        async after() {
            await closeCoeditGuest();
        },
    },
    {
        // The notebook's search screen (4.7.0): « echeance » without its
        // accents finds « Échéance » in titles, text and properties, each
        // result with the passages it was found by, and the chips that narrow
        // by tag or folder.
        name: "tour-notes-recherche",
        path: "/suite/notes/markdown",
        async prepare(page) {
            await openNotesSearch(page, "echeance");
        },
    },
    {
        // The side panel's search (4.7.0): a note found by its text shows the
        // passage under its row, the words highlighted.
        name: "tour-notes-recherche-panneau",
        path: "/suite/notes/markdown",
        async prepare(page) {
            await page.getByPlaceholder("Rechercher une note…").first().fill("seance lumiere");
            await page.locator("[data-tree-snippet]").first().waitFor();
            await page.waitForTimeout(800);
        },
    },
    {
        // Cmd+P (4.7.0): the titles first, then the notes whose text holds
        // the word, with the passage, and a line to the full search.
        name: "tour-notes-recherche-rapide",
        path: "/suite/notes/markdown",
        async prepare(page) {
            await page.keyboard.press("ControlOrMeta+p");
            await page.locator("[data-note-quick-open] input").first().fill("echeance");
            await page.locator("[data-note-quick-found]").first().waitFor();
            await page.waitForTimeout(800);
        },
    },
    {
        // Find and replace in a note (4.7.0), Cmd+Option+F: every occurrence
        // tinted in the editor, the current one darker. « seance » finds the
        // five « séance » of the season's review. Nothing is replaced.
        name: "tour-notes-chercher-remplacer",
        path: "/suite/notes/markdown",
        async prepare(page) {
            await openNoteByTitle(page, "Bilan de la saison");
            // The caret at the top: the bar starts from it, so the current
            // match is the first one, in view.
            await page.locator("main textarea").first().evaluate((field) => {
                field.focus();
                field.setSelectionRange(0, 0);
                field.scrollTop = 0;
            });
            await page.keyboard.press("ControlOrMeta+Alt+f");
            await page.locator("[data-note-find-field] input").first().fill("seance");
            await page.locator("[data-note-replace-field] input").first().fill("rendez-vous");
            await page.locator("[data-note-find-field] input").first().focus();
            await page.waitForTimeout(800);
        },
    },
    {
        // Replacing across the notebook (4.7.0): « Compter » says how many
        // occurrences in how many notes before anything is written. On
        // « seance », which the demo writes in the text of several notes
        // (« Échéance » is mostly a property's name, which is not replaced).
        // The scenario stops there and never confirms.
        name: "tour-notes-remplacer-carnet",
        path: "/suite/notes/markdown",
        async prepare(page) {
            await openNotesSearch(page, "seance");
            await page.locator("[data-note-search-replace-toggle]").first().click();
            await page.locator("[data-note-replace-with] input").first().fill("session");
            await page.locator("[data-note-replace-preview]").first().click();
            await page.locator("[data-note-replace-summary]").first().waitFor();
            await page.waitForTimeout(600);
        },
    },
    {
        // The reading view: a URL that shows **only** the note, without the
        // menu or the breadcrumb, for someone with an account.
        //
        // The shot showed the editor's preview, that is, the same window as
        // the first picture, in rendered mode. Two photos of the same
        // screen, and the view that exists precisely to show a bare note
        // was on neither. It also differs from the share page, which is the
        // other end: that one is read without an account and carries the
        // list of linked notes when the link includes them.
        //
        // By URL rather than by a click: the button that leads there is in
        // the note's menu, and opening a menu to photograph what is behind
        // it makes the scenario longer without proving anything more.
        name: "tour-notes-apparence",
        path: "/suite/notes/markdown",
        async prepare(page) {
            await page.getByRole("link", { name: /^Sommaire de l'agence/ }).first().click();
            await page.waitForTimeout(2_500);

            const id = /\/markdown\/(\d+)/.exec(page.url())?.[1];

            if (undefined === id) throw new Error("la note ne s'est pas ouverte");

            await page.goto(`${BASE_URL}/suite/notes/markdown/${id}/read`, { waitUntil: "networkidle" });
            await page.waitForTimeout(2_000);
        },
    },
    {
        // The panel by space: its own notebook first, then "Guide de
        // l'agence", which the demo shares with the whole back office.
        // The space's folder is expanded so you can see what it holds.
        name: "tour-notes-espaces",
        path: "/suite/notes/markdown",
        async prepare(page) {
            const header = page.locator("[data-space-header]").filter({ hasText: /Guide de l.agence/i }).first();
            await header.waitFor();

            // Everything collapsed first: its expanded notebook pushed the
            // shared space below the edge of the picture. The button only
            // exists if something is open.
            const replier = page.getByTitle("Tout replier").first();
            if (await replier.count() > 0) await replier.click();

            await page.getByRole("link", { name: /^Procédures/ }).first().click();
            await page.waitForTimeout(1_500);
            // The mosaic, which shows the start of each note; the view is
            // remembered from one scenario to the next.
            await page.locator("main").getByTitle("Mosaïque").first().click();
            await page.waitForTimeout(1_000);
        },
    },
    {
        // Creating a space: the same window as for a note or a folder, with
        // who gets in and what they do there.
        name: "tour-notes-nouvel-espace",
        path: "/suite/notes/markdown",
        async prepare(page) {
            await page.getByTitle("Ajouter", { exact: true }).first().click();
            await page.waitForTimeout(800);
            await page.locator('[data-add-kind="space"]').click();
            await page.locator("[data-add-name] input, input[data-add-name]").first().fill("Documentation client");
            await page.waitForTimeout(600);
        },
    },
    {
        // A space's settings: access, members and their role, and web
        // publishing with its URL. The button only appears when hovering the
        // header, as with a real mouse.
        name: "tour-notes-reglages-espace",
        path: "/suite/notes/markdown",
        async prepare(page) {
            const header = page.locator("[data-space-header]").filter({ hasText: /Guide de l.agence/i }).first();
            await header.hover();
            await header.locator("[data-space-settings]").click();
            await page.locator("[data-space-publication]").waitFor();
            await page.waitForTimeout(1_000);
        },
    },
    {
        // A published space, read without an account: its tree and its
        // first note, nothing of the back office around it. The URL is the
        // one the fixtures give the demo space.
        name: "tour-notes-publique",
        path: "/p/guide-agence",
        async prepare(page) {
            await page.locator("[data-reader-public-title]").waitFor();
            await page.waitForTimeout(1_500);
        },
    },
    { name: "tour-calendrier", path: "/suite/planning/calendar" },

    { name: "tour-contrats", path: "/suite/studio/contracts" },
    { name: "tour-trames", path: "/suite/studio/contract-templates" },
    {
        // A template's preview, since 0.9.318: each value coming from a
        // variable is coloured by its source (made-up example, real
        // information, field to fill in), with the legend below the box.
        name: "tour-trame-apercu",
        path: "/suite/studio/contract-templates",
        async prepare(page) {
            // A template's title is not a link: its version is what opens
            // the editor.
            await page.locator("main tr").filter({ hasText: "Contrat de prestation mensuelle" })
                .getByRole("link", { name: /^Version/ }).first().click();
            await page.waitForLoadState("domcontentloaded");
            await page.waitForTimeout(3_000);
            await page.locator("main").getByRole("button", { name: "Actions", exact: true }).first().click();
            await page.getByText("Aperçu", { exact: true }).first().click();
            await page.getByText("Le texte en couleur vient des variables.").first().waitFor();
            await page.waitForTimeout(2_000);
        },
    },
    { name: "tour-clients", path: "/suite/studio/customers" },

    /**
     * A customer's page (3.0.0), the only place its record is written: the
     * whole form on the left, its spaces, contracts and deliverables on the
     * right. Atelier Dupont, the client with the most around it.
     */
    {
        name: "tour-client-page",
        path: "/suite/studio/customers",
        async prepare(page) {
            await page.locator("main").getByRole("link", { name: "Atelier Dupont", exact: true }).first().click();
            await page.waitForLoadState("domcontentloaded");
            await page.waitForTimeout(2_500);
        },
    },

    /**
     * The prospect pipeline (4.10.0): the Prospects tab drawn as a board, one
     * column per stage, the follow-ups late and due today on the cards. The
     * tab is remembered in the browser, so `after` puts the list back on its
     * clients: `tour-client-page` looks for a client by name.
     */
    {
        name: "tour-clients-tableau",
        path: "/suite/studio/customers?shape=board",
        async prepare(page) {
            // The five stages do not fit beside the side menu at 1600 px: the
            // menu is hidden for this shot, and shown again in `after` - the
            // choice is stored on the account, every next shot would lose it.
            await page.getByRole("button", { name: "Masquer le menu", exact: true }).first().click();
            await page.locator('main [data-customers-view="prospect"]').click();
            await page.locator("[data-prospect-board]").waitFor();
            await page.waitForTimeout(1_500);
        },
        async after(page) {
            await page.locator('main [data-customers-view="client"]').click();
            await page.getByRole("button", { name: "Afficher le menu", exact: true }).first().click();
            await page.waitForTimeout(800);
        },
    },
    {
        // The follow-ups due across both tabs, where the side menu's pill and
        // the dashboard's line lead.
        name: "tour-clients-relances",
        path: "/suite/studio/customers?followUps=due",
        async prepare(page) {
            await page.waitForTimeout(1_500);
        },
        async after(page) {
            await page.locator('main [data-customers-view="client"]').click();
        },
    },
    {
        // A prospect's follow-up on its page: the stage, the follow-up due
        // today, the estimate and the history of exchanges. Menuiserie Fabre,
        // the prospect the demo gives a meeting and a call.
        name: "tour-client-suivi",
        path: "/suite/studio/customers",
        async prepare(page) {
            await page.locator('main [data-customers-view="prospect"]').click();
            await page.locator("main").getByRole("link", { name: "Menuiserie Fabre", exact: true }).first().click();
            await page.waitForLoadState("domcontentloaded");
            await page.locator("[data-customer-pipeline]").waitFor();
            await page.locator("[data-customer-pipeline]").evaluate((element) => element.scrollIntoView({ block: "start" }));
            await page.mouse.wheel(0, -90);
            await page.waitForTimeout(1_500);
        },
        async after(page) {
            await page.goto(`${BASE_URL}/suite/studio/customers`);
            await page.locator('main [data-customers-view="client"]').click();
        },
    },

    /**
     * The header carousel: the demo's home page has three slides in it.
     * The second rather than the first, so it is visible that it is one.
     * "Diapositive 2" is a tab, not a button: targeted by its text.
     */
    {
        name: "tour-entete-carrousel",
        path: "/suite/editorial/posts/1/edit",
        async prepare(page) {
            await page.waitForTimeout(4_000);
            await page.locator("main").getByRole("tab", { name: "En-tête", exact: true }).first().click();
            await page.waitForTimeout(2_000);
            await page.locator("main").getByText("Diapositive 2", { exact: true }).first().click();
            await page.waitForTimeout(1_500);
            await page
                .locator("main")
                .getByRole("button", { name: "Ajouter une diapositive" })
                .evaluate((el) => el.scrollIntoView({ block: "center" }));
            await page.mouse.wheel(0, -260);
            await page.mouse.move(1_590, 990);
            await page.waitForTimeout(1_200);
        },
    },

    /**
     * Studio deliverables (1.7.0), those attached to no space: the two
     * shelves, the creation that asks which one, and the settings of a
     * shared deliverable where its author changes it. The open shelf is
     * read from the URL, `?scope=`.
     */
    { name: "tour-livrables", path: "/suite/studio/deliverables?scope=personal" },
    { name: "tour-livrables-partages", path: "/suite/studio/deliverables?scope=shared" },
    {
        name: "tour-livrables-nouveau",
        path: "/suite/studio/deliverables?scope=shared",
        async prepare(page) {
            // The list's main button since 3.1.0, beside « Actions ».
            await page.locator("main").getByRole("button", { name: /Nouveau livrable/ }).first().click();
            const dialog = page.getByRole("dialog").first();
            await dialog.waitFor();
            await dialog.locator("input").first().fill("Proposition de refonte, trame de l'équipe");
            await page.waitForTimeout(800);
        },
    },
    {
        name: "tour-livrables-reglages",
        path: "/suite/studio/deliverables?scope=shared",
        async prepare(page) {
            await page.locator("main").getByRole("link", { name: "Modèle d'audit de présence en ligne" }).first().click();
            await page.waitForLoadState("domcontentloaded");
            await page.waitForTimeout(3_000);
            // The editor's tabs have the `tab` role (since 1.13.0, for
            // screen readers), and "Réglages" is also an entry of the side
            // menu: targeted inside the content.
            await page.locator("main").getByRole("tab", { name: "Réglages", exact: true }).first().click();
            await page.waitForTimeout(1_500);
        },
    },

    /**
     * A deliverable's reading links (1.13.0): three states on the team's
     * audit template, from the fixtures. A link already opened, which can
     * only be revoked now; a protected link that expires; a new link, which
     * its trash icon deletes as long as nobody has opened it.
     */
    {
        name: "tour-livrables-liens",
        path: "/suite/studio/deliverables?scope=shared",
        async prepare(page) {
            await page.locator("main").getByRole("link", { name: "Modèle d'audit de présence en ligne" }).first().click();
            await page.waitForLoadState("domcontentloaded");
            await page.waitForTimeout(3_000);
            await page.locator("main").getByRole("button", { name: "Actions", exact: true }).first().click();
            await page.waitForTimeout(500);
            await page.getByText("Partager", { exact: true }).last().click();
            await page.getByRole("dialog").first().waitFor();
            await page.waitForTimeout(1_500);
        },
    },
    {
        // The PDF for the reader: an appearance setting, off by default.
        name: "tour-livrables-pdf",
        path: "/suite/studio/deliverables?scope=shared",
        async prepare(page) {
            await page.locator("main").getByRole("link", { name: "Modèle d'audit de présence en ligne" }).first().click();
            await page.waitForLoadState("domcontentloaded");
            await page.waitForTimeout(3_000);
            await page.locator("main").getByRole("tab", { name: "Apparence", exact: true }).first().click();
            await page.waitForTimeout(1_500);
            await page.locator("main").getByText("Autoriser le PDF au lecteur", { exact: true }).first().evaluate((el) => window.scrollTo({ top: el.getBoundingClientRect().top + window.scrollY - 360, behavior: "instant" }));
            await page.waitForTimeout(800);
        },
    },

    /**
     * What is new in 1.11.0, on two demo deliverables: the audit template
     * laid out like a slideshow (white background, raised cards, badges,
     * tilted phone) and the same audit as a presentation (in the site's
     * colours, coloured cards). They come from the fixtures
     * (`fixtures/Studio/data/`) and are found by their title: their ids
     * change on every `make demo-reset`.
     */
    {
        name: "tour-livrables-audit",
        path: "/suite/studio/deliverables?scope=personal",
        async prepare(page) {
            await page.goto(`${BASE_URL}/suite/studio/deliverables/${await deliverableId(page, AUDIT_MODEL)}/preview`, { waitUntil: "domcontentloaded" });
            await page.waitForTimeout(2_500);
            await page.getByRole("heading", { name: /Présentation de l'entreprise/i }).first().evaluate((el) => window.scrollTo({ top: el.getBoundingClientRect().top + window.scrollY - 40, behavior: "instant" }));
            await page.waitForTimeout(1_500);
        },
    },
    {
        name: "tour-livrables-presentation",
        path: "/suite/studio/deliverables?scope=personal",
        async prepare(page) {
            await page.goto(`${BASE_URL}/suite/studio/deliverables/${await deliverableId(page, AUDIT_PRESENTATION)}/preview#diapo-11`, { waitUntil: "domcontentloaded" });
            await page.waitForTimeout(3_000);
        },
    },
    {
        name: "tour-livrables-ambiances",
        path: "/suite/studio/deliverables?scope=personal",
        async prepare(page) {
            await page.goto(`${BASE_URL}/suite/studio/deliverables/${await deliverableId(page, AUDIT_MODEL)}`, { waitUntil: "domcontentloaded" });
            await page.waitForTimeout(3_000);
            await page.locator("main").getByRole("tab", { name: "Apparence", exact: true }).first().click();
            await page.waitForTimeout(1_500);
            await page.locator("main").getByText("Ambiances", { exact: true }).first().evaluate((el) => window.scrollTo({ top: el.getBoundingClientRect().top + window.scrollY - 120, behavior: "instant" }));
            await page.waitForTimeout(800);
        },
    },
    {
        name: "tour-grille-sections",
        path: "/suite/studio/deliverables?scope=personal",
        async prepare(page) {
            await page.goto(`${BASE_URL}/suite/studio/deliverables/${await deliverableId(page, AUDIT_MODEL)}`, { waitUntil: "domcontentloaded" });
            await page.waitForTimeout(3_000);
            await page.locator("main").getByRole("button", { name: "Insérer une section" }).first().click();
            await page.getByRole("dialog").first().waitFor();
            await page.waitForTimeout(1_000);
        },
    },
    {
        name: "tour-grille-apercu",
        path: "/suite/studio/deliverables?scope=personal",
        async prepare(page) {
            await page.addInitScript(() => window.localStorage.setItem("aurora.grid.split", "1"));
            await page.goto(`${BASE_URL}/suite/studio/deliverables/${await deliverableId(page, AUDIT_MODEL)}`, { waitUntil: "domcontentloaded" });
            await page.waitForTimeout(3_000);
            await page.locator("main aside").getByTitle("Téléphone").click();
            await page.waitForTimeout(4_000);
            // The first zone of the "Présentation de l'entreprise" section,
            // picked in the preview: it gets outlined there, and its
            // settings open.
            const frame = page.frameLocator("[data-preview-pane] iframe");
            await frame.locator("[data-grid-zone]").nth(9).click();
            await page.waitForTimeout(1_500);
            await page.locator("main aside").evaluate((el) => window.scrollTo({ top: el.getBoundingClientRect().top + window.scrollY - 90, behavior: "instant" }));
            await page.waitForTimeout(800);
        },
    },
    {
        name: "tour-grille-plan",
        path: "/suite/studio/deliverables?scope=personal",
        async prepare(page) {
            await page.addInitScript(() => window.localStorage.setItem("aurora.grid.split", "0"));
            await page.goto(`${BASE_URL}/suite/studio/deliverables/${await deliverableId(page, AUDIT_MODEL)}`, { waitUntil: "domcontentloaded" });
            await page.waitForTimeout(3_000);
            await page.locator("main").getByText(/Plan du document/).first().evaluate((el) => window.scrollTo({ top: el.getBoundingClientRect().top + window.scrollY - 90, behavior: "instant" }));
            await page.waitForTimeout(1_000);
        },
    },

    /**
     * A colour variant, through the family of the "Visuel de campagne" that
     * `make demo` derives in red and in blue. Behind the window, the expanded
     * family strip shows the original and its variant. "Voir la famille" is
     * a button label, not text: targeted by the attribute.
     */
    {
        name: "tour-mediatheque-variante",
        path: "/suite/ged/documents",
        async prepare(page) {
            await page.locator("main").getByRole("button", { name: "Vue liste" }).click();
            await page.waitForTimeout(1_500);
            await page.locator("main").locator('[aria-label="Voir la famille"], [title="Voir la famille"]').first().click();
            await page.waitForTimeout(2_500);
            await page.getByRole("button", { name: /Variante de couleur/ }).first().click();
            await page.mouse.move(1_590, 990);
            await page.waitForTimeout(2_500);
        },
    },

    /**
     * Presentations, through the kickoff meeting and by name: its twelve
     * slides go through every layout, whereas the template has only four.
     *
     * Since 3.0.0 a presentation is a deliverable in the Slides format: the
     * list is the deliverables screen with its "Présentations" filter, and
     * the kickoff meeting is a shared deliverable.
     */
    {
        name: "tour-presentations",
        path: PRESENTATIONS,
        async prepare(page) {
            // The format is a select since 3.1.0 (« Tous les formats »), and
            // its options are rendered outside the component.
            await page.locator("main .multiselect").filter({ hasText: "Tous les formats" }).first().click();
            await page.locator(".multiselect__option:visible").filter({ hasText: /^Présentations/ }).first().click();
            await page.waitForTimeout(1_200);
        },
    },
    { name: "tour-presentations-editeur", path: PRESENTATIONS, prepare: openDeck("Réunion de lancement") },
    {
        name: "tour-presentations-diaporama",
        path: PRESENTATIONS,
        async prepare(page) {
            await openDeck("Réunion de lancement")(page);
            await page.locator("main").getByRole("button", { name: "Présenter" }).click();
            await page.waitForTimeout(1_500);
            // The fourth slide, a full-page photo: the first is only a title
            // on a plain background.
            for (let i = 0; i < 3; i++) await page.keyboard.press("ArrowRight");
            await page.waitForTimeout(1_500);
        },
    },
    /**
     * The kickoff meeting's free slide, being edited: the selected photo
     * shows its handles and its rotation handle, and the panel alongside
     * what can be set on an image. Reached by its label in the list,
     * "11. Diapo libre", and not by the button of the same name that adds
     * one.
     */
    {
        name: "tour-presentations-libre",
        path: PRESENTATIONS,
        async prepare(page) {
            await openDeck("Réunion de lancement")(page);
            await page.locator("main").getByRole("button", { name: /^\d+\.\s*Diapo libre/i }).first().click();
            await page.waitForTimeout(1_500);
            await page.locator("main .fc-stage .fe-image").first().click();
            // The click scrolled the page down to the photo: scroll back up,
            // so the "Présenter" button is not cut off at the top.
            await page.mouse.wheel(0, -2_000);
            await page.waitForTimeout(800);
        },
    },
    {
        name: "tour-presentations-libre-diaporama",
        path: PRESENTATIONS,
        async prepare(page) {
            await openDeck("Réunion de lancement")(page);
            await page.locator("main").getByRole("button", { name: /^\d+\.\s*Diapo libre/i }).first().click();
            await page.waitForTimeout(1_000);
            await page.locator("main").getByRole("button", { name: "Présenter" }).click();
            await page.waitForTimeout(1_500);
            // The three cards come in one by one: three key presses, and the
            // time for their animation.
            for (let i = 0; i < 3; i++) {
                await page.keyboard.press("ArrowRight");
                await page.waitForTimeout(700);
            }
            await page.waitForTimeout(1_500);
        },
    },
    {
        name: "tour-avenant-scelle",
        // The concluded contract that has an amendment: the only state that
        // shows the seal, both signatures and the chain of amendments at
        // once. Reached by its reference in the list rather than by a
        // hardcoded id, which depends on what the database held when the
        // fixtures ran. The reference without a suffix: the "-A1" one is the
        // amendment itself.
        path: "/suite/studio/contracts",
        async prepare(page) {
            const row = page
                .locator("main table tbody tr")
                .filter({ hasText: "Conclu" })
                .filter({ hasText: "Roux Photographie" })
                .filter({ hasNotText: "-A1" })
                .first();
            await row.locator("a[href*='/suite/studio/contracts/']").first().click();
            await page.waitForLoadState("networkidle");
            await page.waitForTimeout(1_500);
        },
    },

    /**
     * A contract's text adapted for its client: the demo draft carries an
     * added clause, and the right-hand column shows it as a difference from
     * the template. Reached through the detail page, as a reader would,
     * rather than by an id that changes on every load.
     */
    {
        name: "tour-contrat-adapte",
        path: "/suite/studio/contracts",
        async prepare(page) {
            const row = page
                .locator("main table tbody tr")
                .filter({ hasText: "Brouillon" })
                .filter({ hasText: "Atelier Dupont" })
                .first();
            await row.locator("a[href*='/suite/studio/contracts/']").first().click();
            await page.waitForLoadState("networkidle");
            await page.locator("main").getByRole("link", { name: "Voir le texte" }).first().click();
            await page.waitForLoadState("networkidle");
            await page.waitForTimeout(2_000);
        },
    },

    /**
     * The board of a client space.
     *
     * Through the list rather than a URL: ids change on every fixture
     * reload, and a walkthrough that hardcodes a `/workspace/8`
     * photographs an error page the next day.
     *
     * **By name, and not whichever comes first.** The demo holds several
     * spaces, one of them empty; opening the first of the list gave five
     * columns that all say "Rien ici pour le moment", that is, a capture
     * that succeeds and shows nothing.
     *
     * Then a click on Contenus: the open view is remembered from one visit
     * to the next, so the space may open on Notes depending on what was
     * looked at before.
     */
    { name: "tour-espaces-clients", path: SPACES, prepare: contents("Tableau") },

    /**
     * The list of spaces on the Clients tab: the banner of the card, which
     * used to be the board, already in its body.
     */
    {
        name: "tour-espaces-clients-liste",
        path: SPACES,
        async prepare(page) {
            await page.getByRole("button", { name: /^Clients/ }).first().click();
            await page.waitForTimeout(1_500);
        },
    },

    /** The same plan as a list: the card promises "en kanban ou en liste". */
    { name: "espace-liste", path: SPACES, prepare: contents("Liste") },

    /**
     * A card down to the bottom: its discussion thread and the client's
     * verdict, here "à reprendre". The window scrolls by itself down to the
     * thread, which the shot of the top of the card cut off.
     */
    {
        name: "espace-fiche-echanges",
        path: SPACES,
        async prepare(page) {
            await openCard("Offre de rentrée")(page);
            await page.evaluate(() => {
                const dialog = document.querySelector("[role='dialog']");
                const scroller = [...dialog.querySelectorAll("*")].find((el) => el.scrollHeight > el.clientHeight + 20 && /(auto|scroll)/.test(getComputedStyle(el).overflowY));
                if (scroller) scroller.scrollTop = scroller.scrollHeight;
            });
            await page.waitForTimeout(800);
        },
    },

    /**
     * Sending for review: what goes to the client, and how.
     *
     * The banner, not the confirmation window. Since 0.9.320 the banner says
     * what is waiting and what the client receives; opened on top, the window
     * covered it with a veil and the card showed the old action.
     */
    {
        name: "espace-envoyer-relire",
        path: SPACES,
        async prepare(page) {
            await contents("Tableau")(page);
            // The window, opened from the banner: who receives the email.
            // Closed, the picture was the board itself, the card's banner.
            await page.locator("main").getByRole("button", { name: /^Envoyer à relire/ }).first().click();
            await page.getByRole("dialog").first().waitFor();
            await page.waitForTimeout(1_200);
        },
    },

    /**
     * The other views of the same space.
     *
     * **They already illustrate the card, and were not reproducible.** Taken
     * by hand once, they aged without anything saying so: the client-side
     * one still showed a page that stacked everything and a guest signed
     * with their email address, two versions after both had disappeared.
     * That is exactly what this file exists to avoid.
     */
    { name: "espace-calendrier", path: SPACES, prepare: spaceView("Calendrier") },
    { name: "espace-fichiers", path: SPACES, prepare: spaceView("Fichiers") },
    { name: "espace-discussion", path: SPACES, prepare: spaceView("Discussion") },
    { name: "espace-notes", path: SPACES, prepare: spaceView("Notes") },
    { name: "espace-informations", path: SPACES, prepare: spaceView("Informations") },
    { name: "espace-liens", path: SPACES, prepare: spaceView("Ressources") },

    /** A card open: the title, the date, its files and its thread. */
    {
        name: "espace-une-fiche",
        path: SPACES,
        async prepare(page) {
            await openCard("Portrait de l'équipe")(page);
        },
    },

    /**
     * A space opened for a prospect.
     *
     * The tab has its own counter, and that is what the capture shows: you
     * work with someone before they sign.
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
     * The page the client opens, through a real URL.
     *
     * **A link issued right here, and not the studio's preview.** The preview
     * carries a banner warning that it is not what the client received: true
     * in the app, misleading on a card that promises to show what the client
     * sees. So the link is created the way the studio creates it, and the URL
     * is read where the screen displays it once. That is the only time it
     * exists in plain text.
     */
    {
        name: "espace-cote-client",
        path: SPACES,
        prepare: openClientSide,
        after: removeClientSideLinks,
    },

    /** Its deliverables, same side: what was written for them, published. */
    {
        name: "espace-cote-client-livrables",
        path: SPACES,
        async prepare(page) {
            await openClientSide(page);
            await page.getByRole("button", { name: "Livrables", exact: true }).first().click();
            await page.waitForTimeout(1_500);
        },
        after: removeClientSideLinks,
    },

    /** A space's deliverables on the studio side: the demo audit, published. */
    { name: "espace-livrables", path: SPACES, prepare: spaceView("Livrables") },

    /** A space's settings: its Drive, its time zone, its access (0.9.328). */
    { name: "espace-reglages", path: SPACES, prepare: spaceView("Réglages") },

    /**
     * A post's reading links, opened from its editor: the card talks about
     * the links of a publication, and the picture showed a deliverable's
     * share window. The press kit, because it is the demo post shared by
     * link only, and `ReadingLinkDemoFixtures` already sent it to a newsroom:
     * the list has a link without the scenario creating one.
     */
    {
        name: "tour-publications-liens-lecture",
        path: `/suite/editorial/posts?search=${encodeURIComponent(READING_POST)}`,
        async prepare(page) {
            await openReadingPost(page);
            // The button lives in the "Lecture par lien" card of the
            // Paramétrage tab, not in the page bar's "Actions".
            await page.locator("main").getByRole("tab", { name: "Paramétrage", exact: true }).first().click();
            await page.waitForTimeout(1_200);
            await page.locator("main").getByRole("button", { name: "Liens de lecture", exact: true }).first().click();
            const dialog = page.getByRole("dialog").filter({ hasText: "Liens de lecture" }).first();
            await dialog.waitFor();
            // Waited on the link itself: the list is fetched when the window
            // opens, and a shot taken too early says "Aucun lien".
            await dialog.getByText("Rédaction de Lyon Mag", { exact: false }).first().waitFor();
            await page.waitForTimeout(1_000);
        },
    },

    /**
     * The page a post's reading link opens: the press kit, without the site
     * around it, under the "Préparé pour" line its settings carry.
     *
     * The URL is asked from the server, which returns it with the list of
     * links: the token is drawn at random on every demo load.
     */
    {
        name: "tour-publications-page-lecture",
        path: `/suite/editorial/posts?search=${encodeURIComponent(READING_POST)}`,
        async prepare(page) {
            await openReadingPost(page);
            const id = /\/posts\/(\d+)\//.exec(page.url())?.[1];

            if (undefined === id) throw new Error(`« ${READING_POST} » ne s'est pas ouvert`);

            const url = await page.evaluate(async (postId) => {
                const response = await fetch(`/suite/editorial/posts/${postId}/reading-links`, { headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" } });

                return (await response.json()).links?.find((link) => !link.revokedAt)?.url ?? null;
            }, id);

            if (!url) throw new Error(`« ${READING_POST} » n'a aucun lien de lecture dans la démo`);

            const response = await page.goto(url, { waitUntil: "networkidle" });
            await assertPage(page, response, url);
            await hideChrome(page);
            await page.waitForTimeout(2_500);
        },
    },

    /**
     * The tour's "La corbeille" page.
     *
     * The demo fills several of them, at different dates: a post, three
     * documents, a folder, a category and two notes. The screen opens on the
     * fullest one, the documents.
     */
    {
        name: "tour-corbeille",
        path: "/suite/trash",
        async prepare(page) {
            await page.waitForTimeout(1_500);
        },
    },
    {
        // Another module in the same screen: the deleted post, with the
        // link to the list it comes from.
        name: "tour-corbeille-publications",
        path: "/suite/trash",
        async prepare(page) {
            // By module, then by type, since 3.1.0; Éditorial has one type.
            await page.locator("main").getByRole("button", { name: /^Éditorial/ }).first().click();
            await page.waitForTimeout(1_200);
        },
    },
    {
        // Emptying asks for confirmation, and says it cannot be undone.
        name: "tour-corbeille-vider",
        path: "/suite/trash",
        async prepare(page) {
            await page.locator("main").getByRole("button", { name: "Vider", exact: true }).click();
            await page.waitForTimeout(1_000);
        },
    },

    /**
     * Studio in the shared trash (1.13.0 and 1.14.0): a deliverable and a
     * presentation set aside, with what it takes to restore them. One tab per
     * type, like posts.
     */
    {
        name: "tour-corbeille-livrables",
        path: "/suite/trash",
        async prepare(page) {
            await page.locator("main").getByRole("button", { name: /^Studio/ }).first().click();
            await page.waitForTimeout(600);
            await page.locator("main").getByRole("button", { name: /^Livrables/ }).first().click();
            await page.waitForTimeout(1_200);
        },
    },
    /**
     * A client space in the trash (3.0.0): deleting a space no longer
     * destroys it. Presentations had their own tab until they became
     * deliverables; this shot took over their picture.
     */
    {
        name: "tour-corbeille-espaces",
        path: "/suite/trash",
        async prepare(page) {
            await page.locator("main").getByRole("button", { name: /^Studio/ }).first().click();
            await page.waitForTimeout(600);
            await page.locator("main").getByRole("button", { name: /^Espaces clients/ }).first().click();
            await page.waitForTimeout(1_200);
        },
    },

    /**
     * The two actions of the post list that the "Publier, programmer,
     * archiver" card did not show: acting on several rows at once, and
     * duplicating.
     *
     * The selection bar carries the only "Actions" button since 3.1.0: the
     * page's create verb is its own button.
     */
    {
        name: "tour-publications-selection",
        path: "/suite/editorial/posts",
        async prepare(page) {
            const cases = page.locator("main tbody input[type=checkbox]");

            for (const ligne of [3, 4, 5]) {
                await cases.nth(ligne).check();
            }

            await page.waitForTimeout(500);
            await page.locator("main").getByRole("button", { name: "Actions", exact: true }).first().click();
            await page.waitForTimeout(800);
        },
    },
    {
        name: "tour-publications-dupliquer",
        path: "/suite/editorial/posts",
        async prepare(page) {
            // The first row, whatever it is: the list's order follows the
            // modification date, and a title named here moved to page two on
            // the next demo reload.
            await page.locator("main").getByRole("button", { name: /^Actions pour / }).first().click();
            await page.waitForTimeout(800);
        },
    },

    /**
     * The profile, for "Comptes, rôles et privilèges".
     *
     * The mood line is typed and not saved: saving it would put it on every
     * other shot that shows this account.
     */
    {
        name: "tour-profil",
        path: "/suite/general/profile",
        async prepare(page) {
            await page.locator("main textarea").first().fill("En séance photo jusqu'à 18 h, je réponds le soir.");
            await page.locator("main textarea").first().blur();
            await page.waitForTimeout(600);
        },
    },
    {
        // The side menu made one's own: a colour for a section, a hidden
        // module. Nothing is saved, for the same reason.
        name: "tour-profil-menu",
        path: "/suite/general/profile/sidemenu",
        async prepare(page) {
            await page.locator("main [title='amber']").first().click();
            const ligne = page.locator("main .divide-y > div", { has: page.getByText("Corbeille", { exact: true }) }).first();
            await ligne.locator("button, [role=switch], input[type=checkbox]").last().click();
            await page.waitForTimeout(800);
        },
    },

    /**
     * What the visitor fills in outside a form, for the "Les formulaires"
     * card: an appointment and a poll, on the demo's new blocks page.
     *
     * Without a session, like the public site. The appointment is filled in
     * and not sent: sending it would put an event in the demo calendar on
     * every shot, and would take the slot.
     */
    {
        name: "tour-reservation",
        path: "/fr/page/nouveaux-blocs",
        anonymous: true,
        async prepare(page) {
            const zone = page.locator("[data-booking]").first();
            // Tomorrow, not today: today's slots run out as the afternoon
            // goes, and the third one was gone by 15:30.
            await zone.locator("[data-booking-day]").nth(1).click();
            await zone.locator("[data-booking-slots]:not([hidden]) [data-booking-slot]").nth(2).click();
            await zone.locator("[data-booking-name]").fill("Camille Laurent");
            await zone.locator("[data-booking-email]").fill("camille.laurent@example.com");
            await zone.locator("[data-booking-message]").fill("Séance portrait en extérieur, si possible en fin de journée.");
            await zone.locator("[data-booking-message]").blur();
            await hideZonesAbove(zone);
            await placeAt(page, zone, 140);
        },
    },
    {
        // Voting reveals the results. If this browser already voted, they
        // are there straight away: the button is only clicked if it is
        // still waiting for a vote.
        name: "tour-sondage",
        path: "/fr/page/nouveaux-blocs",
        anonymous: true,
        async prepare(page) {
            const titre = page.getByText("Quel format préférez-vous ?", { exact: true }).first();
            await titre.scrollIntoViewIfNeeded();

            // Visible, and not merely present: the results are in the page
            // before the vote, hidden, and counting them was enough to never
            // vote.
            if (!(await page.getByText(/^Votes :/).first().isVisible())) {
                await page.getByRole("button", { name: /Les réels/ }).click();
                await page.getByText(/^Votes :/).first().waitFor({ state: "visible" });
            }

            await hideZonesAbove(titre);
            await placeAt(page, titre, 220);
        },
    },

    { name: "tour-utilisateurs", path: "/suite/platform/users" },
    { name: "tour-audit", path: "/dev/dashboard/audit" },
    { name: "tour-themes", path: "/suite/configuration/themes" },
    // The modules, switched on and off: the card's title promises them and
    // its body already shows the settings (`tour-reglages-onglets`), which
    // open on the same General tab.
    { name: "tour-reglages", path: "/dev/dashboard/modules" },

    {
        name: "tour-calendrier-semaine",
        path: "/suite/planning/calendar",
        async prepare(page) {
            await page.getByRole("button", { name: /^Semaine$/ }).click();
            await page.waitForTimeout(1_000);

            // The grid opens on the current hour, so a capture taken in the
            // evening shows an empty afternoon while the demo events are in
            // the morning. The wheel over the grid is what the component
            // listens to; setting `scrollTop` on a guessed element is not.
            // Scrolled all the way up first, then down by a fixed amount:
            // scrolling by a delta alone would land elsewhere depending on
            // the time of the shot.
            await page.mouse.move(1000, 600);
            await page.mouse.wheel(0, -2_000);
            await page.waitForTimeout(300);
            // Down to about eight in the morning, where the demo's days
            // start since its times are written in the calendar's zone.
            await page.mouse.wheel(0, 300);
            await page.waitForTimeout(600);
        },
    },

    /**
     * The public site, seen without a session.
     *
     * The admin bar shows above a site when it is visited while signed in,
     * and it has no business on a picture that shows what a visitor sees.
     */
    /**
     * The public site, as a visitor sees it.
     *
     * Redone on 28/09/2026 with the dressed-up demo (photos, real texts, home
     * carousel): the home page, a service page, a project, the viewer, the
     * site on a phone and the contact page. All without a session, so the
     * admin bar is not there.
     */
    {
        // The home page and its carousel, on the first slide: it only turns
        // after seven seconds, and the shot is taken before.
        name: "tour-site-public",
        path: "/fr",
        anonymous: true,
        async prepare(page) {
            await page.waitForTimeout(1_200);
        },
    },
    {
        // A composed page: the hook, then alternating image and text.
        name: "tour-site-public-service",
        path: "/fr/services/developpement-web",
        anonymous: true,
        async prepare(page) {
            await page.waitForTimeout(1_500);
        },
    },
    {
        // A project: the large image, the story and the figures. Scrolled
        // down to the story, which the large image hides otherwise.
        name: "tour-site-public-realisation",
        path: "/fr/projets/projet-lumen",
        anonymous: true,
        async prepare(page) {
            await page.evaluate(() => window.scrollTo(0, 380));
            await page.waitForTimeout(1_500);
        },
    },
    {
        // The viewer, opened on the home page's gallery.
        name: "tour-site-public-galerie",
        path: "/fr",
        anonymous: true,
        async prepare(page) {
            const opener = page.locator("[data-gallery-open]").first();
            await opener.scrollIntoViewIfNeeded();
            await page.waitForTimeout(800);
            await opener.click();
            await page.waitForTimeout(1_500);
        },
    },
    {
        // Three pages on a phone, side by side. Each is taken in a 390 px
        // wide context, where the site lays itself out as on a real phone,
        // then the three pictures are placed on the canvas. Frames would not
        // work: the site refuses to be displayed inside a page that is not
        // its own.
        name: "tour-site-public-telephone",
        path: "/fr",
        anonymous: true,
        async prepare(page) {
            const phone = await browser.newContext({
                viewport: { width: 390, height: 844 },
                deviceScaleFactor: 1,
                isMobile: true,
                hasTouch: true,
                colorScheme: "dark",
                reducedMotion: "reduce",
            });
            const shots = [];
            for (const path of ["/fr", "/fr/services/photographie", "/fr/projets/projet-atlas"]) {
                const tab = await phone.newPage();
                await tab.goto(`${BASE_URL}${path}`, { waitUntil: "networkidle" });
                await tab.addStyleTag({ content: ".sf-toolbar,.sf-minitoolbar{display:none!important}" });
                await tab.waitForTimeout(1_200);
                shots.push((await tab.screenshot()).toString("base64"));
            }
            await phone.close();

            await page.setContent(`<!doctype html><html><body style="margin:0;height:1000px;display:flex;align-items:center;justify-content:center;gap:56px;background:radial-gradient(ellipse at 50% 40%,#12302a,#030712 75%)">${shots
                .map((shot) => `<div style="padding:10px;border-radius:46px;background:#0b0f17;box-shadow:0 30px 60px rgba(0,0,0,.6),inset 0 0 0 1px rgba(255,255,255,.12)"><img src="data:image/png;base64,${shot}" style="display:block;width:390px;height:844px;border-radius:36px"></div>`)
                .join("")}</body></html>`);
            await page.waitForTimeout(300);
        },
    },
    {
        // A second public page, the one that carries the form: it shows
        // another composition and how a form renders for the visitor.
        //
        // I first wanted the same screen in light mode, since the card
        // promises "un mode sombre et un mode clair". Two attempts for
        // nothing: the public site does not include the back office's boot
        // script, so nobody reads `aurora-theme` there, and it does not
        // follow `prefers-color-scheme` either. The palette is the active
        // theme's, and the demo's is dark. Showing it would mean switching
        // themes, which is another matter.
        name: "tour-site-public-contact",
        path: "/fr/page/contact",
        anonymous: true,
        async prepare(page) {
            await page.waitForTimeout(2_000);
        },
    },
    {
        // Permissions, on the developer side: the card talks about what the
        // tool does by default, and the audit alone only showed part of it.
        name: "tour-permissions",
        path: "/dev/dashboard/permissions",
        async prepare(page) {
            await page.waitForTimeout(2_500);
        },
    },

    /**
     * Lot 1 of the tour audit (07/10/2026): the screens the tour described
     * without showing them.
     */
    {
        // One zone of the grid selected, its settings open: width per
        // screen, offset, surface. The card is under the canvas, and the
        // window is what scrolls: three earlier attempts scrolled an inner
        // box that does not move.
        name: "tour-grille-zone",
        path: `/suite/editorial/posts/${DEMO_POST_ID}/edit#content`,
        async prepare(page) {
            await page.waitForTimeout(3_000);
            await page.locator('main [data-zone="1"] > button').first().click();
            await page.waitForTimeout(1_200);
            const card = page.locator("main div.border-accent.space-y-4").filter({ visible: true }).first();
            await card.evaluate((node) => window.scrollTo({ top: node.getBoundingClientRect().top + window.scrollY - 90, behavior: "instant" }));
            await page.waitForTimeout(1_000);
        },
    },
    {
        // A list zone and its presentations, on the about page: the card
        // promises eleven presentations for one list.
        name: "tour-grille-liste",
        // Searched: the list has two pages and this post is on the second.
        path: `/suite/editorial/posts?search=${encodeURIComponent("À propos")}`,
        async prepare(page) {
            await page.waitForTimeout(1_500);
            await rowAction(page, "Actions pour À propos", /^Modifier/);
            await page.waitForTimeout(3_000);
            await page.locator("main").getByRole("tab", { name: "Contenu", exact: true }).first().click();
            await page.waitForTimeout(1_200);
            await page.locator('main [data-zone="1"] > button').first().click();
            await page.waitForTimeout(1_200);
            // The list's own presentations, not the zone-type palette above,
            // which also has a "Présentation" button.
            const presentation = page.locator("main").getByText("Étapes numérotées", { exact: true }).filter({ visible: true }).first();
            await presentation.evaluate((node) => window.scrollTo({ top: node.getBoundingClientRect().top + window.scrollY - 160, behavior: "instant" }));
            await page.waitForTimeout(1_000);
        },
    },
    {
        // The Appearance tab of a post that repaints itself: the about page
        // carries its own colours in the demo.
        name: "tour-publication-apparence",
        // Searched: the list has two pages and this post is on the second.
        path: `/suite/editorial/posts?search=${encodeURIComponent("À propos")}`,
        async prepare(page) {
            await page.waitForTimeout(1_500);
            await rowAction(page, "Actions pour À propos", /^Modifier/);
            await page.waitForTimeout(3_000);
            await page.locator("main").getByRole("tab", { name: "Apparence", exact: true }).first().click();
            await page.waitForTimeout(1_500);
        },
    },
    {
        // A post waiting for review, and the reviewer's two answers in the
        // page bar's sheet.
        name: "tour-relecture",
        path: "/suite/editorial/posts",
        async prepare(page) {
            await page.waitForTimeout(1_500);
            await rowAction(page, "Actions pour Relire avant de publier", /^Modifier/);
            await page.waitForTimeout(3_000);
            await page.locator("main").getByRole("button", { name: "Actions", exact: true }).first().click();
            await page.locator(".fixed.inset-0.z-50").getByRole("button", { name: /^Approuver et publier/ }).first().waitFor();
            await page.waitForTimeout(1_000);
        },
    },
    {
        // The Studio's editorial calendar, every space: the demo account is
        // not a member of the space that has dated contents.
        name: "tour-studio-calendrier",
        path: "/suite/studio/spaces/calendar?scope=all",
        async prepare(page) {
            await page.waitForTimeout(2_500);
        },
    },
    {
        // A space's client links: who, what each may do, expired, revoked.
        name: "tour-espace-acces",
        path: SPACES,
        async prepare(page) {
            await openSpace(page);
            await page.getByRole("link", { name: "Accès client", exact: true }).first().click();
            await page.waitForTimeout(2_500);
        },
    },
    {
        // The window that issues a link, with its rights. Never confirmed.
        name: "tour-espace-acces-nouveau",
        path: SPACES,
        async prepare(page) {
            await openSpace(page);
            await page.getByRole("link", { name: "Accès client", exact: true }).first().click();
            await page.waitForTimeout(2_500);
            await page.locator("main").getByRole("button", { name: /^Nouveau lien d'accès/ }).first().click();
            const dialog = page.getByRole("dialog").first();
            await dialog.waitFor();
            await dialog.getByPlaceholder("camille@societe.fr").fill("claire.dupont@atelier-dupont.fr");
            await dialog.getByPlaceholder("Camille, responsable marketing").fill("Claire, gérante");
            await page.waitForTimeout(1_000);
        },
    },
    {
        // An event open: its guests and their answers.
        name: "tour-calendrier-evenement",
        path: "/suite/planning/calendar",
        async prepare(page) {
            await page.waitForTimeout(2_500);
            await page.locator("main").getByText("Réunion générale", { exact: true }).first().click();
            await page.getByRole("dialog").first().waitFor();
            await page.waitForTimeout(1_200);
        },
    },
    {
        // Sharing a calendar by link: who has one, until when, revoked.
        // The share button only shows when the calendar row is hovered.
        name: "tour-calendrier-partage",
        path: "/suite/planning/calendar",
        async prepare(page) {
            await page.waitForTimeout(2_500);
            // Shown on hover, and only where the pointer can hover (3.6.0):
            // headless Chromium declares none, so the button never shows.
            // Clicked through the DOM; the picture is the window, not it.
            const row = page.locator("#sidemenu").getByRole("button", { name: "Pro", exact: true }).first().locator("xpath=..");
            await row.getByTitle("Partage par lien").evaluate((button) => button.click());
            await page.getByRole("dialog").first().waitFor();
            await page.waitForTimeout(1_200);
        },
    },
    {
        // A document's edit window: alternative text, caption, category,
        // tags. A real photo with its alternative text: the filed demo
        // documents are flat colours.
        name: "tour-mediatheque-modifier",
        path: `/suite/ged/documents?search=${encodeURIComponent("façade de verre")}`,
        async prepare(page) {
            await page.waitForTimeout(2_000);
            await rowAction(page, "Actions pour Une façade de verre", /^Modifier/);
            await page.getByRole("dialog").first().waitFor();
            await page.waitForTimeout(1_200);
        },
    },
    {
        // The bell open: what waits for a decision.
        name: "tour-notifications",
        path: "/suite",
        async prepare(page) {
            await page.waitForTimeout(2_000);
            await page.getByRole("button", { name: "Notifications", exact: true }).filter({ visible: true }).first().click();
            await page.waitForTimeout(1_500);
        },
    },
    {
        // The Studio panel of the dashboard, every space.
        name: "tour-dashboard-studio",
        path: "/suite?module=studio&studioScope=all",
        async prepare(page) {
            await page.waitForTimeout(2_500);
        },
    },

    /**
     * Lot 2: the contract seen from the client's side, on the link the demo
     * pins (`StudioDemoFixtures::pinSigningLink`). Nothing is sent and
     * nothing changes state: opening is answered here, and so is the code
     * request, so the contract stays "Envoyé" and no email leaves.
     */
    {
        name: "tour-signature-contrat",
        url: SIGNING_URL,
        anonymous: true,
        before: stubSigningCalls,
        async prepare(page) {
            await page.locator("article.contract-document").first().waitFor();
            await page.waitForTimeout(1_500);
        },
    },
    {
        name: "tour-signature-code",
        url: SIGNING_URL,
        anonymous: true,
        before: stubSigningCalls,
        async prepare(page) {
            await page.locator("article.contract-document").first().waitFor();
            await page.getByPlaceholder("Camille", { exact: true }).fill("Jean");
            await page.getByPlaceholder("Durand", { exact: true }).fill("Martin");
            await page.getByPlaceholder("camille@societe.fr").fill("jean.martin@martin-documents.fr");
            await page.getByPlaceholder("Lyon", { exact: true }).fill("Grenoble");

            // A signature drawn in the frame: the code button waits for one.
            const pad = page.locator("canvas").first();
            await pad.scrollIntoViewIfNeeded();
            const box = await pad.boundingBox();
            const at = (x, y) => [box.x + box.width * x, box.y + box.height * y];
            await page.mouse.move(...at(0.15, 0.6));
            await page.mouse.down();
            for (const [x, y] of [[0.22, 0.3], [0.3, 0.7], [0.38, 0.35], [0.46, 0.65], [0.55, 0.4], [0.63, 0.6], [0.75, 0.45], [0.85, 0.55]]) {
                await page.mouse.move(...at(x, y), { steps: 6 });
            }
            await page.mouse.up();

            await page.getByRole("button", { name: "Recevoir le code" }).click();
            await page.getByPlaceholder("123456").fill("482913");
            await page.getByRole("checkbox").last().check();
            await placeAt(page, page.getByText("Votre signature", { exact: true }).first(), 120);
        },
    },

    /**
     * A card the client opens on their side: the visual, the deadline, the
     * files and the thread, and the two answers they can give. Never
     * answered here: that would change the demo.
     */
    {
        name: "espace-cote-client-fiche",
        path: SPACES,
        async prepare(page) {
            await openClientSide(page);
            await page.getByText("Offre de rentrée", { exact: true }).first().click();
            await page.getByRole("dialog").first().waitFor();
            await page.waitForTimeout(1_500);
        },
        after: removeClientSideLinks,
    },
    {
        // Creating a contract: a client, a body, an annex, an amount. Never
        // saved.
        name: "tour-contrat-nouveau",
        path: "/suite/studio/contracts",
        async prepare(page) {
            await page.waitForTimeout(2_000);
            await page.getByRole("button", { name: /^Nouveau contrat/ }).first().click();
            const dialog = page.getByRole("dialog").first();
            await dialog.waitFor();
            await pickOption(page, dialog.locator(".multiselect").nth(0), "Martin Documents");
            await pickOption(page, dialog.locator(".multiselect").nth(1), "Contrat de prestation mensuelle");
            await dialog.getByPlaceholder("850").fill("690");
            await dialog.getByPlaceholder("850").blur();
            await page.waitForTimeout(1_200);
        },
    },
    {
        // The suite on a phone, lists: one card per row, its actions behind
        // "…", the page bar with the back button and icon-only commands.
        name: "tour-telephone-listes",
        path: "/suite",
        async prepare(page) {
            await phoneTriptych(page, [
                async (tab) => tab.goto(`${BASE_URL}/suite/platform/users`, { waitUntil: "networkidle" }),
                async (tab) => tab.goto(`${BASE_URL}/suite/editorial/posts`, { waitUntil: "networkidle" }),
                async (tab) => tab.goto(`${BASE_URL}/suite`, { waitUntil: "networkidle" }),
            ]);
        },
    },
    {
        // The suite on a phone, at work: a space's board, a note, the week.
        name: "tour-telephone-travail",
        path: SPACES,
        async prepare(page) {
            // The space's address is read on the desktop first: on a phone
            // the list is cards and the section rail is a menu, which the
            // helpers written for the desktop do not walk.
            await contents("Tableau")(page);
            const space = page.url();
            await phoneTriptych(page, [
                async (tab) => tab.goto(space, { waitUntil: "networkidle" }),
                async (tab) => {
                    // The note is found through the list's endpoint, which
                    // a blank tab cannot reach by a relative address.
                    await tab.goto(`${BASE_URL}/suite/notes/markdown`, { waitUntil: "domcontentloaded" });
                    await openNoteByTitle(tab, "Site de l'agence");
                },
                async (tab) => tab.goto(`${BASE_URL}/suite/planning/calendar`, { waitUntil: "networkidle" }),
            ]);
        },
    },

    {
        // Refusing is provided for as much as signing: a reason, and the
        // studio is told. Opened, never sent.
        name: "tour-signature-refus",
        url: SIGNING_URL,
        anonymous: true,
        before: stubSigningCalls,
        async prepare(page) {
            await page.locator("article.contract-document").first().waitFor();
            await page.getByRole("button", { name: "Je ne signe pas" }).click();
            const heading = page.getByText("Ne pas signer ce contrat", { exact: true }).first();
            await heading.waitFor();
            await placeAt(page, heading, 160);
        },
    },
    {
        // What is collected, why and for how long, at the moment it is.
        name: "tour-signature-donnees",
        url: SIGNING_URL,
        anonymous: true,
        before: stubSigningCalls,
        async prepare(page) {
            await page.locator("article.contract-document").first().waitFor();
            const summary = page.locator("details summary").filter({ hasText: "Vos données" }).first();
            await summary.click();
            await placeAt(page, summary, 120);
        },
    },
    {
        // The client's side of the discussion.
        name: "espace-cote-client-discussion",
        path: SPACES,
        async prepare(page) {
            await openClientSide(page);
            await page.getByRole("button", { name: "Discussion", exact: true }).first().click();
            await page.waitForTimeout(1_500);
        },
        after: removeClientSideLinks,
    },
    {
        // The client's side of the files: what was put on each content.
        name: "espace-cote-client-fichiers",
        path: SPACES,
        async prepare(page) {
            await openClientSide(page);
            await page.getByRole("button", { name: "Fichiers", exact: true }).first().click();
            await page.waitForTimeout(1_500);
        },
        after: removeClientSideLinks,
    },
    {
        // The suite on a phone, by hand: a row's actions behind "…", the
        // menu as a drawer, a post with its page bar.
        name: "tour-telephone-gestes",
        path: "/suite",
        async prepare(page) {
            await phoneTriptych(page, [
                async (tab) => {
                    await tab.goto(`${BASE_URL}/suite/platform/users`, { waitUntil: "networkidle" });
                    await tab.getByTitle("Actions pour Jean Martin").first().click();
                    await tab.waitForTimeout(800);
                },
                async (tab) => {
                    await tab.goto(`${BASE_URL}/suite`, { waitUntil: "networkidle" });
                    await tab.getByRole("button", { name: "Afficher le menu", exact: true }).first().click();
                    await tab.waitForTimeout(800);
                },
                async (tab) => tab.goto(`${BASE_URL}/suite/editorial/posts/${DEMO_POST_ID}/edit`, { waitUntil: "domcontentloaded" }),
            ]);
        },
    },

    /**
     * Lot 4: blocks as a visitor sees them, on the demo page that holds one
     * of each. Framed by their place in the grid: the public HTML carries no
     * zone id. The zones above are hidden, as for the booking.
     */
    ...[
        ["tour-blocs-pratiques", 1, "Disponibilité, compte à rebours, horaires, carte de visite"],
        ["tour-blocs-reseaux", 5, "Une publication façon réseau social, une image dans un cadre d'ordinateur"],
        ["tour-blocs-code", 9, "Un terminal et un diff"],
        ["tour-blocs-graphiques", 13, "Trois graphiques"],
        ["tour-blocs-calendrier", 17, "Un calendrier éditorial"],
        ["tour-blocs-tarifs", 18, "Une carte et ses tarifs"],
        ["tour-blocs-voyage", 22, "Une carte de voyage étape par étape"],
        ["tour-blocs-devis", 23, "Un simulateur de devis"],
        ["tour-blocs-recit", 25, "Un récit en images"],
    ].map(([name, child]) => ({
        name,
        path: "/fr/page/nouveaux-blocs",
        anonymous: true,
        async prepare(page) {
            const zone = page.locator(`.aurora-grid > div:nth-child(${child})`).first();
            await hideZonesAbove(zone);
            await placeAt(page, zone, 120);
        },
    })),

    {
        // The page a shared calendar opens on, for whoever holds the link:
        // read in the share window, then followed.
        name: "tour-agenda-partage",
        path: "/suite/planning/calendar",
        async prepare(page) {
            await page.waitForTimeout(2_500);
            const row = page.locator("#sidemenu").getByRole("button", { name: "Pro", exact: true }).first().locator("xpath=..");
            await row.getByTitle("Partage par lien").evaluate((button) => button.click());
            const dialog = page.getByRole("dialog").first();
            await dialog.waitFor();
            const link = await dialog.locator("input[readonly]").first().inputValue();
            await page.goto(link, { waitUntil: "networkidle" });
            await hideChrome(page);
            await page.waitForTimeout(1_500);
        },
    },

    /** Lot 3: the rest of the suite the tour only described. */
    {
        // A form's settings: online or not, where answers go, the limits.
        name: "tour-formulaire-reglages",
        path: "/suite/editorial/forms/1#settings",
        async prepare(page) {
            await page.waitForTimeout(2_500);
        },
    },
    {
        // A question that only shows after a given answer.
        name: "tour-formulaire-condition",
        path: "/suite/editorial/forms/1",
        async prepare(page) {
            await page.waitForTimeout(2_000);
            await page.locator("main").getByRole("button", { name: /Nombre de références au catalogue/ }).first().click();
            await page.waitForTimeout(1_000);
            const when = page.locator("main").getByText("Quand l'afficher", { exact: false }).filter({ visible: true }).first();
            await when.evaluate((node) => window.scrollTo({ top: node.getBoundingClientRect().top + window.scrollY - 160, behavior: "instant" }));
            await page.waitForTimeout(800);
        },
    },
    { name: "tour-reglages-lettre", path: "/suite/configuration/settings/newsletter" },
    { name: "tour-reglages-studio", path: "/suite/configuration/settings/studio" },
    { name: "tour-reglages-stockage", path: "/suite/configuration/settings/storage" },
    { name: "tour-reglages-systeme", path: "/suite/configuration/settings/system" },
    { name: "tour-reglages-navigation", path: "/suite/configuration/settings/navigation" },
    { name: "tour-reglages-marque", path: "/suite/configuration/settings/branding" },
    {
        // The document picker, opened from the logo field: the library and
        // free photos, without leaving the screen. Closed, never chosen.
        name: "tour-selecteur-document",
        path: "/suite/configuration/settings/branding",
        async prepare(page) {
            await page.waitForTimeout(2_000);
            await page.locator("main").getByRole("button", { name: /^Changer/ }).first().click();
            await page.getByRole("dialog").first().waitFor();
            await page.waitForTimeout(1_500);
        },
    },
    {
        // The theme's font, in its edit window.
        name: "tour-themes-police",
        path: "/suite/configuration/themes",
        async prepare(page) {
            await page.waitForTimeout(2_500);
            await page.locator("main").getByRole("button", { name: "Actions pour Default" }).click();
            await page.getByRole("button", { name: "Modifier", exact: true }).click();
            await page.waitForTimeout(2_000);
            const font = page.getByText("Police d'écriture", { exact: false }).filter({ visible: true }).first();
            await font.scrollIntoViewIfNeeded();
            await page.waitForTimeout(800);
        },
    },
    {
        // Importing a text as a deliverable: pasted, kept with its titles.
        // Never imported.
        name: "tour-livrables-import",
        path: "/suite/studio/deliverables?scope=shared",
        async prepare(page) {
            await page.waitForTimeout(2_000);
            await page.locator("main").getByRole("button", { name: "Actions", exact: true }).first().click();
            await page.locator(".fixed.inset-0.z-50").getByRole("button", { name: /^Importer un texte/ }).first().click();
            await page.getByRole("dialog").first().waitFor();
            await page.waitForTimeout(1_500);
        },
    },
    {
        // A model copied into a client space. Never copied.
        name: "tour-livrables-copie",
        path: "/suite/studio/deliverables?scope=shared",
        async prepare(page) {
            await page.waitForTimeout(2_000);
            await rowAction(page, `Actions pour ${AUDIT_MODEL}`, /^Copier dans un espace client/);
            await page.getByRole("dialog").first().waitFor();
            await page.waitForTimeout(1_000);
        },
    },
    {
        // Who works on a space, and who leads it.
        name: "tour-espaces-equipe",
        path: SPACES,
        async prepare(page) {
            await page.getByRole("button", { name: /^Clients/ }).first().click();
            await page.waitForTimeout(1_000);
            // The social media space: two people, one of them leading.
            await page.locator("main tr").filter({ hasText: "Réseaux sociaux" }).getByRole("button", { name: /^\d+ personnes?$/ }).first().click();
            await page.getByRole("dialog").first().waitFor();
            await page.waitForTimeout(1_000);
        },
    },
    {
        // A new channel, shown to the client or kept between the team.
        // Never opened.
        name: "espace-nouveau-canal",
        path: SPACES,
        async prepare(page) {
            await spaceView("Discussion")(page);
            await page.locator("main").getByRole("button", { name: /Nouveau canal/ }).first().click();
            await page.getByRole("dialog").first().waitFor();
            await page.waitForTimeout(1_000);
        },
    },
    {
        // Inviting someone: never sent.
        name: "tour-utilisateur-invitation",
        path: "/suite/platform/users",
        async prepare(page) {
            await page.waitForTimeout(1_500);
            await page.getByRole("button", { name: /^Inviter un utilisateur/ }).first().click();
            await page.getByRole("dialog").first().waitFor();
            await page.waitForTimeout(1_000);
        },
    },
    { name: "tour-demandes-acces", path: "/dev/dashboard/access-requests" },
    {
        // The sign-in page, as somebody who is not signed in sees it.
        name: "tour-connexion",
        path: "/suite/platform/login",
        anonymous: true,
        async prepare(page) {
            await page.waitForTimeout(1_200);
        },
    },
    { name: "tour-dev-montage", path: "/dev/dashboard/mount-points" },
    // The developer side of the accounts: roles, types, who can do what.
    { name: "tour-dev-utilisateurs", path: "/dev/dashboard/users" },

    /** Lot 5: the smaller screens. */
    ...[["tour-calendrier-jour", "Jour"], ["tour-calendrier-agenda", "Agenda"]].map(([name, view]) => ({
        name,
        path: "/suite/planning/calendar",
        async prepare(page) {
            await page.waitForTimeout(2_000);
            await page.locator("main").getByRole("button", { name: view, exact: true }).first().click();
            await page.waitForTimeout(1_500);
            if ("Jour" === view) {
                // The day opens on the current hour: up, then to the morning,
                // as for the week.
                await page.mouse.move(1000, 600);
                await page.mouse.wheel(0, -2_000);
                await page.waitForTimeout(300);
                await page.mouse.wheel(0, 300);
                await page.waitForTimeout(600);
            }
        },
    })),
    {
        name: "tour-mot-de-passe-oublie",
        path: "/suite/platform/forgot-password",
        anonymous: true,
        async prepare(page) {
            await page.waitForTimeout(1_000);
        },
    },
    { name: "tour-galeries-liste", path: "/suite/editorial/post-galleries" },
    {
        // A draft's preview, opened from its row: the page as it will be
        // once published, at an address that works without a session.
        name: "tour-brouillon-apercu",
        path: `/suite/editorial/posts?search=${encodeURIComponent("Ce qui arrive ensuite")}`,
        async prepare(page) {
            await page.waitForTimeout(1_500);
            const [preview] = await Promise.all([
                page.context().waitForEvent("page"),
                rowAction(page, "Actions pour Ce qui arrive ensuite", /^Prévisualiser/),
            ]);
            await preview.waitForLoadState("domcontentloaded");
            const address = preview.url();
            await preview.close();
            await page.goto(address, { waitUntil: "networkidle" });
            await hideChrome(page);
            await page.waitForTimeout(1_200);
        },
    },
    {
        // Two people on the same post: the second save is stopped and says
        // why, instead of overwriting the first. The other tab saves the
        // post unchanged, which only moves its version on.
        name: "tour-conflit",
        path: `/suite/editorial/posts/${DEMO_POST_ID}/edit`,
        async prepare(page) {
            await page.waitForTimeout(3_000);
            const other = await page.context().newPage();
            await other.goto(`${BASE_URL}/suite/editorial/posts/${DEMO_POST_ID}/edit`, { waitUntil: "domcontentloaded" });
            await other.getByRole("button", { name: "Enregistrer", exact: true }).first().waitFor();
            await other.waitForTimeout(2_000);
            await other.getByRole("button", { name: "Enregistrer", exact: true }).first().click();
            await other.waitForTimeout(2_000);
            await other.close();
            await page.getByRole("button", { name: "Enregistrer", exact: true }).first().click();
            await page.getByRole("dialog").first().waitFor();
            await page.waitForTimeout(1_000);
        },
    },
    { name: "tour-reglages-lecture", path: "/suite/configuration/settings/reading" },
    { name: "tour-reglages-localisation", path: "/suite/configuration/settings/localization" },
    { name: "tour-reglages-anti-robots", path: "/suite/configuration/settings/captcha" },

    /**
     * Lot 6: accounts and privileges, and the screens still absent from the
     * tour. Nothing here is saved: every window is photographed open and
     * filled in, and the next shot's page load throws it away.
     */
    {
        // Jean Martin's privileges being changed: three editorial boxes
        // ticked, the window not yet saved. `tour-privileges` shows the
        // window as it opens; this one shows what a change looks like.
        name: "tour-privileges-modification",
        path: "/suite/platform/users",
        async prepare(page) {
            await userAction(page, /^Privilèges/);
            const dialog = page.getByRole("dialog").filter({ hasText: "Privilèges" }).first();
            // Not scrolled: the Éditorial section is in view as the window
            // opens, under its header, which a scroll pushed out of the frame.
            for (const label of ["Voir les publications", "Créer des publications", "Modifier les publications"]) {
                await dialog.getByLabel(label, { exact: true }).check();
            }
            // The last box clicked keeps a focus ring otherwise.
            await page.evaluate(() => document.activeElement?.blur());
            await page.waitForTimeout(800);
        },
    },
    {
        // An account's edit window: photo, name, address, role, manager and
        // a new password. The account type and the active switch are not in
        // it: the type is the developer's, deactivating is a row action.
        name: "tour-utilisateur-modifier",
        path: "/suite/platform/users",
        async prepare(page) {
            await userAction(page, /^Modifier/);
        },
    },
    {
        // The modules hidden for one account only, on top of the global
        // switches: the tree of sections and their screens.
        name: "tour-utilisateur-modules",
        path: "/suite/platform/users",
        async prepare(page) {
            await userAction(page, /^Accès aux modules/);
        },
    },
    {
        // The Plateforme panel of the dashboard: accounts and their activity.
        name: "tour-dashboard-plateforme",
        path: "/suite",
        async prepare(page) {
            await page.waitForTimeout(2_000);
            // In `main`: the side menu has a "Plateforme" section too.
            await page.locator("main").getByRole("tab", { name: "Plateforme", exact: true }).first().click();
            await page.waitForTimeout(1_500);
        },
    },
    {
        // "Ajouter une question" opens on the kinds of question, each with
        // what it collects. Closed, never chosen.
        name: "tour-formulaire-types-question",
        path: "/suite/editorial/forms/1",
        async prepare(page) {
            await page.waitForTimeout(2_000);
            // The page bar's button, not the "Ajouter une question ici"
            // between the steps.
            await page.locator("main").getByRole("button", { name: "Ajouter une question", exact: true }).first().click();
            await page.getByRole("dialog").filter({ hasText: "Quel type de question" }).first().waitFor();
            await page.waitForTimeout(1_000);
        },
    },
    {
        // The media library as a list: title, folder, tags, status, file and
        // size per row. The mode is remembered, so it is put back on cards
        // afterwards, the mode the other library shots expect.
        name: "tour-mediatheque-liste",
        path: "/suite/ged/documents",
        async prepare(page) {
            await page.locator("main").getByRole("button", { name: "Vue liste" }).click();
            await page.waitForTimeout(2_000);
        },
        async after(page) {
            await page.locator("main").getByRole("button", { name: "Vue cartes" }).click();
            await page.waitForTimeout(800);
        },
    },
    {
        // A document's own page, the one its permanent link leads to in the
        // suite: metadata, the file, and its colour family with where the
        // original is used. "Visuel de campagne" for that family. Its id
        // changes on every reload: read from the permanent link its window
        // shows.
        name: "tour-mediatheque-page-document",
        path: `/suite/ged/documents?search=${encodeURIComponent("Visuel de campagne")}`,
        async prepare(page) {
            await page.waitForTimeout(1_500);
            await rowAction(page, "Actions pour Visuel de campagne - Automne 2025", /^Voir/);
            const dialog = page.getByRole("dialog").filter({ hasText: "Visuel de campagne" }).first();
            await dialog.waitFor();
            const id = /\/document\/(\d+)/.exec(await dialog.innerText())?.[1];

            if (undefined === id) throw new Error("le lien permanent du document est introuvable");

            const response = await page.goto(`${BASE_URL}/suite/ged/documents/${id}`, { waitUntil: "networkidle" });
            await assertPage(page, response, page.url());
            await page.waitForTimeout(2_000);
            // Down to the end of the page: the family strip and its
            // thumbnails were cut in half by the bottom edge.
            await page.evaluate(() => window.scrollTo({ top: document.documentElement.scrollHeight, behavior: "instant" }));
            await page.waitForTimeout(800);
        },
    },
    {
        // Adding a document: a file chosen, its title, category and folder.
        // Never saved.
        name: "tour-mediatheque-ajout",
        path: "/suite/ged/documents",
        async prepare(page) {
            await page.locator("main").getByRole("button", { name: /Ajouter un document/ }).first().click();
            const dialog = page.getByRole("dialog").filter({ hasText: "Nouveau document" }).first();
            await dialog.waitFor();
            await dialog.locator("input[type=file]").first().setInputFiles(resolve(root, "test_files/images/landscape-placeholder.jpg"));
            await page.waitForTimeout(1_000);
            await dialog.getByPlaceholder("Titre du document…").fill("Vitrine de la boulangerie, automne");
            await dialog.getByPlaceholder("Description optionnelle…").fill("Photo de la façade pour la page d'accueil.");
            // Shown once an image is chosen.
            await dialog.getByPlaceholder(/^Décris l.image/).fill("La vitrine de la boulangerie, pains en devanture");
            await pickOption(page, dialog.locator(".multiselect").filter({ hasText: "Sans catégorie" }).first(), "Ressources Marketing");
            await pickOption(page, dialog.locator(".multiselect").filter({ hasText: "Aucun dossier" }).first(), "Présentations");
            await page.waitForTimeout(800);
        },
    },
    { name: "tour-reglages-notes", path: "/suite/configuration/settings/notes" },
    {
        // Creating a calendar: name, colour, visibility, time zone. Never
        // saved.
        name: "tour-calendrier-nouveau",
        path: "/suite/planning/calendar",
        async prepare(page) {
            await page.waitForTimeout(1_500);
            await page.getByRole("button", { name: "Nouveau calendrier", exact: true }).first().click();
            const dialog = page.getByRole("dialog").filter({ hasText: "Nouveau calendrier" }).first();
            await dialog.waitFor();
            await dialog.getByPlaceholder(/^Travail, Perso/).fill("Séances photo");
            await dialog.getByPlaceholder("Une phrase pour situer.").fill("Les prises de vue chez les clients, matériel compris.");
            await dialog.getByPlaceholder("Une phrase pour situer.").blur();
            await page.waitForTimeout(800);
        },
    },
    {
        // A new client space: its name, the client, its status, time zone,
        // colour and team. Never saved.
        name: "espace-nouveau",
        path: SPACES,
        async prepare(page) {
            await page.locator("main").getByRole("button", { name: /Nouvel espace/ }).first().click();
            const dialog = page.getByRole("dialog").filter({ hasText: "Nouvel espace" }).first();
            await dialog.waitFor();
            await dialog.getByPlaceholder(/^Boulangerie Martin/).fill("Roux Photographie - Galerie des mariages");
            await pickOption(page, dialog.locator(".multiselect").filter({ hasText: "Choisir un client" }).first(), "Roux Photographie");
            await dialog.getByPlaceholder(/^Ce que couvre cet espace/).fill("La sélection des photos de mariage de la saison, et leur livraison aux mariés.");
            // Nobody added to the team: a member's row pushes the field
            // that adds one under the window's footer, cut in half.
            await dialog.getByPlaceholder(/^Ce que couvre cet espace/).blur();
            await page.waitForTimeout(800);
        },
    },
    {
        // A resource added to a space: a link, shown to the client. Never
        // added.
        name: "espace-ressource",
        path: SPACES,
        async prepare(page) {
            await spaceView("Ressources")(page);
            await page.locator("main").getByRole("button", { name: /Nouvelle ressource/ }).first().click();
            const dialog = page.getByRole("dialog").filter({ hasText: "Nouvelle ressource" }).first();
            await dialog.waitFor();
            await dialog.getByPlaceholder(/Maquette Canva/).fill("Planning de publication");
            await dialog.getByPlaceholder("https://").fill("https://docs.example.com/atelier-dupont/planning-automne");
            await dialog.getByPlaceholder(/ouvrir avec le compte/).fill("Le mois en cours, mis à jour chaque lundi.");
            await dialog.getByRole("switch").first().click();
            await page.waitForTimeout(800);
        },
    },
    {
        // A new client and its legal identity, the fields a contract reads.
        // Numbers made up (their check digits hold), never saved.
        name: "tour-client-nouveau",
        path: "/suite/studio/customers",
        async prepare(page) {
            await page.locator("main").getByRole("button", { name: /Nouveau client/ }).first().click();
            const dialog = page.getByRole("dialog").filter({ hasText: "Nouveau client" }).first();
            await dialog.waitFor();
            await pickOption(page, dialog.locator(".multiselect").filter({ hasText: "Prospect" }).first(), "Client");
            await dialog.getByPlaceholder("Atelier Dupont").fill("Boulangerie Fournier");
            await dialog.getByPlaceholder(/^SARL, SAS/).fill("SAS");
            await dialog.getByPlaceholder(/^Restauration/).fill("Boulangerie artisanale");
            await dialog.getByPlaceholder("10000").fill("15000");
            await dialog.getByPlaceholder(/^Adresse complète/).fill("12 rue des Tanneurs, 69002 Lyon");
            await dialog.getByPlaceholder("123 456 789 00012").fill("814 270 559 00010");
            await dialog.getByPlaceholder("123 456 789", { exact: true }).fill("814 270 559");
            await dialog.getByPlaceholder("Lyon B 123 456 789").fill("Lyon B 814 270 559");
            await dialog.getByPlaceholder("FR12345678901").fill("FR64814270559");
            await dialog.getByPlaceholder("FR12345678901").blur();
            await page.waitForTimeout(800);
        },
    },
    { name: "tour-reglages-sequences", path: "/suite/configuration/settings/sequences" },
    { name: "tour-dev-apercu", path: "/dev/dashboard" },

    // "tour-releases" and "tour-release-notes" are no longer photographed:
    // they were GitHub pages. They are drawn from the CHANGELOG with
    // `compose-releases.mjs`.
    {
        // A post whose Spanish is still to be written: the list greys the
        // missing language on its row, at a glance among the others. The
        // demo draft "Ce qui arrive ensuite" has no Spanish on purpose. In
        // the editor the same gap is only a dot beside "ES", too small to
        // read in a picture.
        name: "tour-multilingue-non-traduit",
        path: "/suite/editorial/posts",
        async prepare(page) {
            await page.waitForTimeout(2_500);
        },
    },
];

async function login(page) {
    await page.goto(`${BASE_URL}/suite/platform/login`, { waitUntil: "domcontentloaded" });
    await page.locator("input[type='email'], input[name*='email']").first().fill(EMAIL);
    await page.locator("input[type='password']").first().fill(PASSWORD);
    await page.locator("button[type='submit']").first().click();
    await page.waitForURL(/\/suite/, { timeout: 15_000 });
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
            /* The version under the logo. On a local instance it reads
               "dev", and it is the only thing in these images that tells a
               client they are looking at a development machine rather than
               the product. In production it would carry a number, which
               tells them nothing either. */
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
// The "Comment ça marche" boxes collapsed, on every page: open, they put a
// block of text at the top of each screen and push what the card shows below
// the fold. The choice is shared by all boxes and remembered in the browser
// (`aurora.guides.open`), so it is set before each load; a shot that wants
// the box open expands it in its `prepare` (`openGuides`), and the next load
// collapses it again.
await context.addInitScript(() => {
    window.localStorage.setItem("aurora.guides.open", "0");
});
// The tour is in dark mode, and since 4.9.0 (10/10/2026) the suite opens in
// light mode unless dark is remembered: the first shot of a run came out
// light. Set before every load; `tour-dashboard-clair` swaps it for its shot.
await context.addInitScript(() => {
    if (null === window.localStorage.getItem("aurora-theme")) window.localStorage.setItem("aurora-theme", "dark");
});

const page = await context.newPage();

await login(page);

/**
 * The context without a session, created only if a capture asks for one.
 *
 * Two captures show what someone who is not signed in sees: the public site,
 * which would otherwise carry the admin bar, and the releases page on GitHub.
 * Opening this second browser for the twenty others would be time wasted on
 * every run.
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
 * Refuses to photograph anything that is not the expected page.
 *
 * Two shots went to production without anyone noticing: a raw JSON dump,
 * because `/submissions` is the API and not a screen, and the Symfony
 * exception trace of a 404, disk path included, on the card that presents
 * the public site. Playwright succeeds in both cases: the URL answers, so
 * `goto` is happy, and the file gets written.
 *
 * **A URL that answers is not a page.** So the status code, the content type
 * and the signature of Symfony's error page are checked, and it fails before
 * writing rather than leaving the picture for someone to review.
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

        // `networkidle` waits for a silence GitHub never quite offers; for
        // an external URL, the loaded document is enough and `prepare` does
        // the rest of the waiting.
        if (shot.before) await shot.before(target);

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

        if (shot.after) await shot.after(target);

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
