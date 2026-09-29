import { NotebookPen } from "lucide-vue-next";
import { registerSearchSection } from "@/shared/search/searchSectionRegistry.js";

// The same icon as the notebook's menu entry. Rows carry their own `path`, so
// the palette's generic row draws them and opens the note: nothing to add in
// core for this section.
registerSearchSection({
    key: "notes",
    kind: "note",
    labelKey: "backend.search.sections.notes",
    icon: NotebookPen,
    order: 70,
});
