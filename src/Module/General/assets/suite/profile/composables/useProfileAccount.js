import { computed } from "vue";

const STATUS_COLOR = {
    active: "emerald",
    invited: "amber",
    disabled: "rose",
    pending_verification: "amber",
};

function formatDate(iso, locale) {
    if (!iso) return "";
    try {
        return new Intl.DateTimeFormat(locale || "fr", {
            dateStyle: "long",
        }).format(new Date(iso));
    } catch (_) {
        return iso;
    }
}

export function useProfileAccount(accountInfo, locale) {
    const info = computed(() => accountInfo ?? {});
    const formattedCreatedAt = computed(() =>
        formatDate(info.value.createdAt, locale),
    );

    function statusColor(status) {
        return STATUS_COLOR[status] ?? "slate";
    }

    return {
        info,
        formattedCreatedAt,
        statusColor,
    };
}
