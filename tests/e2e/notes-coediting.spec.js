import { expect, test } from "@playwright/test";

/**
 * Two people writing in the same note, and the one thing no other test can
 * say: that the two texts converge on screen.
 *
 * Everything else about co-editing is checked without a browser - the
 * protocol's elections and refusals in `noteCoeditProtocol.test.js`, the
 * caret measurement in `caretPosition.test.js`, and whether a real hub takes
 * the publish grant in `NoteLiveHubAgainstARealHubTest`. What none of them
 * reaches is the whole point of the feature: somebody types, and somebody
 * else sees it. That needs two browsers, a hub, and a round trip through
 * both.
 *
 * **Serial, and two contexts rather than two pages.** The two writers have to
 * be two different accounts - the elections compare account ids, and a single
 * session opened twice would be one person twice over, which is the one shape
 * the protocol is allowed to ignore.
 *
 * Skipped, loudly, without a hub: same trade as the R2 and hub suites in PHP.
 * The point of a suite is that it stays green for somebody who has never
 * started Docker.
 */
test.describe.configure({ mode: "serial" });

const HUB =
    process.env.E2E_MERCURE_URL ?? "http://localhost:3000/.well-known/mercure";

/** The demo accounts, which `make demo` creates with this password. */
const OWNER = { email: "dev@aurora.app", password: "password" };
const TEAMMATE = { email: "marie.dupont@aurora.app", password: "password" };

/** The demo's shared space: open to the whole back office, Marie writes in it. */
const SPACE_NAME = "Guide de l'agence";

async function hubIsUp() {
    try {
        // 401 is the right answer from a hub with no token, and it is the
        // answer that proves one is listening. A refusal to connect is not.
        const response = await fetch(`${HUB}?topic=ping`, {
            signal: AbortSignal.timeout(2000),
        });

        return response.status === 401 || response.ok;
    } catch {
        return false;
    }
}

async function signIn(page, { email, password }) {
    await page.goto("/suite/platform/login");
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', password);
    await page.click('button[type="submit"]');
    await page.waitForURL((url) => !url.pathname.includes("/login"), {
        timeout: 15_000,
    });
}

/**
 * The shared space, asked for rather than guessed at on screen.
 *
 * The first settings button in the panel belongs to the **personal** space,
 * which has no co-editing switch at all - a private notebook does not change
 * the promise it made. Clicking it and then waiting for a switch that is
 * correctly absent is how the first version of this test failed, and the
 * failure was right.
 */
async function sharedSpace(page) {
    const response = await page.request.get("/suite/notes/spaces");
    const body = await response.json();
    const space = (body.spaces ?? []).find((one) => one.name === SPACE_NAME);

    expect(
        space,
        `The demo space "${SPACE_NAME}" is missing: run make demo.`,
    ).toBeTruthy();

    return space;
}

/** The editor of a note of the shared space. */
async function openSharedNote(page, spaceId) {
    const response = await page.request.get("/suite/notes/markdown/list");
    const body = await response.json();
    const note = (body.notes ?? []).find(
        (one) => Number(one.spaceId) === Number(spaceId),
    );

    expect(note, "The demo shared space holds no note.").toBeTruthy();

    // Clicked in the menu tree rather than navigated to: the editor opens
    // without a page load, which is how somebody actually gets there, and a
    // direct navigation to the note's address came back `ERR_ABORTED` - the
    // page starts its own requests as it mounts and the navigation never
    // settles.
    // Only when we are not already there, and `commit` rather than the
    // default `load`. The module rewrites its own address as it mounts - it
    // opens the note last read - so a navigation that waits for the page to
    // settle is cancelled by the page itself and comes back
    // `net::ERR_ABORTED` on a page that is in fact up. It failed three runs
    // out of four here, and never for the reason the test is about, which is
    // the worst kind of red.
    if (!new URL(page.url()).pathname.startsWith("/suite/notes/markdown")) {
        await page.goto("/suite/notes/markdown", { waitUntil: "commit" });
    }

    const row = page.locator(`[data-note-row="${note.id}"]`).first();
    await expect(row).toBeVisible({ timeout: 20_000 });
    await row.click();

    const field = page.locator("textarea").first();
    await expect(field).toBeVisible({ timeout: 15_000 });

    return field;
}

test.beforeAll(async () => {
    test.skip(!(await hubIsUp()), `No hub at ${HUB}: run \`make hub-start\`.`);
});

test("le texte tapé par l'un apparaît chez l'autre", async ({ browser }) => {
    // Two sign-ins, a settings round trip, two editors and a twenty-second
    // presence beat to wait on: the suite's thirty seconds are for a page
    // that loads, not for a scene with two people in it.
    test.setTimeout(150_000);

    const ownerContext = await browser.newContext();
    const teammateContext = await browser.newContext();

    try {
        const owner = await ownerContext.newPage();
        const teammate = await teammateContext.newPage();

        await signIn(owner, OWNER);
        await signIn(teammate, TEAMMATE);

        // The space has to allow it, and the owner is the one who may say so.
        await owner.goto("/suite/notes/markdown");
        const space = await sharedSpace(owner);

        await owner.locator(`[data-space-settings="${space.id}"]`).click();

        const toggle = owner.locator("[data-space-coediting] input").first();
        await expect(toggle).toBeVisible({ timeout: 10_000 });
        if (!(await toggle.isChecked())) await toggle.check();
        await owner.locator("[data-space-save]").click();

        const ownerField = await openSharedNote(owner, space.id);
        const teammateField = await openSharedNote(teammate, space.id);

        // Both are in the room before anybody types: the first one in seeds
        // the document, and somebody arriving later asks for it. Typing before
        // the second has joined would prove the seed and not the sync.
        await expect(owner.locator("[data-note-room]")).toBeVisible({
            timeout: 30_000,
        });

        // **The save route, closed on both sides.** Without this the test
        // passed with co-editing switched off: the owner's ordinary autosave
        // persisted the text, the hub pushed "the note changed", and the
        // teammate's editor pulled it - the level-2 machinery doing its job
        // and convincing nobody. With the route refused, the only road left
        // to the other screen is the shared document, so the assertion below
        // says what it means. Verified by removing the local-edit watcher in
        // `useNoteCoedit` and watching this go red.
        for (const page of [owner, teammate]) {
            await page.route("**/suite/notes/markdown/*/update", (route) =>
                route.abort(),
            );
        }

        const typed = `écrit par le premier ${Date.now()}`;
        await ownerField.click();
        await ownerField.press("End");
        await ownerField.type(`\n\n${typed}`, { delay: 15 });

        // The whole feature, in one assertion.
        await expect(teammateField).toHaveValue(new RegExp(typed), {
            timeout: 20_000,
        });
    } finally {
        await ownerContext.close();
        await teammateContext.close();
    }
});
