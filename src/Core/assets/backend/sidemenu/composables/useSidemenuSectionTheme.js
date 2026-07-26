import { isRef } from "vue";

/**
 * Per-section visual theme for the sidemenu — colour-codes each module
 * group with a subtle tinted background, a 3 px left accent strip, and
 * a matching tinted label so scanning the nav feels structured even
 * with 20+ entries. The same hue is then reused for the section's
 * active item highlight (bg + text + icon) so a glance at the nav
 * tells you both where you are AND which family the page belongs to.
 *
 * Mirrors the approach used on the Budget page (see
 * `useBudgetSectionTheme`) so the same eye habits transfer.
 *
 * The tints stay light (10 % alpha background + 400 text + 500 border)
 * — the sidemenu is a neutral surface and we don't want the colour
 * pulling focus away from the active item. Tailwind JIT picks up the
 * full class strings present in this map; no safelist needed.
 *
 * Section IDs are the same `NavSection` ids the PHP modules emit. Any
 * id not in the map falls back to the accent palette (Aurora default).
 */
const SECTION_THEMES = {
    general: makeTheme("sky"),
    platform: makeTheme("indigo"),
    configuration: makeTheme("fuchsia"),
    editorial: makeTheme("rose"),
    ged: makeTheme("lime"),
    dev: makeTheme("orange"),
};

const FALLBACK_THEME = makeTheme("accent");

/**
 * Builds a full theme bundle for a Tailwind colour name. Returns the
 * exact Tailwind class strings the JIT compiler needs to see at build
 * time — keep all references intact (no dynamic concat with the
 * colour name) so PurgeCSS doesn't drop them.
 */
