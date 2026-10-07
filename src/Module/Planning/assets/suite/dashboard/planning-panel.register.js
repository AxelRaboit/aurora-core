import { CalendarDays } from "lucide-vue-next";
import { registerDashboardPanel } from "@/shared/dashboard/panelRegistry.js";

registerDashboardPanel({
    id: "planning",
    labelKey: "suite.nav.sections.planning",
    icon: CalendarDays,
    component: () => import("./PlanningPanel.vue"),
    todo: (stats) => [
        {
            key: "reminders_overdue",
            labelKey: "suite.stats.todo.reminders_overdue",
            count: stats.overdue ?? 0,
            href: stats.path,
            tone: "danger",
        },
    ],
});
