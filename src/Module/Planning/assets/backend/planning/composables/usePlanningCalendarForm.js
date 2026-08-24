import { ref } from "vue";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { useSaveFlow } from "./useSaveFlow.js";

/**
 * The open calendar, and the six writes the settings modal makes.
 *
 * Distinct from `usePlanningCalendar`, which is where the reader is and what is on
 * screen. This is the calendar as a record being edited: its name, its colour, who
 * it is shared with, and which addresses reach it without an account.
 */
export function usePlanningCalendarForm(
    props,
    { load, upsertCalendar, removeCalendar, onOpen },
) {
    const { request } = useRequest();
    const { saving, errors, submit } = useSaveFlow(request);

    const openCalendar = ref(null);

    /**
     * Every address the reader has opened, across all their calendars.
     *
     * Held here rather than on each calendar because one link can point at
     * several: a per-calendar copy would carry the same row twice with no way to
     * tell it was one link, and revoking it would have to find both.
     */
    const shareLinks = ref([...(props.shareLinks ?? [])]);

    const savingLink = ref(false);
    const linkErrors = ref({});

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
        linkErrors.value = {};
        onOpen();
    }

    function closeCalendar() {
        openCalendar.value = null;
        linkErrors.value = {};
    }

    /** Keeps the modal on the row the server just wrote, not the one it was given. */
    function adopt(calendar) {
        upsertCalendar(calendar);
        openCalendar.value = calendar;
    }

    /**
     * The links pointing at one calendar.
     *
     * Filtered here rather than in the modal so the modal takes a list and draws
     * it - and so "which links touch this calendar" is answered once.
     */
    function linksFor(calendar) {
        const id = calendar?.id ?? null;

        return null === id
            ? []
            : shareLinks.value.filter((link) =>
                  link.calendars.some((entry) => entry.id === id),
              );
    }

    async function createLink(form) {
        const calendar = openCalendar.value;

        if (!calendar?.id) {
            return;
        }

        savingLink.value = true;
        linkErrors.value = {};

        try {
            const data = await request(props.createLinkPath, {
                calendarIds: [calendar.id],
                label: form.label,
                mode: form.mode,
                // Empty means no expiry, which the server reads as null. A feed
                // wants exactly that; a guest link almost never should.
                expiresAt: form.expiresAt || null,
            });

            if (!data) return;
            if (data.errors) {
                linkErrors.value = data.errors;

                return;
            }

            shareLinks.value = [data.link, ...shareLinks.value];
        } finally {
            savingLink.value = false;
        }
    }

    /**
     * Closes a link, and keeps it in the list.
     *
     * Replaced rather than removed: the row is how somebody sees what they closed
     * and when, and dropping it would make the list look like nothing had happened.
     */
    async function revokeLink(link) {
        const data = await request(
            props.revokeLinkPathTemplate.replace("__id__", String(link.id)),
        );
        if (!data) return;

        shareLinks.value = shareLinks.value.map((existing) =>
            existing.id === data.link.id ? data.link : existing,
        );
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
        shareLinks,
        linksFor,
        savingLink,
        linkErrors,
        createLink,
        revokeLink,
        saving,
        errors,
        createCalendar,
        editCalendar,
        closeCalendar,
        setShares,
        saveCalendar,
        removeCalendarAndItsEvents,
    };
}
