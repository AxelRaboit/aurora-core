# Vue Backend - Vue / JS (Interface Admin)

- [convention_mobile_card_layout.md](convention_mobile_card_layout.md) - `sm:hidden` cards + `hidden sm:block` table ; footer actions ; bouton full-width mobile
- [pattern_admin_list_toolbar.md](pattern_admin_list_toolbar.md) - `AppListToolbar` partagé : slot default (search) + slot `#actions` (boutons) ; grid responsive 1fr/auto
- [convention_vue_form_validation.md](convention_vue_form_validation.md) - `useForm` + `required()` + `:error` ; `useI18n()` dans le composable ; reset loading sur tous les chemins
- [convention_modal_and_confirmation.md](convention_modal_and_confirmation.md) - `AppModal` (`:show + v-on:close`) + confirmation via modale, jamais `confirm()` natif
- [convention_file_picker_button.md](convention_file_picker_button.md) - `AppFilePickerButton` encapsule `<input type="file" hidden>` + `<AppButton>` trigger
- [convention_color_picker.md](convention_color_picker.md) - 3 composants couleur : `AppColorSwatch` (nu), `AppColorField` (form), `AppColorPicker` (preset grid)
- [pitfall_nested_drag_drop_clone.md](pitfall_nested_drag_drop_clone.md) - node récursif VueDraggable : computed bidirectionnel sur `props.node.children`, jamais `ref([...])`. Bug latent dans `TermNode.vue`
- [pattern_folder_sidebar.md](pattern_folder_sidebar.md) - sidebar arborescence Media-style sur une page liste : 5 composables (Navigation/SidebarTree/SidebarFolders/DragDrop/BulkMove) + backend `move`/`bulkMove`/`countGroupedByFolders`/`rootOnly`/`withCounts` ; garde la page `/folders` admin intacte
- [decision_suite_visual_redesign.md](decision_suite_visual_redesign.md) - refonte du 09-10/10/2026 : cadre aligné (h-12), menu sans icônes, fil d'Ariane dans le contenu, `AppStatTile` + `AppSectionCard`, une palette de statuts (`statusStyles.js`)
- [pitfall_m0_cancels_stack_spacing.md](pitfall_m0_cancels_stack_spacing.md) - `m-0` sur un enfant d'`aurora-stack` / `space-y-*` annule l'espace sous lui (Tailwind 4) ; regrouper plutôt.
