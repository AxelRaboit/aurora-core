import { computed, onMounted, ref, watch } from "vue";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";
import { gridWindow, monthGrid } from "./monthGrid.js";
import { timeGridWindow, visibleDays } from "./timeGrid.js";

/**
 * Where the reader is, which calendars are showing, and the events between.
 *
 * Position is one date and one view, not a year and a month. Switching from the
 * month to the week has to land on the week containing where you already were,
 * and two pieces of state would have to be kept agreeing every time either
 * changed - which is the bug where paging in one view moves the other.
 *
 * Both live in the URL. A calendar is a place you send somebody - "look at
 * Tuesday" - and a view whose position is only in memory cannot be linked to or
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

    const VIEWS = ["day", "week", "month"];

    const params = new URLSearchParams(window.location.search);
    const anchor = ref(readDateFromUrl(params) ?? new Date());
    const view = ref(
        VIEWS.includes(params.get("view")) ? params.get("view") : "month",
    );

    /**
     * The date in the URL, or null for anything that is not one.
     *
     * Built field by field rather than handed to `new Date(string)`, which reads
     * a bare `YYYY-MM-DD` as UTC midnight - so west of Greenwich the calendar
     * would open on the day before the one in the link.
     */
    function readDateFromUrl(search) {
        const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(
            search.get("date") ?? "",
        );
        if (null === match) {
            return null;
        }

        const [, y, m, day] = match.map(Number);
        const date = new Date(y, m - 1, day);

        // A URL somebody typed can say 2026-13-40. `new Date` rolls that into a
        // real date silently, so it is compared back rather than trusted.
        return date.getMonth() === m - 1 && date.getDate() === day
            ? date
            : null;
    }

    function writeStateToUrl() {
        const url = new URL(window.location.href);
        const pad = (n) => String(n).padStart(2, "0");
        const date = anchor.value;

        url.searchParams.set("view", view.value);
        url.searchParams.set(
            "date",
            `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`,
        );
        window.history.replaceState({}, "", url);
    }

    const year = computed(() => anchor.value.getFullYear());
    const month = computed(() => anchor.value.getMonth());

    const cells = computed(() => monthGrid(year.value, month.value));

    /** The days the hourly views draw. Empty in the month view, which uses cells. */
    const days = computed(() =>
        "month" === view.value ? [] : visibleDays(anchor.value, view.value),
    );

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
            const { from, to } =
                "month" === view.value
                    ? gridWindow(year.value, month.value)
                    : timeGridWindow(anchor.value, view.value);
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

    /**
     * Page by whatever the current view shows: a month, a week, or a day.
     *
     * One function for all three, because "previous" means the same thing to the
     * reader in each - and three would be three places to keep the URL writing.
     */
    function go(offset) {
        if ("month" === view.value) {
            // The first of the month, so paging from the 31st does not skip
            // February: `new Date(2026, 0, 31)` plus one month is 3 March.
            anchor.value = new Date(year.value, month.value + offset, 1);

            return;
        }

        const next = new Date(anchor.value);
        next.setDate(next.getDate() + offset * ("week" === view.value ? 7 : 1));
        anchor.value = next;
    }

    function goToToday() {
        anchor.value = new Date();
    }

    /**
     * Switching view keeps the date, which is the whole point of storing one.
     *
     * Coming out of the month view the anchor is the first of the month, so a
     * reader who was looking at August lands on the week of 1 August rather than
     * on today - the range they were already reading.
     */
    function setView(next) {
        if (VIEWS.includes(next)) {
            view.value = next;
        }
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

    // One watcher for both, so switching view on a different date is a single
    // fetch rather than one for the date and one for the view.
    watch([anchor, view], () => {
        writeStateToUrl();
        void load();
    });

    onMounted(() => {
        writeStateToUrl();
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
        view,
        anchor,
        cells,
        days,
        load,
        go,
        goToToday,
        setView,
        toggleCalendar,
        upsertCalendar,
        removeCalendar,
    };
}
