import {
    Building2,
    FileSignature,
    NotebookText,
    PanelsTopLeft,
    ScrollText,
    SquareKanban,
} from "lucide-vue-next";
import { registerSearchSection } from "@/shared/search/searchSectionRegistry.js";

// Studio's six sections, each keyed as `StudioSuiteSearchProvider` returns
// them and drawn by the palette's generic row (title, subtitle, path). The
// icons are the ones the Studio menu entries already use, so a result reads as
// belonging to the screen it opens. The label keys are written out rather than
// built from the section key, so the test that checks keys resolve can see them.
registerSearchSection({
    key: "spaces",
    kind: "space",
    labelKey: "suite.search.sections.spaces",
    icon: PanelsTopLeft,
    order: 80,
});

registerSearchSection({
    key: "space_contents",
    kind: "space_content",
    labelKey: "suite.search.sections.space_contents",
    icon: SquareKanban,
    order: 90,
});

registerSearchSection({
    key: "customers",
    kind: "customer",
    labelKey: "suite.search.sections.customers",
    icon: Building2,
    order: 100,
});

registerSearchSection({
    key: "contracts",
    kind: "contract",
    labelKey: "suite.search.sections.contracts",
    icon: FileSignature,
    order: 110,
});

registerSearchSection({
    key: "contract_templates",
    kind: "contract_template",
    labelKey: "suite.search.sections.contract_templates",
    icon: ScrollText,
    order: 120,
});

registerSearchSection({
    key: "deliverables",
    kind: "deliverable",
    labelKey: "suite.search.sections.deliverables",
    icon: NotebookText,
    order: 135,
});