function makeTheme(c) {
    switch (c) {
        case "slate":
            return {
                headerBg: "bg-slate-500/10",
                headerBorder: "border-l-slate-500",
                headerText: "text-slate-300",
                activeBg: "bg-slate-600/15",
                activeText: "text-slate-300",
                hoverBg: "hover:bg-slate-600/10",
                hoverText: "hover:text-slate-300",
                iconActive: "text-slate-300",
                iconHover: "group-hover:text-slate-300",
            };
        case "indigo":
            return {
                headerBg: "bg-indigo-500/10",
                headerBorder: "border-l-indigo-500",
                headerText: "text-indigo-400",
                activeBg: "bg-indigo-600/15",
                activeText: "text-indigo-400",
                hoverBg: "hover:bg-indigo-600/10",
                hoverText: "hover:text-indigo-400",
                iconActive: "text-indigo-400",
                iconHover: "group-hover:text-indigo-400",
            };
        case "stone":
            return {
                headerBg: "bg-stone-500/10",
                headerBorder: "border-l-stone-500",
                headerText: "text-stone-300",
                activeBg: "bg-stone-600/15",
                activeText: "text-stone-300",
                hoverBg: "hover:bg-stone-600/10",
                hoverText: "hover:text-stone-300",
                iconActive: "text-stone-300",
                iconHover: "group-hover:text-stone-300",
            };
        case "zinc":
            return {
                headerBg: "bg-zinc-500/10",
                headerBorder: "border-l-zinc-500",
                headerText: "text-zinc-300",
                activeBg: "bg-zinc-600/15",
                activeText: "text-zinc-300",
                hoverBg: "hover:bg-zinc-600/10",
                hoverText: "hover:text-zinc-300",
                iconActive: "text-zinc-300",
                iconHover: "group-hover:text-zinc-300",
            };
        case "yellow":
            return {
                headerBg: "bg-yellow-500/10",
                headerBorder: "border-l-yellow-500",
                headerText: "text-yellow-400",
                activeBg: "bg-yellow-600/15",
                activeText: "text-yellow-400",
                hoverBg: "hover:bg-yellow-600/10",
                hoverText: "hover:text-yellow-400",
                iconActive: "text-yellow-400",
                iconHover: "group-hover:text-yellow-400",
            };
        case "emerald":
            return {
                headerBg: "bg-emerald-500/10",
                headerBorder: "border-l-emerald-500",
                headerText: "text-emerald-400",
                activeBg: "bg-emerald-600/15",
                activeText: "text-emerald-400",
                hoverBg: "hover:bg-emerald-600/10",
                hoverText: "hover:text-emerald-400",
                iconActive: "text-emerald-400",
                iconHover: "group-hover:text-emerald-400",
            };
        case "rose":
            return {
                headerBg: "bg-rose-500/10",
                headerBorder: "border-l-rose-500",
                headerText: "text-rose-400",
                activeBg: "bg-rose-600/15",
                activeText: "text-rose-400",
                hoverBg: "hover:bg-rose-600/10",
                hoverText: "hover:text-rose-400",
                iconActive: "text-rose-400",
                iconHover: "group-hover:text-rose-400",
            };
        case "lime":
            return {
                headerBg: "bg-lime-500/10",
                headerBorder: "border-l-lime-500",
                headerText: "text-lime-400",
                activeBg: "bg-lime-600/15",
                activeText: "text-lime-400",
                hoverBg: "hover:bg-lime-600/10",
                hoverText: "hover:text-lime-400",
                iconActive: "text-lime-400",
                iconHover: "group-hover:text-lime-400",
            };
        case "cyan":
            return {
                headerBg: "bg-cyan-500/10",
                headerBorder: "border-l-cyan-500",
                headerText: "text-cyan-400",
                activeBg: "bg-cyan-600/15",
                activeText: "text-cyan-400",
                hoverBg: "hover:bg-cyan-600/10",
                hoverText: "hover:text-cyan-400",
                iconActive: "text-cyan-400",
                iconHover: "group-hover:text-cyan-400",
            };
        case "sky":
            return {
                headerBg: "bg-sky-500/10",
                headerBorder: "border-l-sky-500",
                headerText: "text-sky-400",
                activeBg: "bg-sky-600/15",
                activeText: "text-sky-400",
                hoverBg: "hover:bg-sky-600/10",
                hoverText: "hover:text-sky-400",
                iconActive: "text-sky-400",
                iconHover: "group-hover:text-sky-400",
            };
        case "teal":
            return {
                headerBg: "bg-teal-500/10",
                headerBorder: "border-l-teal-500",
                headerText: "text-teal-400",
                activeBg: "bg-teal-600/15",
                activeText: "text-teal-400",
                hoverBg: "hover:bg-teal-600/10",
                hoverText: "hover:text-teal-400",
                iconActive: "text-teal-400",
                iconHover: "group-hover:text-teal-400",
            };
        case "purple":
            return {
                headerBg: "bg-purple-500/10",
                headerBorder: "border-l-purple-500",
                headerText: "text-purple-400",
                activeBg: "bg-purple-600/15",
                activeText: "text-purple-400",
                hoverBg: "hover:bg-purple-600/10",
                hoverText: "hover:text-purple-400",
                iconActive: "text-purple-400",
                iconHover: "group-hover:text-purple-400",
            };
        case "amber":
            return {
                headerBg: "bg-amber-500/10",
                headerBorder: "border-l-amber-500",
                headerText: "text-amber-400",
                activeBg: "bg-amber-600/15",
                activeText: "text-amber-400",
                hoverBg: "hover:bg-amber-600/10",
                hoverText: "hover:text-amber-400",
                iconActive: "text-amber-400",
                iconHover: "group-hover:text-amber-400",
            };
        case "fuchsia":
            return {
                headerBg: "bg-fuchsia-500/10",
                headerBorder: "border-l-fuchsia-500",
                headerText: "text-fuchsia-400",
                activeBg: "bg-fuchsia-600/15",
                activeText: "text-fuchsia-400",
                hoverBg: "hover:bg-fuchsia-600/10",
                hoverText: "hover:text-fuchsia-400",
                iconActive: "text-fuchsia-400",
                iconHover: "group-hover:text-fuchsia-400",
            };
        case "pink":
            return {
                headerBg: "bg-pink-500/10",
                headerBorder: "border-l-pink-500",
                headerText: "text-pink-400",
                activeBg: "bg-pink-600/15",
                activeText: "text-pink-400",
                hoverBg: "hover:bg-pink-600/10",
                hoverText: "hover:text-pink-400",
                iconActive: "text-pink-400",
                iconHover: "group-hover:text-pink-400",
            };
        case "blue":
            return {
                headerBg: "bg-blue-500/10",
                headerBorder: "border-l-blue-500",
                headerText: "text-blue-400",
                activeBg: "bg-blue-600/15",
                activeText: "text-blue-400",
                hoverBg: "hover:bg-blue-600/10",
                hoverText: "hover:text-blue-400",
                iconActive: "text-blue-400",
                iconHover: "group-hover:text-blue-400",
            };
        case "green":
            return {
                headerBg: "bg-green-500/10",
                headerBorder: "border-l-green-500",
                headerText: "text-green-400",
                activeBg: "bg-green-600/15",
                activeText: "text-green-400",
                hoverBg: "hover:bg-green-600/10",
                hoverText: "hover:text-green-400",
                iconActive: "text-green-400",
                iconHover: "group-hover:text-green-400",
            };
        case "red":
            return {
                headerBg: "bg-red-500/10",
                headerBorder: "border-l-red-500",
                headerText: "text-red-400",
                activeBg: "bg-red-600/15",
                activeText: "text-red-400",
                hoverBg: "hover:bg-red-600/10",
                hoverText: "hover:text-red-400",
                iconActive: "text-red-400",
                iconHover: "group-hover:text-red-400",
            };
        case "orange":
            return {
                headerBg: "bg-orange-500/10",
                headerBorder: "border-l-orange-500",
                headerText: "text-orange-400",
                activeBg: "bg-orange-600/15",
                activeText: "text-orange-400",
                hoverBg: "hover:bg-orange-600/10",
                hoverText: "hover:text-orange-400",
                iconActive: "text-orange-400",
                iconHover: "group-hover:text-orange-400",
            };
        case "violet":
            return {
                headerBg: "bg-violet-500/10",
                headerBorder: "border-l-violet-500",
                headerText: "text-violet-400",
                activeBg: "bg-violet-600/15",
                activeText: "text-violet-400",
                hoverBg: "hover:bg-violet-600/10",
                hoverText: "hover:text-violet-400",
                iconActive: "text-violet-400",
                iconHover: "group-hover:text-violet-400",
            };
        case "accent":
        default:
            return {
                headerBg: "bg-surface-2/40",
                headerBorder: "border-l-line",
                headerText: "text-muted",
                activeBg: "bg-accent-600/15",
                activeText: "text-accent-400",
                hoverBg: "hover:bg-accent-600/10",
                hoverText: "hover:text-accent-400",
                iconActive: "text-accent-400",
                iconHover: "group-hover:text-accent-400",
            };
    }
}

