import { computed, onMounted, ref, watch } from "vue";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";
import { gridWindow, monthGrid } from "./monthGrid.js";

/**
 * Which month is on screen, which calendars are showing, and the events between.
 *
 * The month lives in the URL. A calendar is a place you send somebody - "look at
 * September" - and a view whose position is only in memory cannot be linked to or
 * reloaded. Hidden calendars stay in memory on purpose: which of your own
 * calendars you have folded away is not something you send anyone.
 */
export function usePlanningCalendar(props) {
    const { request } = useRequest();

    const calendars = ref([...(props.calendars ?? [])]);
    const events = ref([]);
    const loading = ref(false);

    /** Ids the reader has folded away. Absent means showing. */
    const hidden = ref(new Set());

    const today = new Date();
    const initial = readMonthFromUrl() ?? {
        year: today.getFullYear(),
        month: today.getMonth(),
    };
    const year = ref(initial.year);
    const month = ref(initial.month);

    function readMonthFromUrl() {
        const raw = new URLSearchParams(window.location.search).get("month");
        if (!/^\d{4}-\d{2}$/.test(raw ?? "")) {
            return null;
        }

        const [y, m] = raw.split("-").map(Number);

        // A month outside 1-12 is a URL somebody typed. Falling back to today
        // beats rendering a grid for month 47.
        return m >= 1 && m <= 12 ? { year: y, month: m - 1 } : null;
    }

    function writeMonthToUrl() {
        const url = new URL(window.location.href);
        url.searchParams.set(
            "month",
            `${year.value}-${String(month.value + 1).padStart(2, "0")}`,
        );
        window.history.replaceState({}, "", url);
    }

    const cells = computed(() => monthGrid(year.value, month.value));

    /**
     * Only the events of calendars that are showing.
     *
     * Filtered here rather than refetched: folding a calendar away is a display
     * decision, and a round trip for it would make the grid flicker for something
     * the browser already has.
     */
    const visibleEvents = computed(() =>
        events.value.filter((event) => !hidden.value.has(event.planningId)),
    );

    async function load() {
        loading.value = true;

        try {
            const { from, to } = gridWindow(year.value, month.value);
            const url = new URL(props.eventsPath, window.location.origin);
            url.searchParams.set("from", from.toISOString());
            url.searchParams.set("to", to.toISOString());

            const data = await request(url.pathname + url.search, null, {
                method: HttpMethod.Get,
                noGuard: true,
            });

            // Left as it was on failure rather than emptied: `useRequest` has
            // already said something went wrong, and blanking the month would
            // make a network blip look like a month with nothing in it.
            if (data?.events) {
                events.value = data.events;
            }
        } finally {
            loading.value = false;
        }
    }

    function goToMonth(offset) {
        const next = new Date(year.value, month.value + offset, 1);
        year.value = next.getFullYear();
        month.value = next.getMonth();
    }

    function goToToday() {
        const now = new Date();
        year.value = now.getFullYear();
        month.value = now.getMonth();
    }

    /**
     * Puts a saved calendar into the list, in place or at the end.
     *
     * Spliced rather than refetched, because the write already answered with the
     * serialised calendar: asking the server again for something it just sent
     * would make the sidebar blink for no new information.
     */
    function upsertCalendar(calendar) {
        const at = calendars.value.findIndex(
            (existing) => existing.id === calendar.id,
        );

        if (-1 === at) {
            calendars.value = [...calendars.value, calendar].sort((a, b) =>
                a.name.localeCompare(b.name),
            );

            return;
        }

        const next = [...calendars.value];
        next[at] = calendar;
        calendars.value = next;
    }

    /**
     * Drops a deleted calendar, and stops hiding an id that no longer exists.
     *
     * The second half matters: the hidden set is keyed by id, and leaving a dead
     * id in it would silently hide a future calendar that happens to reuse the
     * number.
     */
    function removeCalendar(id) {
        calendars.value = calendars.value.filter(
            (calendar) => calendar.id !== id,
        );

        if (hidden.value.has(id)) {
            const next = new Set(hidden.value);
            next.delete(id);
            hidden.value = next;
        }
    }

    function toggleCalendar(id) {
        const next = new Set(hidden.value);
        next.has(id) ? next.delete(id) : next.add(id);
        hidden.value = next;
    }

    // One watcher for both, so paging from December to January is a single fetch
    // rather than one for the year and one for the month.
    watch([year, month], () => {
        writeMonthToUrl();
        void load();
    });

    onMounted(() => {
        writeMonthToUrl();
        void load();
    });

    return {
        calendars,
        events,
        visibleEvents,
        hidden,
        loading,
        year,
        month,
        cells,
        load,
        goToMonth,
        goToToday,
        toggleCalendar,
        upsertCalendar,
        removeCalendar,
    };
}
