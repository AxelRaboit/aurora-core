import { expect, test } from "@playwright/test";

async function loginAsAdmin(page) {
    await page.goto("/login");
    await page
        .locator("input[type='email'], input[name*='email']")
        .first()
        .fill("dev@aurora.app");
    await page.locator("input[type='password']").first().fill("password");
    await page.locator("button[type='submit']").first().click();
    await page.waitForURL(/\/admin/);
}

test.describe("Confirm modal a11y", () => {
    test("delete uses an accessible AppModal (no native window.confirm)", async ({
        page,
    }) => {
        await loginAsAdmin(page);

        // Track any native dialog so we can assert it never fires after the refactor.
        let nativeDialogTriggered = false;
        page.on("dialog", async (dialog) => {
            nativeDialogTriggered = true;
            await dialog.dismiss();
        });

        await page.goto("/admin/crm/contacts");

        // Click the first delete button (icon button labelled "Supprimer" / "Delete" via :title).
        const deleteButton = page
            .locator('button[title="Supprimer"], button[title="Delete"]')
            .first();

        // Skip silently if the page has no deletable row in this fixture seed.
        if ((await deleteButton.count()) === 0) {
            test.skip(true, "No deletable contact in seed");
        }

        await deleteButton.click();

        // The modal must be a real ARIA dialog, not a window.confirm.
        const modal = page
            .locator('div[role="dialog"][aria-modal="true"]')
            .first();
        await expect(modal).toBeVisible();

        // Cancel and ensure we returned to the list without page reload.
        await modal
            .locator("button")
            .filter({ hasText: /annuler|cancel/i })
            .first()
            .click();
        await expect(modal).toBeHidden();

        expect(nativeDialogTriggered).toBe(false);
    });
});
