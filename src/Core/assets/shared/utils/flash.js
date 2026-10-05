/**
 * A message that has to outlive the page that raised it.
 *
 * A toast shown just before `window.location.href = ...` is gone with the
 * page: « Livrable dupliqué » was set and never read. The server already
 * hands messages to the next page through `window.__flash__`; this is the
 * same road for a message the browser raises itself, kept in the session so
 * that it survives exactly one navigation. `flash.js` shows it on arrival.
 */
const KEY = "aurora.flash";
const TYPES = ["success", "error", "info", "warning"];

/** Queue a message for the next page. Silent when storage is unavailable: the message is a courtesy. */
export function queueFlash(type, message) {
    if (!TYPES.includes(type) || "string" !== typeof message || "" === message)
        return;

    try {
        const queued = JSON.parse(window.sessionStorage.getItem(KEY) ?? "[]");
        const list = Array.isArray(queued) ? queued : [];
        list.push({ type, message });
        window.sessionStorage.setItem(KEY, JSON.stringify(list));
    } catch {
        // Private window, blocked storage: no message, no harm.
    }
}

/** The queued messages, once: they are removed as they are read. */
export function takeFlashes() {
    try {
        const raw = window.sessionStorage.getItem(KEY);
        window.sessionStorage.removeItem(KEY);
        const list = JSON.parse(raw ?? "[]");

        return Array.isArray(list)
            ? list.filter(
                  (entry) =>
                      entry &&
                      TYPES.includes(entry.type) &&
                      "string" === typeof entry.message,
              )
            : [];
    } catch {
        return [];
    }
}
