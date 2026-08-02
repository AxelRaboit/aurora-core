import { FileText } from "lucide-vue-next";
import { registerDashboardPanel } from "@/shared/dashboard/panelRegistry.js";

registerDashboardPanel({
    id: "editorial",
    labelKey: "backend.nav.sections.editorial",
    icon: FileText,
    component: () => import("./EditorialPanel.vue"),
});
