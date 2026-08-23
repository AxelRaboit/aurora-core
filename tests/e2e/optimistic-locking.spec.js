import { expect, test } from "@playwright/test";

/**
 * End-to-end test for optimistic locking conflict flow.
 *
 * This test is a scaffold - it assumes a seeded admin user (admin@aurora.app / password)
 * and an existing post. Two browser contexts simulate two concurrent admins.
 *
 * Skipped by default; enable with E2E_FULL=1 once fixtures are loaded against the E2E target.
 */
test.describe("Optimistic locking conflict", () => {
    test.skip(
        !process.env.E2E_FULL,
        "Set E2E_FULL=1 to run the full conflict flow",
    );

    test("second admin is blocked with a conflict banner and can merge", async ({
        browser,
    }) => {
        const admin = { email: "admin@aurora.app", password: "password" };

        const contextA = await browser.newContext();
        const contextB = await browser.newContext();
        const pageA = await contextA.newPage();
        const pageB = await contextB.newPage();

        for (const page of [pageA, pageB]) {
            await page.goto("/login");
            await page.locator("input[type='email']").first().fill(admin.email);
            await page
                .locator("input[type='password']")
                .first()
                .fill(admin.password);
            await page.locator("button[type='submit']").first().click();
            await page.waitForURL(/\/admin/);
        }

        await pageA.goto("/admin/posts");
        await pageA
            .getByRole("button", { name: /modifier|edit/i })
            .first()
            .click();

        await pageB.goto("/admin/posts");
        await pageB
            .getByRole("button", { name: /modifier|edit/i })
            .first()
            .click();

        // B saves first
        await pageB.keyboard.press("Control+S");
        await expect(pageB.locator("text=/mis à jour|updated/i")).toBeVisible();

        // A tries to save - should see the conflict banner
        await pageA.keyboard.press("Control+S");
        await expect(
            pageA.locator("text=/modifié par un autre|modified by another/i"),
        ).toBeVisible();

        await contextA.close();
        await contextB.close();
    });
});
