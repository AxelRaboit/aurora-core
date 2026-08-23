---
name: pattern-single-locale-mode
description: Toggle réversible "single_locale_mode" - masque le multi-langue à l'UI/écriture sans toucher au schéma ni aux données
metadata:
  type: project
---

## Fait

Aurora dispose d'un setting `single_locale_mode` (`ApplicationParameterEnum::SingleLocaleMode`,
groupe `localization`, bool, défaut `0`) qui force l'app à fonctionner
en mono-langue (la `default_locale`) sans casser l'archi multi-langue
sous-jacente.

Piliers :
- `LocaleContextInterface` (`src/Core/Locale/Service/`) - source de
  vérité runtime ; mémoization in-request des reads `SettingRepository`.
- `LocaleSubscriber` force la default locale et ignore la session quand
  le mode est ON.
- `SingleLocaleRedirectSubscriber` redirige (`301`) les URLs préfixées
  par une autre locale vers la default.
- `Context::activeLocales()` (front public) filtre vers `[default]`
  → masque automatiquement le `LocaleSwitcher` + sitemap/RSS mono-locale.
- `TranslationLocaleSyncer` : préserve les `XxxTranslation` dormantes
  en DB lors des updates en single mode (réversibilité).

## Pourquoi

L'utilisateur voulait pouvoir gérer du contenu mono-langue **sans
perdre** la capacité multi-langue. La contrainte forte : **réversibilité
à chaud** (toggle ON/OFF dans `/backend/configuration/settings`, pas de migration, pas
de perte des contenus EN si on bascule à FR puis re-bascule). Solution :
filtrer l'UI et les écritures, mais jamais altérer les lectures ni le
schéma.

## Comment l'appliquer

- Toute nouvelle entité avec `XxxTranslation` doit suivre les conventions
  de [[convention-locale-context]].
- Si tu ajoutes un Manager qui supprime des translations dans `applyInput()`,
  **passer par `TranslationLocaleSyncer::stale()`** au lieu d'un loop
  manuel. Sinon, basculer en single FR détruit les EN.
- Routes frontend : garder le segment `/{locale}` même si le single
  mode redirige tout vers la default. Ça préserve la capacité multi.

Doc complète : `docs/aurora-core/dev/single_locale_mode.md`.
Commits de référence : `25fd1463` (foundation), `d02a1260` (propagation),
`04fc9ec4` (UI), `078f9fcc` (nettoyage hardcodes).
