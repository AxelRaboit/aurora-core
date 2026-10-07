import { ref } from "vue";
import { buildPath } from "@/shared/utils/http/buildPath.js";

export function useDocumentDetail(versionsPath, usagePath) {
    const viewingDocument = ref(null);
    const viewingDocumentVersions = ref([]);
    const viewingDocumentUsage = ref(null);

    async function viewDocument(gedDocument) {
        viewingDocument.value = gedDocument;
        viewingDocumentVersions.value = [];
        viewingDocumentUsage.value = null;

        if (versionsPath) {
            fetch(buildPath(versionsPath, { id: gedDocument.id }), {
                headers: { "X-Requested-With": "XMLHttpRequest" },
            })
                .then((response) => response.json())
                .then((data) => {
                    if (data.success)
                        viewingDocumentVersions.value = data.versions ?? [];
                })
                .catch(() => {});
        }

        if (usagePath) {
            fetch(buildPath(usagePath, { id: gedDocument.id }), {
                headers: { "X-Requested-With": "XMLHttpRequest" },
            })
                .then((response) => response.json())
                .then((data) => {
                    if (data.success)
                        viewingDocumentUsage.value = {
                            total: data.total ?? 0,
                            groups: data.groups ?? [],
                        };
                })
                .catch(() => {});
        }
    }

    function closeDetail() {
        viewingDocument.value = null;
        viewingDocumentVersions.value = [];
        viewingDocumentUsage.value = null;
    }

    return {
        viewingDocument,
        viewingDocumentVersions,
        viewingDocumentUsage,
        viewDocument,
        closeDetail,
    };
}
