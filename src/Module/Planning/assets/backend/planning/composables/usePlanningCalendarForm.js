import { ref } from "vue";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { useSaveFlow } from "./useSaveFlow.js";

/**
 * The open calendar, and the six writes the settings modal makes.
 *
 * Distinct from `usePlanningCalendar`, which is where the reader is and what is on
 * screen. This is the calendar as a record being edited: its name, its colour, who
 * it is shared with, and whether it has a published feed.
 */
export function usePlanningCalendarForm(
    props,
    { load, upsertCalendar, removeCalendar, onOpen },
) {
    const { request } = useRequest();
    const { saving, errors, submit } = useSaveFlow(request);

    const openCalendar = ref(null);

    /**
     * The feed address, held only between the request that created it and the
     * modal closing.
     *
     * Not kept in the calendar list: that payload is on every page, and a live
     * credential does not belong there for the sake of showing it a second time.
     */
    const feedUrl = ref("");

    function createCalendar() {
        // `{}` and not null: the modal opens on `calendar !== null`, and a new
        // calendar has no id yet.
        openCalendar.value = {};
        errors.value = {};
        onOpen();
    }

    function editCalendar(calendar) {
        openCalendar.value = calendar;
        errors.value = {};
        feedUrl.value = "";
        onOpen();
    }

    function closeCalendar() {
        openCalendar.value = null;
        feedUrl.value = "";
    }

    /** Keeps the modal on the row the server just wrote, not the one it was given. */
    function adopt(calendar) {
        upsertCalendar(calendar);
        openCalendar.value = calendar;
    }

    async function publishFeed(calendar) {
        const data = await request(
            props.feedCalendarPathTemplate.replace(
                "__id__",
                String(calendar.id),
            ),
        );
        if (!data) return;

        adopt(data.calendar);
        feedUrl.value = data.feedUrl ?? "";
    }

    async function revokeFeed(calendar) {
        const data = await request(
            props.revokeFeedCalendarPathTemplate.replace(
                "__id__",
                String(calendar.id),
            ),
        );
        if (!data) return;

        adopt(data.calendar);
        feedUrl.value = "";
    }

    async function setShares({ calendar, shares }) {
        const data = await request(
            props.sharesCalendarPathTemplate.replace(
                "__id__",
                String(calendar.id),
            ),
            {
                shares: shares.map((share) => ({
                    userId: share.userId,
                    canWrite: share.canWrite,
                })),
            },
        );
        if (!data) return;

        adopt(data.calendar);
    }

    async function saveCalendar(form) {
        const id = openCalendar.value?.id;
        const path = id
            ? props.updateCalendarPathTemplate.replace("__id__", String(id))
            : props.createCalendarPath;

        await submit(path, form, async (data) => {
            upsertCalendar(data.calendar);
            closeCalendar();
            // Reloaded because a calendar's colour is drawn on every one of its
            // events, and renaming it changes what the popover says.
            await load();
        });
    }

    async function removeCalendarAndItsEvents(calendar) {
        const data = await request(
            props.deleteCalendarPathTemplate.replace(
                "__id__",
                String(calendar.id),
            ),
        );
        if (!data) return;

        removeCalendar(calendar.id);
        closeCalendar();
        await load();
    }

    return {
        openCalendar,
        feedUrl,
        saving,
        errors,
        createCalendar,
        editCalendar,
        closeCalendar,
        publishFeed,
        revokeFeed,
        setShares,
        saveCalendar,
        removeCalendarAndItsEvents,
    };
}
