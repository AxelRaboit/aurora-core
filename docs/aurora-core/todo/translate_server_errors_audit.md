# Audit - `translateServerErrors` non appliqué côté Vue

## Contexte

Par convention Aurora, `Aurora\Core\Validation\Service\PayloadValidator`
retourne des **clés i18n** dans le payload d'erreur JSON (ex.
`backend.editorial.posts.errors.title_required`), pas du texte traduit.

Les composants form (`AppInput`, `AppTextarea`, `AppMultiselect`, …)
affichent leur prop `:error` **littéralement** : sans transformation,
l'utilisateur voit la clé brute sous le champ au lieu du libellé FR/EN.

Le helper [`translateServerErrors`](../../../src/Core/assets/shared/utils/validation/translateServerErrors.js)
existe pour transformer le map `{ field: cleI18n }` → `{ field: texteTraduit }`,
mais sur les ~30 fichiers qui manipulent `data.errors`, ~12 seulement
l'utilisent. Le reste est un mix de **vrais bugs d'affichage** et de
**cas légitimes** (toast-only, composables génériques qui re-déléguent).

## Direction d'implémentation

1. Lister les candidats depuis la racine du projet :
   ```bash
   grep -rl "data\.errors\|setErrors" src --include="*.vue" --include="*.js" \
     | xargs grep -L "translateServerErrors"
   ```

2. Pour chaque fichier, vérifier si **les errors sont bindées field-par-field
   à un AppInput** (ou équivalent). Si oui → bug, ajouter
   `translateServerErrors(t, data.errors)` avant l'assignation au ref.

3. Cas légitimes à laisser tels quels :
   - **Toast-only** : `toast.error(t(data.error))` n'a pas besoin du
     helper (seule valeur, traduite inline)
   - **Composables génériques** (`useForm`, `useFormAction`, …) qui
     acceptent un map déjà traduit en entrée - la translation se fait
     chez l'appelant

4. PR séparée, commit `audit(forms): translate server errors consistently`.

## Pointeurs code

- Helper : `src/Core/assets/shared/utils/validation/translateServerErrors.js`
- Convention domaine PHP côté complémentaire :
  `.claude/memory/aurora-shared/convention_domain_exception_translation_key.md`
  (même philosophie côté exceptions PHP, via `DomainException::TRANSLATION_KEY`)
- Usage de référence : chercher `translateServerErrors` dans
  `src/Module/Editorial/` ou `src/Module/Crm/` - convention appliquée
  partout depuis l'audit forms de mai 2026.
