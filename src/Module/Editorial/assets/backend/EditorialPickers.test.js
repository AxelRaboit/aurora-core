import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { mount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";

window.__isAdmin__ = true;

const PostTypesApp = (await import("./post-types/PostTypesApp.vue")).default;
const TaxonomiesApp = (await import("./taxonomies/TaxonomiesApp.vue")).default;
const MenusApp = (await import("./menus/MenusApp.vue")).default;
const FormsApp = (await import("./forms/FormsApp.vue")).default;

const i18n = createTestI18n();

const paths = (names) =>
    names.reduce((all, name) => ({ ...all, [name]: `/${name}` }), {});

const CASES = [
    {
        name: "post types",
        component: PostTypesApp,
        props: {
            ...paths([
                "createPath",
                "updatePathTemplate",
                "deletePathTemplate",
                "fieldCreatePathTemplate",
                "fieldEditPathTemplate",
                "fieldDeletePathTemplate",
                "fieldReorderPathTemplate",
            ]),
            postTypes: [
                {
                    id: 1,
                    label: "Article",
                    slug: "post",
                    isBuiltIn: true,
                    supports: [],
                    fields: [],
                },
                {
                    id: 2,
                    label: "Projet",
                    slug: "project",
                    isBuiltIn: false,
                    supports: [],
                    fields: [],
                },
            ],
            supportOptions: [],
            fieldTypes: [],
        },
    },
    {
        name: "taxonomies",
        component: TaxonomiesApp,
        props: {
            ...paths([
                "createPath",
                "updatePathTemplate",
                "deletePathTemplate",
                "termCreatePathTemplate",
                "termEditPathTemplate",
                "termDeletePathTemplate",
                "termReorderPathTemplate",
            ]),
            taxonomies: [
                {
                    id: 7,
                    slug: "category",
                    translations: {},
                    terms: [],
                    postTypes: [],
                },
            ],
            postTypes: [],
            locales: ["fr"],
        },
    },
    {
        name: "menus",
        component: MenusApp,
        props: {
            ...paths([
                "targetsPath",
                "updatePathTemplate",
                "itemCreatePathTemplate",
                "itemEditPathTemplate",
                "itemDeletePathTemplate",
                "itemReorderPathTemplate",
            ]),
            menus: [
                {
                    id: 4,
                    name: "Principal",
                    location: "header",
                    locationKnown: true,
                    items: [],
                },
            ],
            locales: ["fr"],
        },
    },
    {
        name: "forms",
        component: FormsApp,
        props: {
            ...paths([
                "createPath",
                "updatePathTemplate",
                "deletePathTemplate",
                "fieldCreatePathTemplate",
                "fieldEditPathTemplate",
                "fieldDeletePathTemplate",
                "fieldReorderPathTemplate",
                "submissionsPathTemplate",
                "exportPathTemplate",
            ]),
            forms: [
                {
                    id: 9,
                    reference: "contact",
                    // The title lives on a translation, the way the page reads it.
                    translations: { fr: { title: "Contact" } },
                    fields: [],
                    submissionCount: 0,
                    isActive: true,
                },
            ],
            locales: ["fr"],
            fieldTypes: [{ value: "text", labelKey: "t", hasOptions: false }],
            conditionLogics: [{ value: "and", labelKey: "l" }],
        },
    },
];

const mounted = [];

beforeEach(() => {
    global.fetch = vi.fn().mockResolvedValue({
        ok: true,
        status: 200,
        // Every key these pages read off a response, so a load that resolves
        // after the assertions cannot blow up the render.
        json: async () => ({
            success: true,
            items: [],
            submissions: [],
            total: 0,
        }),
    });
});

afterEach(() => {
    while (mounted.length) mounted.pop().unmount();
    vi.restoreAllMocks();
});

function render({ component, props }, extra = {}) {
    const wrapper = mount(component, {
        props: { ...props, ...extra },
        global: { plugins: [i18n] },
    });
    mounted.push(wrapper);

    return wrapper;
}

describe.each(CASES)(
    "the $name page, once its picker moved to the menu",
    (testCase) => {
        /**
         * The picker column came out of the template, and with it the loop, the
         * badge and the click handler that set the selection. Vue resolves template
         * references at render, so a name left behind is invisible to the linter
         * and to the bundler - only a mount finds it.
         */
        it("still mounts, with no reference left behind", () => {
            const wrapper = render(testCase);

            expect(wrapper.html()).toBeTruthy();
            expect(wrapper.find("aside").exists()).toBe(false);
        });

        /**
         * Which record is on screen is the server's answer now, arriving in a prop,
         * because each record is a route and the menu links to it.
         */
        it("shows the record the address named, not the first of the list", () => {
            // Keyed by name: the taxonomies page is also handed an (empty)
            // `postTypes` list, which a chain of `??` would happily pick.
            const records = {
                "post types": testCase.props.postTypes,
                taxonomies: testCase.props.taxonomies,
                menus: testCase.props.menus,
                forms: testCase.props.forms,
            }[testCase.name];
            const last = records[records.length - 1];

            const wrapper = render(testCase, { activeId: last.id });

            expect(wrapper.html()).toContain(
                String(
                    last.label ??
                        last.name ??
                        last.translations?.fr?.title ??
                        last.slug,
                ),
            );
        });
    },
);
