import { FolderKanban } from "lucide-vue-next";
import { registerDashboardPanel } from "@/shared/dashboard/panelRegistry.js";

registerDashboardPanel({
    id: "studio",
    labelKey: "backend.nav.sections.studio",
    icon: FolderKanban,
    component: () => import("./StudioPanel.vue"),
});
