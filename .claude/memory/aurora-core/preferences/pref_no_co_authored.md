# Préférence : pas de `Co-Authored-By` Claude dans les commits

## Règle

**Ne jamais ajouter** de ligne `Co-Authored-By: Claude …` dans les
messages de commit, même implicitement via le footer généré par défaut.

## Pourquoi

L'utilisateur a explicitement demandé "ne co-author pas" lors d'une
session antérieure. Préférence personnelle de présentation du repo
(historique propre, attribution claire).

## Comment l'appliquer

### Format de commit standard à utiliser

```
feat: short summary

Body explaining the why and what, multi-line if needed.
- bullet 1
- bullet 2
```

**Pas** :
```
feat: short summary

Body explaining the why and what.

🤖 Generated with [Claude Code]
Co-Authored-By: Claude <noreply@anthropic.com>
```

### Vérification

Si un commit accidentel contient le co-author :
```bash
git log -1 | grep -i "co-author"
```

Ne pas réécrire l'historique passé pour ça (déjà arrivé) - juste s'en
souvenir pour les prochains commits.

## Source

Préférence utilisateur explicite, énoncée pendant la première session du
rollout. Réitérée en clair : "ne co author pas".
