import {
    Building2,
    FileSignature,
    NotebookText,
    PanelsTopLeft,
    Presentation,
    ScrollText,
    SquareKanban,
} from "lucide-vue-next";
import { registerSearchSection } from "@/shared/search/searchSectionRegistry.js";

// Studio's seven sections, each keyed as `StudioBackendSearchProvider` returns
// them and drawn by the palette's generic row (title, subtitle, path). The
// icons are the ones the Studio menu entries already use, so a result reads as
// belonging to the screen it opens. The label keys are written out rather than
// built from the section key, so the test that checks keys resolve can see them.
registerSearchSection({
    key: "spaces",
    kind: "space",
    labelKey: "backend.search.sections.spaces",
    icon: PanelsTopLeft,
    order: 80,
});

registerSearchSection({
    key: "space_contents",
    kind: "space_content",
    labelKey: "backend.search.sections.space_contents",
    icon: SquareKanban,
    order: 90,
});

registerSearchSection({
    key: "customers",
    kind: "customer",
    labelKey: "backend.search.sections.customers",
    icon: Building2,
    order: 100,
});

registerSearchSection({
    key: "contracts",
    kind: "contract",
    labelKey: "backend.search.sections.contracts",
    icon: FileSignature,
    order: 110,
});

registerSearchSection({
    key: "contract_templates",
    kind: "contract_template",
    labelKey: "backend.search.sections.contract_templates",
    icon: ScrollText,
    order: 120,
});

registerSearchSection({
    key: "decks",
    kind: "deck",
    labelKey: "backend.search.sections.decks",
    icon: Presentation,
    order: 130,
});

registerSearchSection({
    key: "deliverables",
    kind: "deliverable",
    labelKey: "backend.search.sections.deliverables",
    icon: NotebookText,
    order: 135,
});
