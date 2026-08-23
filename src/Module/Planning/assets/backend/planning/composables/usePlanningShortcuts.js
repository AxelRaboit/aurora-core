import { useKeyboardShortcut } from "@/shared/composables/useKeyboardShortcut.js";

/**
 * The calendar's keyboard shortcuts, taken from Google's.
 *
 * `d`/`w`/`m` for the views, `t` for today, `n`/`p` for next and previous, `c` to
 * create. Somebody who already uses a calendar every day arrives knowing these,
 * which is the whole argument for copying them rather than inventing better ones.
 *
 * `j`/`k` are bound alongside `n`/`p` because Google binds both and the muscle
 * memory splits by which one you learned. `r` for a reminder is ours - Google has
 * no reminders to bind it to.
 *
 * Nothing fires while a modal is open. Escape closes those, and a stray `d` while
 * somebody reads an event would move the grid out from under them.
 */
export function usePlanningShortcuts({
    isBusy,
    setView,
    go,
    goToToday,
    createEvent,
    createReminder,
}) {
    function unless(action) {
        return () => {
            if (isBusy()) {
                return;
            }

            action();
        };
    }

    useKeyboardShortcut(
        { key: "d" },
        unless(() => setView("day")),
    );
    useKeyboardShortcut(
        { key: "w" },
        unless(() => setView("week")),
    );
    useKeyboardShortcut(
        { key: "m" },
        unless(() => setView("month")),
    );

    useKeyboardShortcut({ key: "t" }, unless(goToToday));

    useKeyboardShortcut(
        { key: "n" },
        unless(() => go(1)),
    );
    useKeyboardShortcut(
        { key: "j" },
        unless(() => go(1)),
    );
    useKeyboardShortcut(
        { key: "p" },
        unless(() => go(-1)),
    );
    useKeyboardShortcut(
        { key: "k" },
        unless(() => go(-1)),
    );

    useKeyboardShortcut({ key: "c" }, unless(createEvent));
    useKeyboardShortcut({ key: "r" }, unless(createReminder));
}
