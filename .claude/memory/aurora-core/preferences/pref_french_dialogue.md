# Préférence : dialogue en français

## Règle

L'utilisateur communique en français. Les réponses Claude (texte
utilisateur, commit messages techniques en anglais OK, mais dialogue
conversationnel en français).

## Pourquoi

Préférence linguistique observée à travers toutes les sessions. Naturel
pour la collaboration.

## Comment l'appliquer

- **Texte conversationnel** : français.
- **Code (variables, classes, comments)** : anglais (convention du projet).
- **Commit messages** : anglais (convention du projet, cohérent avec
  l'historique git).
- **Docs `docs/aurora-core/`** : français pour la prose, anglais pour le code/
  exemples (cohérent avec les docs existantes).

### Style à éviter

- ❌ Anglicismes francisés inutiles ("review-er", "merge-er")
- ❌ Surutilisation des emojis (sauf ✅/❌/⚠️ pour les états)
- ❌ Tournures "j'ai fait X" trop répétitives - varier (je viens de, c'est
  fait, terminé, etc.)

### Style à privilégier

- ✅ Phrases courtes, denses
- ✅ Code blocks bien délimités
- ✅ Tableaux pour les comparaisons
- ✅ Liens inline vers les commits / docs

## Source

Observation comportementale (toutes les sessions de rollout en français).
Pas de directive explicite, mais cohérent.
