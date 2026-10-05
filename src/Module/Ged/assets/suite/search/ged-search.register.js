import { Image } from "lucide-vue-next";
import { registerSearchSection } from "@/shared/search/searchSectionRegistry.js";

registerSearchSection({
    key: "media",
    kind: "media",
    labelKey: "suite.search.sections.media",
    icon: Image,
    order: 40,
});
