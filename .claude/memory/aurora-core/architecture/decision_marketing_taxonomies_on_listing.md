---
name: decision_marketing_taxonomies_on_listing
description: Les taxonomies marketing (categories, tags) vivent sur Listing (vitrine Ecommerce), jamais sur Product (catalogue ERP).
metadata:
  type: feedback
---

## Règle

Les taxonomies marketing - `ListingCategory`, `ListingTag`, et toute future
taxonomie de présentation - sont attachées à `Listing` (la vitrine Ecommerce),
**jamais** à `Product` (le catalogue ERP).

- URL canonique produit : `/shop/<slug>` - découplée des taxonomies.
- Routes de filtre : `/shop/category/<slug>` et `/shop/tag/<slug>` - pages
  séparées qui listent les `Listing` filtrés, sans changer le canonique de
  chaque produit.
- ManyToMany : `Listing ↔ ListingCategory`, `Listing ↔ ListingTag`. **Aucun
  lien** entre Product et ces taxonomies.

## Pourquoi

1. **Multi-canal Sylius-style** : un même `Product` peut être catégorisé
   différemment selon le canal de vente. Coupler les taxonomies à `Product`
   empêcherait d'avoir deux vitrines avec des classifications distinctes.
2. **Stabilité du canonique** : le SEO du produit (`/shop/<slug>`) ne doit
   pas bouger quand on re-catégorise. Re-catégoriser un `Listing` ne change
   pas l'URL canonique du produit.
3. **Séparation ERP vs Ecommerce** : `Product` (catalogue, prix, stock) vit
   dans le module ERP - la catégorisation commerciale n'a rien à y faire.
4. Le sub-DTO `ListingCategoryTranslationInput` reste `final readonly`
   (non-instrumenté) - cohérent avec la règle "seul le DTO racine consommé
   par le controller est instrumenté" ([[convention_extensibility]]).

## Comment l'appliquer

1. Nouvelle taxonomie de **présentation** (mise en avant, collection
   saisonnière, badge marketing) → l'attacher à `Listing` ou à une entité de
   présentation, pas à `Product`.
2. Filtre frontend par taxonomie → route dédiée `/shop/<taxonomy>/<slug>` qui
   query `Listing` joint à la taxonomie. URL produit individuel reste
   `/shop/<slug>` (jamais `/shop/<category>/<product-slug>`).
3. Si un besoin légitime émerge de catégoriser au niveau `Product` (ex:
   classification fiscale, famille produit pour reporting), créer une
   taxonomie **séparée** côté ERP avec un nom distinct - ne pas réutiliser
   `ListingCategory`.

## Source

Commits `c88e3c85` (entity), `a996c8af` (ManyToMany Listing↔Category),
`cb3c8a4a` (route /shop/category), `361ba300` (ListingTag), `963881d2`
(ManyToMany Listing↔Tag).
