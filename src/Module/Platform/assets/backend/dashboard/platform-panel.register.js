import { Users } from "lucide-vue-next";
import { registerDashboardPanel } from "@/shared/dashboard/panelRegistry.js";

registerDashboardPanel({
    id: "platform",
    labelKey: "backend.nav.sections.platform",
    icon: Users,
    component: () => import("./PlatformPanel.vue"),
});
