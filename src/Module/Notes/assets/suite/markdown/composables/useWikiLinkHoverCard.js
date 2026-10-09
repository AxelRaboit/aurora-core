import { onBeforeUnmount, ref, watch } from "vue";

/**
 * The start of a linked note, shown when the pointer rests on its link
 * (09/10/2026), as Obsidian and Notion do: enough to know whether the link is
 * the one wanted, without leaving the note being read.
 *
 * A short wait before it opens, so that crossing a paragraph full of links
 * does not flash a card on each one; a short wait before it closes, so the
 * pointer can travel onto the card itself.
 *
 * @param {import('vue').Ref<HTMLElement|null>} rootRef  the rendered note
 * @param {(title: string, heading: string) => Promise<string|null>} loadHtml
 */
export function useWikiLinkHoverCard(rootRef, loadHtml) {
    const OPEN_DELAY_MS = 400;
    const CLOSE_DELAY_MS = 200;

    const card = ref(null);
    let openTimer = null;
    let closeTimer = null;
    let shownFor = null;

    function clearTimers() {
        clearTimeout(openTimer);
        clearTimeout(closeTimer);
        openTimer = null;
        closeTimer = null;
    }

    function onOver(event) {
        const link = event.target.closest?.("a.wiki-link");
        if (!link || link.closest(".md-embed")) return;
        if (link === shownFor) {
            clearTimeout(closeTimer);

            return;
        }

        clearTimers();
        openTimer = setTimeout(() => void open(link), OPEN_DELAY_MS);
    }

    function onOut(event) {
        const link = event.target.closest?.("a.wiki-link");
        if (!link) return;
        clearTimeout(openTimer);
        scheduleClose();
    }

    function scheduleClose() {
        clearTimeout(closeTimer);
        closeTimer = setTimeout(close, CLOSE_DELAY_MS);
    }

    async function open(link) {
        const title = link.dataset.noteTitle ?? "";
        const heading = link.dataset.heading ?? "";
        const html = await loadHtml(title, heading);
        if (null === html || undefined === html) return;

        const box = link.getBoundingClientRect();
        const below = box.bottom + 8;
        const fitsBelow = below + 260 < window.innerHeight;
        shownFor = link;
        card.value = {
            title: title || link.textContent,
            html,
            left: Math.max(8, Math.min(box.left, window.innerWidth - 400)),
            top: fitsBelow ? below : null,
            bottom: fitsBelow ? null : window.innerHeight - box.top + 8,
        };
    }

    function close() {
        clearTimers();
        shownFor = null;
        card.value = null;
    }

    /** The card itself keeps the pointer: it stays while it is read. */
    function onCardEnter() {
        clearTimeout(closeTimer);
    }

    watch(
        rootRef,
        (root, previous) => {
            previous?.removeEventListener("mouseover", onOver);
            previous?.removeEventListener("mouseout", onOut);
            root?.addEventListener("mouseover", onOver);
            root?.addEventListener("mouseout", onOut);
        },
        { immediate: true },
    );

    onBeforeUnmount(() => {
        clearTimers();
        rootRef.value?.removeEventListener("mouseover", onOver);
        rootRef.value?.removeEventListener("mouseout", onOut);
    });

    return { card, close, onCardEnter, onCardLeave: scheduleClose };
}

/** The beginning of a note for a card: a dozen lines, not the whole text. */
export function noteExcerpt(markdown, maxLines = 12, maxCharacters = 900) {
    const lines = String(markdown ?? "").split("\n");
    let excerpt = lines.slice(0, maxLines).join("\n");
    if (excerpt.length > maxCharacters)
        excerpt = `${excerpt.slice(0, maxCharacters)}…`;

    return excerpt;
}
