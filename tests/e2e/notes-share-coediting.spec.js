import { expect, test } from "@playwright/test";

/**
 * An account and a guest of a live link writing the same note - a personal
 * one, which only the link opens to the room.
 *
 * The protocol's rules are tested without a browser, and the guest route's
 * refusals in `NoteShareLiveTest`. What only two browsers can show is that the
 * owner and the guest end up in the same room and hear each other: the case
 * where the account arrives second, with the lowest id, is the one that split
 * a session in two before `answersDocRequest` (09/10/2026).
 *
 * **Both save routes are closed while typing**, as in the colleague test: with
 * them open the autosave and a reload could carry the text and the test would
 * pass for the wrong reason.
 *
 * Skipped without a hub, like every live suite.
 */
test.describe.configure({ mode: "serial" });

const HUB =
    process.env.E2E_MERCURE_URL ?? "http://localhost:3000/.well-known/mercure";

/** The demo owner, or whoever the environment names. */
const OWNER = {
    email: process.env.E2E_OWNER_EMAIL ?? "dev@aurora.app",
    password: process.env.E2E_OWNER_PASSWORD ?? "password",
};

async function hubIsUp() {
    try {
        const response = await fetch(`${HUB}?topic=ping`, {
            signal: AbortSignal.timeout(2000),
        });

        return response.status === 401 || response.ok;
    } catch {
        return false;
    }
}

async function signIn(page) {
    await page.goto("/suite/platform/login");
    await page.fill('input[name="email"]', OWNER.email);
    await page.fill('input[name="password"]', OWNER.password);
    await page.click('button[type="submit"]');
    await page.waitForURL((url) => !url.pathname.includes("/login"), {
        timeout: 15_000,
    });
}

test.beforeAll(async () => {
    test.skip(!(await hubIsUp()), `No hub at ${HUB}: run \`make hub-start\`.`);
});

test("un invité et le propriétaire écrivent la même note personnelle", async ({
    browser,
}) => {
    test.setTimeout(150_000);

    const ownerContext = await browser.newContext();
    const guestContext = await browser.newContext();

    try {
        const owner = await ownerContext.newPage();
        await signIn(owner);

        // A note of the owner's personal space: no space setting can open it,
        // only the link's own box.
        const created = await (
            await owner.request.post("/suite/notes/markdown/create", {
                data: { title: "Écrite à deux", content: "Début.\n" },
            })
        ).json();
        const noteId = created.note.id;

        const shared = await (
            await owner.request.post("/suite/notes/markdown/shares", {
                data: { noteId, canWrite: true, coediting: true },
            })
        ).json();
        expect(shared.link.coediting).toBe(true);

        // The guest first: the account then arrives with the lowest id.
        const guest = await guestContext.newPage();
        await guest.goto(shared.link.url);
        const guestField = guest.locator("[data-share-content-field]");
        await expect(guestField).toBeVisible({ timeout: 15_000 });
        await expect(guest.locator("[data-share-live-hint]")).toBeVisible({
            timeout: 30_000,
        });

        await owner.goto(`/suite/notes/markdown/${noteId}`, {
            waitUntil: "commit",
        });
        const ownerField = owner.locator("textarea").first();
        await expect(ownerField).toBeVisible({ timeout: 15_000 });
        await expect(owner.locator("[data-note-room]")).toBeVisible({
            timeout: 30_000,
        });

        await owner.route("**/suite/notes/markdown/*/update", (route) =>
            route.abort(),
        );
        await guest.route("**/notes/share/*/*/save", (route) => route.abort());

        const fromTheGuest = `écrit par l'invité ${Date.now()}`;
        await guestField.click();
        await guestField.press("End");
        await guestField.type(`\n${fromTheGuest}`, { delay: 15 });
        await expect(ownerField).toHaveValue(new RegExp(fromTheGuest), {
            timeout: 20_000,
        });

        const fromTheOwner = `écrit par le propriétaire ${Date.now()}`;
        await ownerField.click();
        await ownerField.press("End");
        await ownerField.type(`\n${fromTheOwner}`, { delay: 15 });
        await expect(guestField).toHaveValue(new RegExp(fromTheOwner), {
            timeout: 20_000,
        });
    } finally {
        await ownerContext.close();
        await guestContext.close();
    }
});
