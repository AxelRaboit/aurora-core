import { ref, computed } from "vue";
import { useI18n } from "vue-i18n";

export function usePermissionsFilter(data) {
    const { t } = useI18n();
    const searchInput = ref("");

    function matches(haystack, needle) {
        return haystack.toLowerCase().includes(needle);
    }

    // Client-side filter - the registry is small and fully loaded, no need to
    // round-trip the server.
    //
    // A module that registers no permission is dropped, because a heading
    // followed by "no permission defined for this module" says nothing an
    // absent heading would not say. The search already did exactly that - it
    // keeps a module only when some permission survives the filter - so the
    // unfiltered list was the odd one out: the section showed until you typed
    // a single character, then vanished.
    const filteredModules = computed(() => {
        const query = searchInput.value.trim().toLowerCase();
        const modules = (data.value?.modules ?? []).filter(
            (moduleEntry) => moduleEntry.permissions.length,
        );
        if (!query) return modules;
        return modules
            .map((moduleEntry) => {
                const moduleLabel = t(`backend.modules.${moduleEntry.id}`);
                const moduleHit =
                    matches(moduleEntry.id, query) ||
                    matches(moduleLabel, query);
                const matchingPerms = moduleEntry.permissions.filter(
                    (permission) => {
                        if (moduleHit) return true;
                        const label = t(
                            `backend.permissions.names.${permission.name}`,
                        );
                        return (
                            matches(permission.name, query) ||
                            matches(label, query)
                        );
                    },
                );
                return matchingPerms.length
                    ? { ...moduleEntry, permissions: matchingPerms }
                    : null;
            })
            .filter(Boolean);
    });

    return { searchInput, filteredModules };
}
