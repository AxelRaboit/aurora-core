import { reactive, onMounted } from "vue";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";
import { ParameterType } from "@core/utils/enums/settings/parameterType.js";

export function useSettingsPostPicker(
    groups,
    availableGroups,
    fieldValues,
    postSearchPath,
) {
    const postPickerLabels = reactive({});
    const postPickerSearch = reactive({});
    const postPickerResults = reactive({});
    const postPickerOpen = reactive({});
    const postPickerSearchAbort = {};
    const { request } = useRequest();

    async function resolvePostLabel(key) {
        const id = fieldValues[key];
        if (!id || !postSearchPath) return;
        const json = await request(`${postSearchPath}?ids=${id}`, null, {
            method: HttpMethod.Get,
            noGuard: true,
        });
        const post = json?.results?.[0];
        if (post)
            postPickerLabels[key] = {
                id: post.id,
                title: post.title ?? `#${post.id}`,
            };
    }

    async function searchPosts(key, query) {
        if (!postSearchPath) return;
        if (postPickerSearchAbort[key]) postPickerSearchAbort[key].abort();
        if (!query.trim()) {
            postPickerResults[key] = [];
            postPickerOpen[key] = false;
            return;
        }
        postPickerSearchAbort[key] = new AbortController();
        const json = await request(
            `${postSearchPath}?q=${encodeURIComponent(query)}`,
            null,
            {
                method: HttpMethod.Get,
                signal: postPickerSearchAbort[key].signal,
                noGuard: true,
            },
        );
        if (json === null) return;
        postPickerResults[key] = json.results ?? [];
        postPickerOpen[key] = true;
    }

    function selectPost(key, post) {
        fieldValues[key] = String(post.id);
        postPickerLabels[key] = {
            id: post.id,
            title: post.title ?? `#${post.id}`,
        };
        postPickerSearch[key] = "";
        postPickerResults[key] = [];
        postPickerOpen[key] = false;
    }

    function clearPost(key) {
        fieldValues[key] = "";
        postPickerLabels[key] = null;
    }

    function onPostPickerBlur(key) {
        setTimeout(() => {
            postPickerOpen[key] = false;
        }, 150);
    }

    function onPostPickerFocus(key) {
        if (postPickerResults[key]?.length) postPickerOpen[key] = true;
    }

    onMounted(() => {
        for (const groupName of availableGroups) {
            for (const parameter of groups[groupName]) {
                if (
                    parameter.type === ParameterType.Post &&
                    fieldValues[parameter.key]
                ) {
                    resolvePostLabel(parameter.key);
                }
            }
        }
    });

    return {
        postPickerLabels,
        postPickerSearch,
        postPickerResults,
        postPickerOpen,
        resolvePostLabel,
        searchPosts,
        selectPost,
        clearPost,
        onPostPickerBlur,
        onPostPickerFocus,
    };
}
