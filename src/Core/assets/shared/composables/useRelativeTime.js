import { ref, computed, watch, onBeforeUnmount } from "vue";
import { useI18n } from "vue-i18n";

/**
 * Reactive "X seconds ago" / "il y a 2 min" string for a Date ref.
 * Re-renders on a fixed `tickMs` cadence (default 15s) so the displayed
 * value stays fresh without spinning the event loop.
 *
 * Uses Intl.RelativeTimeFormat with the current vue-i18n locale.
 *
 * @param {import('vue').Ref<Date|null>} dateRef
 * @param {object} [options]
 * @param {number} [options.tickMs=15000]
 */
export function useRelativeTime(dateRef, { tickMs = 15_000 } = {}) {
    const { locale } = useI18n();
    const now = ref(new Date());

    let intervalId = null;

    function startTicking() {
        stopTicking();
        intervalId = setInterval(() => {
            now.value = new Date();
        }, tickMs);
    }

    function stopTicking() {
        if (intervalId !== null) {
            clearInterval(intervalId);
            intervalId = null;
        }
    }

    // Only tick while there's a date to render.
    watch(
        dateRef,
        (date) => {
            if (date) {
                now.value = new Date();
                startTicking();
            } else {
                stopTicking();
            }
        },
        { immediate: true },
    );

    onBeforeUnmount(stopTicking);

    const relative = computed(() => {
        const date = dateRef.value;
        if (!date) return "";

        const differenceSeconds = Math.round(
            (date.getTime() - now.value.getTime()) / 1000,
        );
        const absoluteSeconds = Math.abs(differenceSeconds);

        const formatter = new Intl.RelativeTimeFormat(locale.value, {
            numeric: "auto",
        });

        if (absoluteSeconds < 60)
            return formatter.format(differenceSeconds, "second");
        if (absoluteSeconds < 3_600)
            return formatter.format(
                Math.round(differenceSeconds / 60),
                "minute",
            );
        if (absoluteSeconds < 86_400)
            return formatter.format(
                Math.round(differenceSeconds / 3_600),
                "hour",
            );
        return formatter.format(Math.round(differenceSeconds / 86_400), "day");
    });

    return { relative };
}
