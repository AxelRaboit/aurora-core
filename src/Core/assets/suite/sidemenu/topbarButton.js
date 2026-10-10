/**
 * The classes of a control in the application's top bar: search, light and
 * dark, notifications, the menu toggle.
 *
 * A framed square, thirty-six pixels, the same on every one of them (visual
 * redesign of the suite, 10/10/2026). They were bare icons until then, each
 * drawn with its own padding by the component that owned it; framed, they read
 * as one row of controls at the edge of the frame, and the bell's badge sits on
 * a corner instead of floating beside a glyph.
 *
 * Written once here because four components in three Vue apps draw them, and
 * a copy in each is how their paddings had drifted apart.
 */
export const TOPBAR_BUTTON =
    "relative inline-grid size-9 shrink-0 place-items-center rounded-[10px] border border-line bg-surface text-secondary transition-colors hover:bg-surface-2 hover:text-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-surface";

/** The icon inside, eighteen pixels. */
export const TOPBAR_ICON = "h-[18px] w-[18px]";
