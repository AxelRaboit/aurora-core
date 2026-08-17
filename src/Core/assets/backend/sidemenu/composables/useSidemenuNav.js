import { ref, computed, onMounted, nextTick } from "vue";
import { useI18n } from "vue-i18n";
import { usePersistedExpanded } from "@/shared/composables/usePersistedExpanded.js";
import { useSidemenuSectionTheme } from "./useSidemenuSectionTheme.js";
import {
    LayoutDashboard,
    FileText,
    Layers,
    Image,
    Images,
    Menu,
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

const ICON_MAP = {
    "layout-dashboard": LayoutDashboard,
    "file-text": FileText,
    layers: Layers,
    image: Image,
    images: Images,
    menu: Menu,
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

export function useSidemenuNav(
    navSections,
    activeRoute,
    sectionAliases = {},
    itemAliases = {},
    sectionColorOverrides = {},
) {
    const { t } = useI18n();
    const { itemClasses: themeItemClasses, iconClasses: themeIconClasses } =
        useSidemenuSectionTheme(sectionColorOverrides);

    const {
        isExpanded: isGroupExpanded,
        toggle: toggleGroup,
        getRaw: getGroupRaw,
    } = usePersistedExpanded("aurora-sidemenu-groups");
    const { isExpanded: isSectionExpandedById, toggle: toggleSectionById } =
        usePersistedExpanded("aurora-sidemenu-sections");

    function isSectionExpanded(section) {
        return isSectionExpandedById(section.id);
    }
    /**
     * The account block at the foot of the menu folds like a nav section, and
     * shares their store: one localStorage key, one rule — expanded unless
     * someone said otherwise. A second mechanism for the same gesture would be
     * a second thing to keep in agreement.
     *
     * Not one of `navSections`, because it is not navigation: its rows are a
     * theme toggle, a mail catcher and a logout form, and nothing generates
     * them.
     */
    const ACCOUNT_SECTION = "account";

    function isAccountExpanded() {
        return isSectionExpandedById(ACCOUNT_SECTION);
    }

    function toggleAccount() {
        toggleSectionById(ACCOUNT_SECTION);
    }

    function toggleSection(section) {
        toggleSectionById(section.id);
    }

    const dashboardPath = computed(
        () => navSections?.[0]?.items?.[0]?.path ?? "/admin",
    );

    function buildItem(item) {
        return {
            route: item.route,
            path: item.path,
            label: itemAliases[item.route]?.trim() || t(item.labelKey),
            description: item.descriptionKey ? t(item.descriptionKey) : "",
            icon: ICON_MAP[item.icon] ?? FileText,
            activeColor: item.activeColor ?? "accent",
            children: (item.children ?? []).map(buildItem),
        };
    }

    const groupedSections = computed(() =>
        navSections.map((section) => ({
            id: section.id,
            label:
                sectionAliases[section.id]?.trim() ||
                t(`backend.nav.sections.${section.id}`),
            items: section.items.map(buildItem),
        })),
    );

    const navItems = computed(() =>
        groupedSections.value.flatMap((s) =>
            s.items.flatMap((i) => [i, ...(i.children ?? [])]),
        ),
    );

    const navFilter = ref("");

    const displayedSections = computed(() => {
        const q = navFilter.value.trim().toLowerCase();
        if (!q) return groupedSections.value;
        const results = [];
        for (const section of groupedSections.value) {
            const matchingItems = [];
            for (const item of section.items) {
                if (item.label.toLowerCase().includes(q)) {
                    matchingItems.push(item);
                } else if (item.children?.length) {
                    const matchingChildren = item.children.filter((c) =>
                        c.label.toLowerCase().includes(q),
                    );
                    matchingItems.push(...matchingChildren);
                }
            }
            if (matchingItems.length)
                results.push({ ...section, items: matchingItems });
        }
        return results;
    });

    function isActive(route) {
        return activeRoute?.startsWith(route);
    }

    function isActiveExact(route) {
        return activeRoute === route;
    }

    function itemIsActive(item) {
        return (
            isActive(item.route) ||
            (item.children?.some((child) => isActive(child.route)) ?? false)
        );
    }

    /**
     * Wrapper classes for a nav item. Delegates to the section theme
     * registry so the active/hover hue inherits the section colour
     * (e.g. a GED item lights up lime, a configuration item fuchsia).
     * Falls back to the accent palette when the sectionId isn't known.
     */
    function itemClasses(item, sectionId = null) {
        return themeItemClasses(sectionId, {
            isActive: isActive(item.route),
            inTree: itemIsActive(item) && !isActive(item.route),
        });
    }

    function iconClasses(item, sectionId = null) {
        return themeIconClasses(sectionId, {
            isActive: isActive(item.route) || itemIsActive(item),
        });
    }

    onMounted(() => {
        groupedSections.value.forEach((section) => {
            section.items.forEach((item) => {
                if (
                    item.children?.length &&
                    getGroupRaw(item.route) === undefined &&
                    item.children.some((c) => isActive(c.route))
                ) {
                    toggleGroup(item.route);
                }
            });
        });
    });

    onMounted(() =>
        nextTick(() => {
            const active = document.querySelector(
                ".sidemenu-nav [data-sidemenu-active='true']",
            );
            active?.scrollIntoView({ block: "nearest", behavior: "instant" });
        }),
    );

    return {
        dashboardPath,
        groupedSections,
        navItems,
        navFilter,
        displayedSections,
        isGroupExpanded,
        toggleGroup,
        isSectionExpanded,
        toggleSection,
        isAccountExpanded,
        toggleAccount,
        isActive,
        isActiveExact,
        itemIsActive,
        itemClasses,
        iconClasses,
    };
}
