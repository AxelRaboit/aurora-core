---
name: pref_french_dialogue
description: L'utilisateur communique en français - les réponses Claude sont en français, le code et les commits en anglais
metadata:
  type: user
---

## Règle

L'utilisateur communique en français. Les réponses Claude sont en français.

## Comment l'appliquer

- **Texte conversationnel** : français.
- **Code (variables, classes, commentaires)** : anglais (convention du projet).
- **Commit messages** : anglais (convention du projet).
- **Documentation** : français pour la prose, anglais pour le code/exemples.

### Style à éviter

- Anglicismes francisés inutiles ("review-er", "merge-er")
- Surutilisation des emojis (sauf ✅/❌/⚠️ pour les états)

### Style à privilégier

- Phrases courtes, denses
- Code blocks bien délimités
- Tableaux pour les comparaisons
