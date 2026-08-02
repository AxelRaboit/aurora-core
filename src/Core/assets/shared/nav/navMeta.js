/**
 * Shared nav metadata: kebab-icon-name → Lucide component, and module-id →
 * theme color. Mirrors the sidemenu's icon map + section palette so other
 * surfaces (e.g. the modules dashboard) render icons and colours the same way.
 */
import {
    LayoutDashboard,
    FileText,
    Layers,
    Image,
    Images,
    Menu,
    Tag,
    Tags as TagsIcon,
    Users as UsersIcon,
    Building2,
    TrendingUp,
    Shield,
    Settings,
    Palette,
    MessageSquare,
    ClipboardList,
    Package,
    ShoppingBag,
    Map as MapIcon,
    Receipt,
    ScanLine,
    Briefcase,
    ShieldCheck,
    Camera,
    ShoppingCart,
    Gauge,
    FolderKanban,
    FolderOpen,
    FolderTree,
    Folder,
    CalendarDays,
    KanbanSquare,
    KeyRound,
    Lock,
    Flame,
    StickyNote,
    Wallet,
    PieChart,
    Target,
    Repeat,
    Sparkles,
    Scale,
    Globe2,
    BarChart3,
    Upload,
    ScrollText,
    ClipboardCheck,
} from "lucide-vue-next";

export const ICON_MAP = {
    "layout-dashboard": LayoutDashboard,
    "file-text": FileText,
    layers: Layers,
    image: Image,
    images: Images,
    menu: Menu,
    tag: Tag,
    tags: TagsIcon,
    users: UsersIcon,
    "building-2": Building2,
    "trending-up": TrendingUp,
    shield: Shield,
    settings: Settings,
    palette: Palette,
    "message-square": MessageSquare,
    "clipboard-list": ClipboardList,
    package: Package,
    "shopping-bag": ShoppingBag,
    map: MapIcon,
    receipt: Receipt,
    "scan-line": ScanLine,
    briefcase: Briefcase,
    "shield-check": ShieldCheck,
    camera: Camera,
    "shopping-cart": ShoppingCart,
    gauge: Gauge,
    "folder-kanban": FolderKanban,
    "folder-open": FolderOpen,
    "folder-tree": FolderTree,
    folder: Folder,
    "calendar-days": CalendarDays,
    "kanban-square": KanbanSquare,
    "key-round": KeyRound,
    vault: Lock,
    flame: Flame,
    "scroll-text": ScrollText,
    "clipboard-check": ClipboardCheck,
    "sticky-note": StickyNote,
    wallet: Wallet,
    "pie-chart": PieChart,
    target: Target,
    repeat: Repeat,
    sparkles: Sparkles,
    scale: Scale,
    "globe-2": Globe2,
    "bar-chart-3": BarChart3,
    upload: Upload,
};

/** Resolve a kebab icon name to its Lucide component (FileText fallback). */
export function resolveNavIcon(name) {
    return ICON_MAP[name] ?? FileText;
}

/**
 * Module id → Tailwind colour family, mirroring the sidemenu section palette
 * (useSidemenuSectionTheme). Used to tint a module's icons consistently.
 */
export const MODULE_COLOR = {
    general: "slate",
    platform: "indigo",
    configuration: "zinc",
    ged: "lime",
    media: "pink",
    dev: "orange",
};

/** Full Tailwind text class per colour family (safelist-friendly literals). */
const ICON_TEXT_CLASS = {
    slate: "text-slate-400",
    indigo: "text-indigo-400",
    stone: "text-stone-400",
    zinc: "text-zinc-400",
    yellow: "text-yellow-400",
    emerald: "text-emerald-400",
    rose: "text-rose-400",
    lime: "text-lime-400",
    pink: "text-pink-400",
    cyan: "text-cyan-400",
    sky: "text-sky-400",
    teal: "text-teal-400",
    purple: "text-purple-400",
    amber: "text-amber-400",
    fuchsia: "text-fuchsia-400",
    blue: "text-blue-400",
    green: "text-green-400",
    orange: "text-orange-400",
    violet: "text-violet-400",
    accent: "text-accent",
};

/** Module id (e.g. 'ecommerce') → its icon text colour class. */
export function moduleIconColorClass(moduleId) {
    return ICON_TEXT_CLASS[MODULE_COLOR[moduleId] ?? "accent"] ?? "text-accent";
}

/**
 * Tinted-background + left-accent classes per colour family, mirroring the
 * sidemenu section header (useSidemenuSectionTheme headerBg + headerBorder).
 * Full literal strings so Tailwind keeps them (no dynamic construction).
 * Pair with a `border-l-*` width class on the element.
 */
const HEADER_CLASS = {
    slate: "bg-slate-500/10 border-l-slate-500",
    indigo: "bg-indigo-500/10 border-l-indigo-500",
    stone: "bg-stone-500/10 border-l-stone-500",
    zinc: "bg-zinc-500/10 border-l-zinc-500",
    yellow: "bg-yellow-500/10 border-l-yellow-500",
    emerald: "bg-emerald-500/10 border-l-emerald-500",
    rose: "bg-rose-500/10 border-l-rose-500",
    lime: "bg-lime-500/10 border-l-lime-500",
    pink: "bg-pink-500/10 border-l-pink-500",
    cyan: "bg-cyan-500/10 border-l-cyan-500",
    sky: "bg-sky-500/10 border-l-sky-500",
    teal: "bg-teal-500/10 border-l-teal-500",
    purple: "bg-purple-500/10 border-l-purple-500",
    amber: "bg-amber-500/10 border-l-amber-500",
    fuchsia: "bg-fuchsia-500/10 border-l-fuchsia-500",
    blue: "bg-blue-500/10 border-l-blue-500",
    green: "bg-green-500/10 border-l-green-500",
    orange: "bg-orange-500/10 border-l-orange-500",
    violet: "bg-violet-500/10 border-l-violet-500",
    accent: "bg-accent/10 border-l-accent",
};

/** Module id → its tinted header classes (background + left accent colour). */
export function moduleHeaderClass(moduleId) {
    return (
        HEADER_CLASS[MODULE_COLOR[moduleId] ?? "accent"] ?? HEADER_CLASS.accent
    );
}

/** Derive the module id from a toggle key: 'modules_ecommerce_backend' → 'ecommerce'. */
export function moduleIdFromToggleKey(key) {
    return String(key)
        .replace(/^modules_/, "")
        .replace(/_(backend|frontend)$/, "");
}
