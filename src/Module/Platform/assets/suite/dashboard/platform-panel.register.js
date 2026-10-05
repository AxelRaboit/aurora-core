import { Users } from "lucide-vue-next";
import { registerDashboardPanel } from "@/shared/dashboard/panelRegistry.js";

registerDashboardPanel({
    id: "platform",
    labelKey: "suite.nav.sections.platform",
    icon: Users,
    component: () => import("./PlatformPanel.vue"),
});
