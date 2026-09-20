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

test.describe("Admin Themes", () => {
    test("navigates to themes page", async ({ page }) => {
        await loginAsAdmin(page);
        await page.goto("/admin/themes");

        const heading = page.getByRole("heading").first();
        await expect(heading).toBeVisible();
    });

    test("opens create theme modal", async ({ page }) => {
        await loginAsAdmin(page);
        await page.goto("/admin/themes");

        const createButton = page
            .locator("button")
            .filter({ hasText: /nouveau thème/i })
            .first();
        await expect(createButton).toBeVisible();
        await createButton.click();

        const modal = page
            .locator(
                "[role='dialog'], .modal, [class*='modal'], [class*='overlay']",
            )
            .first();
        await expect(modal).toBeVisible({ timeout: 5000 });
    });
});
