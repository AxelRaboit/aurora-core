import { ref } from "vue";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";

export function useProfileLocale(localePath, initialLocale) {
    const selectedLocale = ref(initialLocale);
    const { loading: localeLoading, request } = useRequest();

    async function changeLocale() {
        if (selectedLocale.value === initialLocale) return;
        const data = await request(localePath, {
            locale: selectedLocale.value,
        });
        if (data === null) {
            selectedLocale.value = initialLocale;
            return;
        }
        window.location.reload();
    }

    return { selectedLocale, localeLoading, changeLocale };
}
