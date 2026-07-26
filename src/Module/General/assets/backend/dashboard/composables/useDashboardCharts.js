import { computed } from "vue";
import { useI18n } from "vue-i18n";

export function useDashboardCharts(stats) {
    const { t } = useI18n();

    const postsByMonthData = computed(() => {
        const series = stats.value.postsByMonth ?? [];
        return {
            labels: series.map((s) => s.month),
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
