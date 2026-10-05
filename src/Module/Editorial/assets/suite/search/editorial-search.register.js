import { FileText, Tags } from "lucide-vue-next";
import { registerSearchSection } from "@/shared/search/searchSectionRegistry.js";

registerSearchSection({
    key: "posts",
    kind: "post",
    labelKey: "suite.search.sections.posts",
    icon: FileText,
    order: 20,
});

registerSearchSection({
    key: "terms",
    kind: "term",
    labelKey: "suite.search.sections.terms",
    icon: Tags,
    order: 30,
});
