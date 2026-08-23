import { CalendarDays } from "lucide-vue-next";
import { registerDashboardPanel } from "@/shared/dashboard/panelRegistry.js";

registerDashboardPanel({
    id: "planning",
    labelKey: "backend.nav.sections.planning",
    icon: CalendarDays,
    component: () => import("./PlanningPanel.vue"),
});
