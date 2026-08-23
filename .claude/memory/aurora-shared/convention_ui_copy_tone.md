---
name: convention-ui-copy-tone
description: Ton impersonnel et neutre pour le copy UI - pas de tu/vous, pas de référence au nom du produit, pas d'emojis dans le texte
metadata:
  type: feedback
---

## Règle

Le copy UI (texte visible par l'utilisateur final dans les traductions) reste **impersonnel, neutre et durable** :

1. **Pas de seconde personne directe**
   - ❌ « Définis tes objectifs… » / « Tu peux faire un virement… »
   - ❌ « Define your goals… » / « You can transfer… »
   - ✅ « Les objectifs d'épargne suivent… » / « Les actions au-dessus permettent un virement… »
   - ✅ « Savings goals track… » / « The actions above support a transfer… »

   Préférer la voix passive, l'infinitif, ou les phrases dont le sujet est l'objet métier (« le portefeuille », « la transaction », « le budget »).

2. **Pas de mention du nom commercial du produit dans le copy**
   - ❌ « Aurora calcule automatiquement… »
   - ❌ « Aurora apprend à catégoriser… »
   - ✅ « Le solde est calculé automatiquement »
   - ✅ « L'auto-catégorisation apprend à classer… »

   Une mention de marque dans le copy crée une dépendance : un rebrand ou un fork casse N traductions. Le système se désigne par sa fonction, pas par sa marque.

3. **Pas d'emojis dans le texte traduit**
   - ❌ « Le bouton 📃 ouvre la liste »
   - ✅ « Un bouton sur chaque ligne ouvre la liste »

   Les emojis dans le copy diluent les vrais signaux visuels (icônes Lucide servies par les composants). Ils se mélangent aussi mal avec une typographie sérifée et vieillissent vite.

## Où ça s'applique

**Strictement** :
- `AppMessage` helper banners (`<page>.help`, `<page>.help_<variant>`)
- Empty states (texte affiché quand la liste est vide)
- Page subtitles (sous-titres descriptifs)
- Modal titles + descriptions explicatives
- Tooltips au-delà d'un mot

**Avec souplesse** :
- Toasts (peuvent être plus directs - « Portefeuille créé. ») mais éviter quand même « tu / vous »
- Boutons (déjà courts par nature)
- Validation error messages (souvent générés depuis Symfony Validator)

**Pas concerné** :
- Logos / branding pages (la marque y est légitime)
- Login / welcome screens (un « Bienvenue sur X » est attendu)
- Identifiants techniques (route names, classes CSS, etc.)
- Code, namespaces, commentaires PHP / JS - `Aurora` reste partout dans les imports, c'est uniquement le **copy traduit visible** qui est concerné

## Exemples de réécriture

| ❌ Avant | ✅ Après |
|---|---|
| « Définis tes objectifs d'épargne (vacances…). Si tu lies un objectif à une catégorie, Aurora additionne les transactions. Sinon tu déposes manuellement. » | « Les objectifs d'épargne suivent une cagnotte (vacances…). Lorsqu'un objectif est lié à une catégorie, les transactions de cette catégorie sont additionnées automatiquement comme avancement. À défaut, les dépôts se font manuellement. » |
| « Set how much you plan to spend each month. Aurora compares planned vs. actual. The 📃 button opens the list. » | « The monthly budget defines planned amounts per category. A planned-vs-actual comparison is computed automatically. Each row exposes a button that opens the list. » |
| « Tu peux ajuster manuellement la balance » | « La balance peut être ajustée manuellement » |
| « Aurora apprend automatiquement » | « L'auto-catégorisation apprend automatiquement » |

## Détection rapide des violations

```bash
# Tutoiement / nom produit dans les translations
grep -rnE "\\b[Tt]u\\b|\\b[Tt]on\\b|\\b[Tt]a\\b|\\b[Tt]es\\b|\\bAurora\\b|\\byou\\b|\\byour\\b" \
  src/Module/*/translations/messages.*.yaml \
  | grep -vE "Aurora\\\\Module|^# |authenticated|guests"

# Emojis dans les translations (cf. liste partielle)
grep -rnE "📃|📅|📊|🎯|💰|✅|❌|⚠️|🔔" \
  src/Module/*/translations/
```

## Pourquoi

- **Durabilité** : tutoyer date l'app (ton « startup years 2015 »), vouvoyer rend formel à l'excès. Le passif/impersonnel reste neutre dans 5 ans.
- **Internationalisation** : le tutoiement n'existe pas en EN (pas de tu/vous), traduire « Tu peux… » vers « You can… » casse déjà la cohérence de ton entre les deux langues. Le passif/impersonnel se traduit symétriquement.
- **Rebrand-safe** : l'app peut changer de nom commercial sans casser le copy. Le système se désigne par sa fonction.
- **Hiérarchie visuelle** : les emojis dans le copy bruitent l'attention déjà sollicitée par les icônes des composants. Une seule source de iconographie (Lucide via les composants) reste lisible.
