import { FolderKanban } from "lucide-vue-next";
import { registerDashboardPanel } from "@/shared/dashboard/panelRegistry.js";

registerDashboardPanel({
    id: "studio",
    labelKey: "suite.nav.sections.studio",
    icon: FolderKanban,
    component: () => import("./StudioPanel.vue"),
    todo: (stats) => {
        // The space calendar, as a list, on the state that waits.
        const onState = (state) =>
            stats.calendarPath
                ? `${stats.calendarPath}?${new URLSearchParams({ scope: stats.scope ?? "mine", view: "list", state })}`
                : null;

        return [
            {
                key: "follow_ups",
                labelKey: "suite.stats.todo.follow_ups_due",
                count: stats.followUpsDue ?? 0,
                href: stats.followUpsPath,
                tone: "warning",
            },
            {
                key: "countersign",
                labelKey: "suite.stats.todo.countersign",
                count: stats.awaitingCountersignature ?? 0,
                href: stats.contractsToCountersignPath,
                tone: "warning",
            },
            {
                key: "unread_client_messages",
                labelKey: "suite.stats.todo.unread_client_messages",
                count: stats.unreadClientMessages ?? 0,
                href: stats.unreadClientMessagesPath,
                tone: "warning",
            },
            {
                key: "contracts_waiting_long",
                labelKey: "suite.stats.todo.contracts_waiting_long",
                count: stats.contractsWaitingLong ?? 0,
                href: stats.contractsWithCustomerPath,
                tone: "warning",
            },
            {
                key: "contract_links_expiring",
                labelKey: "suite.stats.todo.contract_links_expiring",
                count: stats.contractLinksExpiring ?? 0,
                href: stats.contractsWithCustomerPath,
                tone: "warning",
            },
            {
                key: "space_links_expiring",
                labelKey: "suite.stats.todo.space_links_expiring",
                count: stats.spaceLinksExpiring ?? 0,
                href: stats.spaceLinksExpiringPath,
                tone: "warning",
            },
            {
                key: "contracts_refused",
                labelKey: "suite.stats.todo.contracts_refused",
                count: stats.contractsRefused ?? 0,
                href: stats.contractsToSendPath,
                tone: "warning",
            },
            {
                key: "contracts_expired",
                labelKey: "suite.stats.todo.contracts_expired",
                count: stats.contractsExpired ?? 0,
                href: stats.contractsToSendPath,
                tone: "warning",
            },
            {
                key: "missed",
                labelKey: "suite.stats.todo.missed",
                count: stats.missed ?? 0,
                href: onState("missed"),
                tone: "danger",
            },
            {
                key: "late_review",
                labelKey: "suite.stats.todo.late_review",
                count: stats.lateReview ?? 0,
                href: onState("late_review"),
                tone: "danger",
            },
            {
                key: "changes_requested",
                labelKey: "suite.stats.todo.changes_requested",
                count: stats.changesRequested ?? 0,
                href: onState("changes_requested"),
                tone: "warning",
            },
        ];
    },
});
