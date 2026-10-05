import { AlarmClock, CalendarDays } from "lucide-vue-next";
import { registerSearchSection } from "@/shared/search/searchSectionRegistry.js";

registerSearchSection({
    key: "events",
    kind: "event",
    labelKey: "suite.search.sections.events",
    icon: CalendarDays,
    order: 50,
});

registerSearchSection({
    key: "reminders",
    kind: "reminder",
    labelKey: "suite.search.sections.reminders",
    icon: AlarmClock,
    order: 60,
});
