import {
    ArrowRight,
    Award,
    Ban,
    Check,
    Clock,
    Euro,
    Eye,
    Flag,
    Lightbulb,
    Lock,
    Rocket,
    Settings,
    TrendingUp,
    TriangleAlert,
    Users,
    Zap,
} from "lucide-vue-next";

/**
 * The sixteen icons a slide may name, and not the whole of Lucide.
 *
 * **Declared like everything else in this module.** The library holds well over
 * a thousand, and importing by name at render would mean shipping all of them
 * to every reader of a public share link for the two a deck actually uses.
 * Sixteen cover what a deck argues about: time, money, people, risk, speed, a
 * rule, a goal.
 *
 * **In its own file so two readers can share it.** The frame needs the
 * components to draw; `cells.js` needs only the names, to tell an icon from a
 * piece of text somebody typed in the same position. A list that lived in the
 * component would have to be duplicated to answer the second question, and the
 * day one copy gained a name the other would start dropping words.
 */
export const ICONS = {
    check: Check,
    arrow: ArrowRight,
    clock: Clock,
    euro: Euro,
    users: Users,
    warning: TriangleAlert,
    ban: Ban,
    lock: Lock,
    eye: Eye,
    rocket: Rocket,
    zap: Zap,
    trend: TrendingUp,
    idea: Lightbulb,
    award: Award,
    flag: Flag,
    settings: Settings,
};

/** The names alone, for whoever has to recognise one without drawing it. */
export const ICON_NAMES = Object.keys(ICONS);

/** The component for a name, or null. A name nothing matches draws nothing. */
export function iconFor(name) {
    return ICONS[name] ?? null;
}
