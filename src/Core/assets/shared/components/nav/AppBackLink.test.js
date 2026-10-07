import { describe, it, expect, vi, afterEach } from "vitest";
import { mount } from "@vue/test-utils";
import AppBackLink from "./AppBackLink.vue";

const original = Object.getOwnPropertyDescriptor(
    Document.prototype,
    "referrer",
);

function cameFrom(url) {
    Object.defineProperty(document, "referrer", {
        configurable: true,
        get: () => url,
    });
}

afterEach(() => {
    if (original) Object.defineProperty(document, "referrer", original);
    else delete document.referrer;
    vi.restoreAllMocks();
});

describe("AppBackLink", () => {
    /**
     * On the gutter, not 8px before it: on a phone the box touched the edge
     * of the screen (07/10/2026). Thirty-eight pixels, like the commands.
     */
    it("sits on the gutter at the height of the bar", () => {
        const classes = mount(AppBackLink, {
            props: { href: "/suite/editorial/posts", label: "Publications" },
        })
            .find("a")
            .classes();

        expect(classes).not.toContain("-ml-2");
        expect(classes).toContain("size-9.5");
        expect(classes).toContain("border");
    });

    it("names where it goes, hidden on a phone but still its text", () => {
        const link = mount(AppBackLink, {
            props: { href: "/suite/editorial/posts", label: "Publications" },
        }).find("a");

        expect(link.attributes("href")).toBe("/suite/editorial/posts");
        expect(link.attributes("title")).toBe("Publications");
        expect(link.find("span").text()).toBe("Publications");
        expect(link.find("span").classes()).toEqual(
            expect.arrayContaining(["sr-only", "sm:not-sr-only"]),
        );
    });

    it("goes back in history when the previous page is the list it leads to", async () => {
        cameFrom(
            `${window.location.origin}/suite/editorial/posts?status=draft`,
        );
        vi.spyOn(window.history, "length", "get").mockReturnValue(3);
        const back = vi
            .spyOn(window.history, "back")
            .mockImplementation(() => {});

        await mount(AppBackLink, {
            props: { href: "/suite/editorial/posts", label: "Publications" },
        })
            .find("a")
            .trigger("click");

        expect(back).toHaveBeenCalled();
    });

    it("follows its link when the reader came from elsewhere", async () => {
        cameFrom(`${window.location.origin}/suite`);
        const back = vi
            .spyOn(window.history, "back")
            .mockImplementation(() => {});

        await mount(AppBackLink, {
            props: { href: "/suite/editorial/posts", label: "Publications" },
        })
            .find("a")
            .trigger("click");

        expect(back).not.toHaveBeenCalled();
    });

    it("hands the click to a page that goes back without leaving", async () => {
        const onBack = vi.fn();
        const wrapper = mount(AppBackLink, {
            props: {
                href: "/suite/notes/markdown",
                label: "Toutes les notes",
                onBack,
            },
        });

        await wrapper.find("a").trigger("click");

        expect(onBack).toHaveBeenCalledOnce();
        expect(wrapper.find("a").attributes("href")).toBe(
            "/suite/notes/markdown",
        );
    });
});
