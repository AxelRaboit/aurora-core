# Vue Frontend - Vue / Twig (Site public)

- [convention_frontend_rendering.md](convention_frontend_rendering.md) - **règle de base** : tous les templates frontend sont des passerelles Vue, SEO via head meta Twig
- [convention_frontend_search.md](convention_frontend_search.md) - client-side (`v-model` + computed) vs server-side (`AppSearchInput @search` + endpoint JSON) : critère volume + patterns complets
- [structure_templates.md](structure_templates.md) - `Frontend/themes/default/{module}/`, ThemeResolver, override par thème, `showFrontMenus`
- [structure_template_folders.md](structure_template_folders.md) - folder-per-feature pour ≥1 fichier (`shop/{index,category,…}`), plat sinon
- [convention_no_bem_tailwind_first.md](convention_no_bem_tailwind_first.md) - pas de BEM dans Twig/Vue, utility-first Tailwind exclusivement
- [pattern_details_dropdown.md](pattern_details_dropdown.md) - dropdowns Twig en `<details data-dropdown>` (ouverture native, clic-extérieur/Échap via `detailsDropdown.js`), jamais un `<select>`
- [pattern_shared_listing_card.md](pattern_shared_listing_card.md) - composant card partagé par module (`PostCard`, `ShopListingCard`) consommé depuis les apps Home/Archive/Term/ShopIndex/etc.
