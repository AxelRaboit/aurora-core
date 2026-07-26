import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";

export function useDashboardCharts(stats) {
    const { t } = useI18n();
    const { formatMonthYear } = useDateFormat();

    const postsByMonthData = computed(() => {
        const series = stats.value.postsByMonth ?? [];
        return {
            labels: series.map((s) => formatMonthYear(s.month)),
            datasets: [
                {
                    label: t("backend.stats.posts"),
                    data: series.map((s) => s.count),
                    borderColor: "#818cf8",
                    backgroundColor: "rgba(129, 140, 248, 0.15)",
                    fill: true,
                    tension: 0.3,
                },
            ],
        };
    });

    return { postsByMonthData };
}