/**
 * @param {Record<string, string> | import('vue').Ref<Record<string, string>>} [overrides]
 *   Map of sectionId → palette name (`emerald`, `rose`, …). Overrides win
 *   over the built-in `SECTION_THEMES` so an admin / per-user customisation
 *   can swap the default palette without forking this file. Accepts a Vue
 *   ref so consumers can update the map live (e.g. the profile settings
 *   page broadcasts changes and the sidemenu picks them up without reload).
 */
export function useSidemenuSectionTheme(overrides = {}) {
    function readOverrides() {
        const value = isRef(overrides) ? overrides.value : overrides;
        if (!value || Array.isArray(value) || typeof value !== "object") {
            return {};
        }

        return value;
    }

    function resolve(sectionId) {
        const overrideColor = readOverrides()[sectionId];
        if (typeof overrideColor === "string" && overrideColor.length > 0) {
            return makeTheme(overrideColor);
        }

        return SECTION_THEMES[sectionId] ?? FALLBACK_THEME;
    }

    function headerClasses(sectionId) {
        const theme = resolve(sectionId);
        return `${theme.headerBg} border-l-[3px] ${theme.headerBorder} rounded-r-md`;
    }

    function labelClasses(sectionId) {
        return resolve(sectionId).headerText;
    }

    /**
     * Tailwind classes for a nav item's wrapper:
     * - active (current route)     → tinted background + tinted text
     * - in-tree (parent of active) → tinted text only, hover tint bg
     * - idle                       → muted text, hover slides into tint
     */
    function itemClasses(sectionId, { isActive, inTree }) {
        const theme = resolve(sectionId);
        if (isActive) {
            return `${theme.activeBg} ${theme.activeText}`;
        }
        if (inTree) {
            return `${theme.activeText} hover:bg-surface-2`;
        }

        return `text-secondary ${theme.hoverText} ${theme.hoverBg}`;
    }

    /**
     * Tailwind classes for the icon nested inside a nav item — picks up
     * the section colour when its container is active/in-tree, slides
     * into it on hover otherwise.
     */
    function iconClasses(sectionId, { isActive }) {
        const theme = resolve(sectionId);
        if (isActive) {
            return theme.iconActive;
        }

        return `text-muted ${theme.iconHover} transition-colors`;
    }

    return { headerClasses, labelClasses, itemClasses, iconClasses };
}
