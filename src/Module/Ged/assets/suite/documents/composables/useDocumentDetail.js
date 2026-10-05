import { ref } from "vue";
import { buildPath } from "@/shared/utils/http/buildPath.js";

export function useDocumentDetail(versionsPath, usagePath) {
    const viewingDoc = ref(null);
    const viewingDocVersions = ref([]);
    const viewingDocUsage = ref(null);

    async function viewDoc(doc) {
        viewingDoc.value = doc;
        viewingDocVersions.value = [];
        viewingDocUsage.value = null;

        if (versionsPath) {
            fetch(buildPath(versionsPath, { id: doc.id }), {
                headers: { "X-Requested-With": "XMLHttpRequest" },
            })
                .then((r) => r.json())
                .then((data) => {
                    if (data.success)
                        viewingDocVersions.value = data.versions ?? [];
                })
                .catch(() => {});
        }

        if (usagePath) {
            fetch(buildPath(usagePath, { id: doc.id }), {
                headers: { "X-Requested-With": "XMLHttpRequest" },
            })
                .then((r) => r.json())
                .then((data) => {
                    if (data.success)
                        viewingDocUsage.value = {
                            total: data.total ?? 0,
                            groups: data.groups ?? [],
                        };
                })
                .catch(() => {});
        }
    }

    function closeDetail() {
        viewingDoc.value = null;
        viewingDocVersions.value = [];
        viewingDocUsage.value = null;
    }

    return {
        viewingDoc,
        viewingDocVersions,
        viewingDocUsage,
        viewDoc,
        closeDetail,
    };
}
