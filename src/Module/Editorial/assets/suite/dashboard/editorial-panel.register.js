import { FileText } from "lucide-vue-next";
import { registerDashboardPanel } from "@/shared/dashboard/panelRegistry.js";

registerDashboardPanel({
    id: "editorial",
    labelKey: "suite.nav.sections.editorial",
    icon: FileText,
    component: () => import("./EditorialPanel.vue"),
    todo: (stats) => [
        {
            key: "comments_pending",
            labelKey: "suite.stats.todo.comments_pending",
            count: stats.commentsByStatus?.pending ?? 0,
            href: stats.commentsPendingPath,
            tone: "warning",
        },
        {
            key: "posts_pending_review",
            labelKey: "suite.stats.todo.posts_pending_review",
            count: stats.byStatus?.pending_review ?? 0,
            href: stats.postsPendingReviewPath,
            tone: "warning",
        },
    ],
});
