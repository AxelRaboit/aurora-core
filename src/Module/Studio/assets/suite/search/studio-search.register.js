import {
    Building2,
    FileSignature,
    Link2,
    MessagesSquare,
    NotebookText,
    PanelsTopLeft,
    Paperclip,
    ScrollText,
    SquareKanban,
} from "lucide-vue-next";
import { registerSearchSection } from "@/shared/search/searchSectionRegistry.js";

// Studio's nine sections, each keyed as `StudioSuiteSearchProvider` returns
// them and drawn by the palette's generic row (title, subtitle, path). The
// icons are the ones the Studio menu entries and the space tabs already use, so
// a result reads as belonging to the screen it opens. The label keys are written out rather than
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

// What is inside a space, right after its cards: the tabs a result opens.
registerSearchSection({
    key: "space_resources",
    kind: "space_resource",
    labelKey: "suite.search.sections.space_resources",
    icon: Link2,
    order: 92,
});

registerSearchSection({
    key: "space_files",
    kind: "space_file",
    labelKey: "suite.search.sections.space_files",
    icon: Paperclip,
    order: 94,
});

registerSearchSection({
    key: "space_messages",
    kind: "space_message",
    labelKey: "suite.search.sections.space_messages",
    icon: MessagesSquare,
    order: 96,
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
