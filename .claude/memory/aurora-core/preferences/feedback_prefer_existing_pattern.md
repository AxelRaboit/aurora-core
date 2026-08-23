# Feedback : préférer le pattern existant à une nouvelle abstraction

## Règle

Quand l'utilisateur signale une gêne **structurelle/UX**, d'abord vérifier si le
**pattern déjà en place** la couvre - avant d'introduire une nouvelle
abstraction. Pour ce projet, un groupe de features liées = **un module avec
des sous-toggles** (pattern Notes : `NotesBackend` + `NotesMarkdown`/`NotesBlock`/
`NotesPostIt`). C'est LE pattern de référence pour "une section qui contient
plusieurs sous-modules".

## Pourquoi

Cas vécu (2026-05) : Vault était déjà un module "Outils" (`VaultBackend`) avec 2
sous-toggles (`VaultSafe` = Coffre-fort, `VaultPasswordGenerator` = Générateur) -
soit exactement le pattern Notes voulu. Au lieu de le reconnaître, j'ai
**découplé** PasswordGenerator en module top-level séparé, ce qui a forcé une
cascade d'abstractions nouvelles : fusion de `NavSection` par id dans
`ModuleRegistry`, groupement du dashboard par section, suppression de `VaultSafe`…
L'utilisateur : « on complexifie pour rien… revenir à du simple et faire comme
les autres ». → 6 commits annulés (reset + force-push).

## Comment l'appliquer

- Avant un gros refacto structurel : « le pattern X (Notes, etc.) existe-t-il
  déjà et répond-il au besoin ? » Si oui, l'utiliser tel quel (au pire un
  renommage de label), pas de nouvelle mécanique.
- Ne PAS introduire merge-de-sections, groupement dashboard, découplage de
  module… sans nécessité prouvée. Le défaut Aurora reste l'extensibilité saine
  (cf. [[pref_think_long_term]]) MAIS pas au prix de réinventer un pattern qui
  existe déjà et marche.
- Multiplier les questions/itérations sur un même point = signal qu'on
  sur-conçoit ; revenir au plus simple.
