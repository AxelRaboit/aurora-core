import { FolderOpen } from "lucide-vue-next";
import { registerDashboardPanel } from "@/shared/dashboard/panelRegistry.js";

registerDashboardPanel({
    id: "ged",
    labelKey: "backend.nav.sections.ged",
    icon: FolderOpen,
    component: () => import("./GedPanel.vue"),
});
