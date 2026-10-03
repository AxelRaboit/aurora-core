import { describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import NoteSidePanel from "./NoteSidePanel.vue";

const i18n = createTestI18n();

/**
 * Le plan de la note, dans le panneau latéral.
 *
 * Il se lit dans le texte, sans rien demander au serveur : basculer sur le
 * plan ne doit pas appeler les liens, et un titre cliqué dit à la page où
 * aller.
 */
function render(content) {
    const fetchBacklinks = vi
        .fn()
        .mockResolvedValue({ ok: true, payload: { backlinks: [] } });
    const fetchUnlinkedMentions = vi
        .fn()
        .mockResolvedValue({ ok: true, payload: { mentions: [] } });
    const wrapper = mount(NoteSidePanel, {
        props: { noteId: 1, content, fetchBacklinks, fetchUnlinkedMentions },
        global: { plugins: [i18n] },
    });

    return { wrapper, fetchBacklinks, fetchUnlinkedMentions };
}

describe("NoteSidePanel outline", () => {
    it("lists the headings and asks the page to jump to one", async () => {
        const { wrapper, fetchUnlinkedMentions } = render(
            "# Brief\n\nTexte de six mots ici présent.\n\n## Objectifs\n- Un",
        );
        await flushPromises();

        await wrapper.find('[data-side-tab="outline"]').trigger("click");
        await flushPromises();

        const items = wrapper.findAll("[data-note-outline] li");
        expect(items.map((item) => item.text())).toEqual([
            "Brief",
            "Objectifs",
        ]);
        expect(fetchUnlinkedMentions).not.toHaveBeenCalled();

        await items[1].find("button").trigger("click");
        expect(wrapper.emitted("jump")[0][0]).toMatchObject({
            level: 2,
            text: "Objectifs",
            line: 4,
        });
    });

    it("says how long the note is", async () => {
        const { wrapper } = render("# Brief\n\nTexte de six mots ici présent.");
        await wrapper.find('[data-side-tab="outline"]').trigger("click");

        // Brief (1) + six mots de prose (6) : la clé de traduction reçoit le compte.
        expect(wrapper.find("[data-note-length]").text()).toContain(
            "outline.words",
        );
    });
});
