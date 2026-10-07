import { readonly, ref } from "vue";

/**
 * Open or collapsed: one choice for all the "How it works" panels.
 *
 * Collapsing one panel collapses them all, on the open screen as on the
 * others, and the choice is kept in the browser; expanding it reopens them
 * all. The reader who knows the tool does not close twenty panels one by
 * one, the one who is discovering it keeps them open.
 *
 * The state lives at module level, so it is shared by every instance on the
 * page, and followed from one tab to another through the `storage` event.
 * Storage can be missing (private browsing): the panels then stay in
 * agreement on the page, with no memory from one visit to the next.
 */
const STORAGE_KEY = "aurora.guides.open";

function read() {
    try {
        const value = window.localStorage.getItem(STORAGE_KEY);

        return null === value ? null : "1" === value;
    } catch {
        return null;
    }
}

/** `null` as long as the reader has chosen nothing. */
const choice = ref(read());

if ("undefined" !== typeof window) {
    window.addEventListener("storage", (event) => {
        if (STORAGE_KEY === event.key) choice.value = read();
    });
}

function remember(open) {
    choice.value = open;
    try {
        window.localStorage.setItem(STORAGE_KEY, open ? "1" : "0");
    } catch {
        // Without storage, the panels stay in agreement on the page, with no memory.
    }
}

/**
 * The screens whose guide has already been shown once.
 *
 * As long as the reader has chosen nothing, a guide opens the first time its
 * screen is visited and stays folded after that: open everywhere, the guides
 * pushed every list below the fold, on a phone below the first screen (UI
 * audit of 07/10/2026). Kept per browser, like the choice.
 */
const SEEN_KEY = "aurora.guides.seen";

function readSeen() {
    try {
        const value = JSON.parse(window.localStorage.getItem(SEEN_KEY) ?? "[]");

        return Array.isArray(value) ? value : [];
    } catch {
        return [];
    }
}

function hasSeen(key) {
    return readSeen().includes(key);
}

function markSeen(key) {
    const seen = readSeen();
    if (seen.includes(key)) return;
    try {
        window.localStorage.setItem(SEEN_KEY, JSON.stringify([...seen, key]));
    } catch {
        // Without storage, every visit is a first one.
    }
}

/** For tests: forget the choice and the screens seen, like a fresh browser. */
function forget() {
    choice.value = null;
    try {
        window.localStorage.removeItem(STORAGE_KEY);
        window.localStorage.removeItem(SEEN_KEY);
    } catch {
        // Nothing to forget.
    }
}

export function useGuidePreference() {
    return { choice: readonly(choice), remember, forget, hasSeen, markSeen };
}
