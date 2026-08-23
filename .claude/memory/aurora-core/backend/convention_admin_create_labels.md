# Convention - labels admin create/add (FR + EN)

## Règle

Pour chaque entité disposant d'une page CRUD admin (liste avec modal/éditeur
de création), les libellés i18n doivent suivre **Convention A** (style
Ecommerce) :

### FR (`messages.fr.yaml`)
- `<entity>.add` = libellé du **bouton sur la toolbar de la liste** →
  `"Ajouter un <entity>"` / `"Ajouter une <entity>"` (accord en genre).
- `<entity>.create` = **titre du modal/éditeur de création** →
  `"Nouveau <entity>"` / `"Nouvelle <entity>"`.
- `<entity>.edit` = `"Modifier {name}"` (inchangé).
- Le bouton submit reste `shared.common.save` / `shared.common.create`.

### EN (`messages.en.yaml`)
- `<entity>.add` = `"Add <entity>"` (capitalisation minimale, lowercase
  entity - pas de title case style américain).
- `<entity>.create` = `"New <entity>"`.

### Interdits
- Pas de `"Créer un <entity>"`, `"Créer le <entity>"`, `"Créer l'<entity>"`
  pour des labels d'action sur entité (le verbe "Créer" reste réservé au
  bouton submit générique `shared.common.create` = "Créer").
- Pas de clé `new:` pour une entité - utiliser `create:`.

## Pourquoi

Avant standardisation, l'audit a relevé **5 conventions différentes** :
- Crm : `"Créer l'entreprise"`, `"Créer le contact"`, `"Créer l'affaire"`
- Ged / PdfForm / Photo : `"Créer un X"` (verbe + nom)
- Ecommerce (référence) : `add: "Ajouter un X"` / `create: "Nouveau X"`
- Editorial : mix `"Ajouter"` (trop générique) + `"Nouveau contenu"`
- Crm agencies/services : `new:` au lieu de `add:`/`create:`

Le pattern UX moderne (Notion, Linear, Sylius) place l'**action verbeuse**
sur le bouton toolbar (qui doit annoncer ce qu'il fait) et le **nom court**
dans le titre du modal/éditeur (qui se passe d'introduction). Convention A
applique ça uniformément.

## Comment l'appliquer

À chaque nouvelle entité Aurora avec CRUD admin :

1. Déclarer les 2 clés dans `messages.fr.yaml` :
   ```yaml
   <entity>:
     add: Ajouter un/une <entity>
     create: Nouveau/Nouvelle <entity>
     edit: Modifier le/la <entity>
   ```
2. Mirror dans `messages.en.yaml` :
   ```yaml
   <entity>:
     add: Add <entity>
     create: New <entity>
     edit: Edit <entity>
   ```
3. Côté Vue :
   - Bouton toolbar de la liste → `t("backend.<entity>.add")`
   - `:title` de la `<AppModal>` ou en-tête de l'éditeur full-page →
     `entity ? t('backend.<entity>.edit', {...}) : t('backend.<entity>.create')`
   - Submit du form → `t("shared.common.save")` ou `t("shared.common.create")`.

### Variantes structurelles autorisées
- **Editor full-page** (cas `Post`) : la clé `create` sert de titre de
  l'en-tête de l'éditeur, exactement comme pour un modal.
- **Liste + sidemenu éditeur** (cas `Form` Editorial) : `add` sur le bouton
  primaire de la sidemenu, `create` ou clé spécifique (ex. `newForm`) sur
  l'en-tête de l'éditeur quand on est en mode création.
- **Bouton dans une sidemenu étroite avec titre de section au-dessus**
  (cas `taxonomies`, `post-types`) : la clé `add` peut être raccourcie à
  juste `"Ajouter"` / `"Add"`. Justification : le `<h2>` "Types de
  contenu" / "Taxonomies" juste au-dessus rend le nom d'entité redondant,
  et l'espace horizontal limité de la sidemenu fait wraper la version
  longue. Cas similaire : un bouton d'action évident dans une carte ou
  un widget dont le contexte rend l'objet implicite ("Ajouter" dans une
  carte « Champs personnalisés », par exemple).
- **Mais** : en cas de doute, garder la version longue. La forme courte
  n'est légitime que quand le contexte visuel immédiat (≤ 1 ligne
  au-dessus, même carte/widget) lève l'ambiguïté. Un bouton "Ajouter"
  perdu dans une page sans cadre de référence reste mauvais.

## Pièges

- **Accord en genre** : `un contact` (m), `une entreprise` (f), `une
  affaire` (f), `un service` (m), `une galerie` (f), `un dossier` (m). Une
  erreur d'accord se voit immédiatement dans l'UI.
- **Quotes YAML** : utiliser des apostrophes droites `'` (smart quotes
  cassent la lecture YAML dans certains contextes).
- **Renaming `new:` → `create:`** : toujours grep `src/` + `templates/`
  (le JS/Vue est désormais co-localisé sous `src/` depuis 0.5) pour
  patcher les consommateurs Vue/Twig/PHP. Une clé renommée sans patch
  des sites d'appel = label brut affiché.
