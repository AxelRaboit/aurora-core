# DONE - Fusion Media → GED (un seul système de fichiers)

> **Statut (2026-05-30) : ✅ terminée - toutes les phases livrées.**
> `Document` (GED) est désormais **l'unique entité fichier** d'Aurora. Le
> module Media a été supprimé en Phase 5, les tables `core_media*` droppées,
> et tous les consommateurs migrés. Voir l'historique git (préfixes
> `feat(erp)|...|feat(media)`, à partir du commit `0a0780a1`) pour le
> détail par phase.
>
> **Décision (2026-05-25)** : supprimer la médiathèque (`/backend/media/media`) et
> tout câbler sur la GED. `Document` (GED) devient **l'unique entité fichier**.
> Choix assumé malgré le compromis (cf. « Risques » plus bas) : `Document`
> portera à la fois des champs de *rendu* (variants/focal) et de *records*
> (statut/OCR/catégorie/versions).
>
> **Mode d'exécution : par phases, chacune verte et livrable.** Tant que la
> Phase 5 n'est pas faite, Media reste en place → réversible. (✅ toutes
> les phases sont passées le 2026-05-30 - historique conservé pour la
> traçabilité.)

---

## Pourquoi c'est gros (état des lieux mesuré le 2026-05-25)

Media n'est pas qu'une page admin : c'est la colonne vertébrale des **assets
de contenu** rendus sur le frontend.

**~7 entités FK-référencent Media (`media_id`)** :
- `Editorial\Post` : `featured_media_id` + `mediaId` dans les blocs EditorJS
  (`core_post_translations.blocks` JSONB)
- `Ecommerce\Listing` + `Ecommerce\ListingCategory`
- `Erp\Product`
- `Photo\Gallery` (cover) + `Photo\GalleryItem`
- Branding : `logo_media_id`, `favicon_media_id`, `seo_default_og_image`
  (`ApplicationParameterEnum`)
- Photos de profil utilisateur (`Platform\User`)

**17 consommateurs de `MediaUrlGenerator`** (rendu frontend) : SEO (`og:image`),
branding, `StorageUrlExtension`, Ecommerce (Cart/Listing/ListingCategory),
Editorial (PostSerializer, BlocksRenderer, PostPageRenderer), Erp (Product),
Photo (Gallery), Platform (UserProfilePhotoUrlGenerator), Configuration
(Settings/Theme), DataFixtures, Testing.

**MediaPicker dans l'éditeur de contenu** : `AppBlockEditor`, `MediaTextBlock`,
`AppImagePickerField` (form partagé), `PostFeaturedImagePanel`, `PostSeoPanel`,
`useGalleryEditItems`, util `shared/utils/mediaPicker.js`.

**Ce que GED ne sait pas (encore) faire** : variants (thumbnail/medium/large,
WebP, srcset), focal point, pipeline d'URL de variants. À bâtir en Phase 1.

---

## Phase 1 - Parité de rendu sur `Document` (additif, ZÉRO régression) ✅ DONE 2026-05-30

Socle obligatoire : `Document` doit savoir rendre des images frontend avant
qu'un seul consommateur puisse migrer.

- [ ] Déplacer `ImageVariantGenerator` de `Module/Media/Library/Service/` →
      `Core/Storage/Service/` (même move que `ImageCropper` / `MimeTypeEnum`).
      Mettre à jour les imports Media.
- [ ] Ajouter à `AbstractDocument` : `focalX`/`focalY` (float nullable) +
      `variants` (json). `width`/`height`/`alt`/`caption` existent déjà.
- [ ] Générer les variants à l'upload GED (`GedDocumentUploader`) + au crop
      (`DocumentManager::cropImage`) ; stocker dans `variants`.
- [ ] Créer `DocumentUrlGenerator` (Core ou Ged) : `publicUrl`, `variantUrl`,
      `focalPositionCss` - calqué sur `MediaUrlGenerator`.
- [ ] Exposer variants/focal dans `DocumentSerializer`.
- [ ] Migration : `ALTER TABLE core_ged_documents ADD focal_x, focal_y, variants`.
- [ ] Tests : variants générés à l'upload/crop, URLs de variants.
- **Risque : nul** (aucun consommateur touché, Media intact).

## Phase 2 - Migrer les consommateurs FK (un module à la fois) ✅ DONE 2026-05-30

Pour chaque module : `media_id → document_id`, serializer/renderer basculé sur
`DocumentUrlGenerator`, picker basculé. **+ migration de données** (copier les
lignes `core_media` utilisées vers `core_ged_documents`, remapper la FK).

- [ ] Editorial Post (`featured_media_id` → `featured_document_id`)
- [ ] Ecommerce Listing + ListingCategory
- [ ] Erp Product
- [ ] Photo Gallery + GalleryItem (cover + items)
- [ ] Branding (logo/favicon/og:image) - settings `*_media_id`
- [ ] Photos de profil utilisateur
- **Risque : élevé** (FK + données). Un module = un lot de commits + une
      migration testée. Garder Media lisible en parallèle pour comparer.

## Phase 3 - Contenu embarqué (JSONB) ✅ DONE 2026-05-30

- [ ] Remapper `{"type":"image","data":{"mediaId":N}}` →
      `{"documentId":M}` dans `core_post_translations.blocks` (et tout autre
      contenu EditorJS : Notes/Block). Migration de données + tool EditorJS
      (`MediaTextBlock` → `DocumentTextBlock` ou param générique).
- **Risque : élevé** (parsing JSONB, ne pas perdre de contenu).

## Phase 4 - Picker unifié + nav ✅ DONE 2026-05-30

- [ ] `DocumentPickerModal` partout : `AppBlockEditor`, `AppImagePickerField`
      (form partagé), `PostFeaturedImagePanel`, `PostSeoPanel`, galerie.
- [ ] `shared/utils/mediaPicker.js` → `documentPicker.js`.
- [ ] Retirer le NavItem `/backend/media/media` (toggle module Media).

## Phase 5 - Suppression de Media ✅ DONE 2026-05-30

Une fois TOUTES les données migrées et vérifiées :

- [ ] Drop module `Media` : entités `Media`/`MediaFolder`/`MediaVersion`,
      `MediaManager`, `MediaUrlGenerator`, `MediaUsageProvider`, controllers,
      Vue (`MediaApp`, etc.), route `/backend/media/media`.
- [ ] Retirer de `resolve_target_entities` (AuroraBundle).
- [ ] Migration : drop tables `core_media`, `core_media_folders`,
      `core_media_versions` + séquences.
- [ ] Nettoyer i18n `backend.media.*`, le groupe de settings `media`, etc.

---

## Risques / compromis assumés

1. **Entité bi-domaine** : `Document` mélange rendu (variants/focal) et records
   (statut/OCR/catégorie). C'est l'inverse de la séparation propre bâtie en
   début de session - accepté pour avoir un seul stockage.
2. **Migrations de données irréversibles** : faire des backups avant Phase 2/3.
   Tester chaque migration sur une copie.
3. **Clients (aurora-welding, aurora-client)** : si un client référence
   `MediaInterface`, sa migration devra suivre. Auditer côté client avant
   Phase 5.
4. **Frontend** : tout `srcset`/`og:image`/`variantUrl` doit continuer à
   marcher après bascule - tests de rendu (PostPageRenderer, SEO) indispensables.

## Point de départ

**Commencer par Phase 1** : purement additive, aucune régression, et c'est le
prérequis de tout le reste. Voir aussi la mémoire
`.claude/memory/aurora-core/architecture/pattern_self_owned_storage.md`
(versioning + crop + ImageCropper déjà mutualisé en Core).
