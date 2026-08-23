# Convention : traductions des privilèges

## Règle

Pour **chaque** `NavPermission('<key>')` déclarée dans un module, il faut
une traduction **française et anglaise** sous la clé i18n
`backend.permissions.names.<key>`.

**Format obligatoire : structure NESTED** (pas de clé plate quotée). Vue-i18n
traverse les niveaux dot-par-dot et ne sait pas chercher une clé littérale
contenant des `.`.

```yaml
# src/Module/<Module>/translations/messages.fr.yaml
backend:
  permissions:
    names:
      planning:
        plannings:
          view: Voir les plannings
          manage: Gérer les plannings (création, édition, suppression)
        events:
          manage: Gérer les événements du planning
```

```yaml
# messages.en.yaml
backend:
  permissions:
    names:
      planning:
        plannings:
          view: View plannings
          manage: Manage plannings (create, edit, delete)
        events:
          manage: Manage planning events
```

### ❌ Ne PAS faire

```yaml
# Les clés plates avec quotes se chargent dans le JSON sous forme de
# string-clé, mais vue-i18n ne les trouve JAMAIS - son resolver
# traverse `names → planning → plannings → view`, pas la chaîne
# littérale "planning.plannings.view".
backend:
  permissions:
    names:
      'planning.plannings.view': Voir les plannings  # ❌
```

Symptôme : la modale d'admin affiche la **clé brute**
(`planning.plannings.view`) au lieu du libellé traduit.

## Pourquoi

L'UI d'attribution des privilèges (`/backend/platform/users/{id}/privileges`)
affiche chaque privilège via :

```vue
<span class="text-xs">
    {{ t('backend.permissions.names.' + priv, priv) }}
</span>
```

Le 2e argument est le fallback : sans traduction, le label brut
(`planning.plannings.view`) s'affiche, ce qui est moche et pas lisible
pour un utilisateur non technique.

## Comment l'appliquer

### À chaque nouveau module avec permissions

1. Lister tes `NavPermission(...)` dans `<Module>Module.php`.
2. Ajouter dans `src/Module/<Module>/translations/messages.fr.yaml` ET
   `messages.en.yaml` les entrées `backend.permissions.names.*`.
3. Lancer `make translation` pour régénérer les JSON consommés par vue-i18n + clear cache.

### Audit

```bash
# Toutes les permissions déclarées
grep -rhoE "NavPermission\('[^']+'\)" src/ --include="*.php" \
  | sort -u

# Toutes les traductions présentes
grep -rE "'[a-z_]+\.[a-z_]+\.[a-z_]+':" src/Core/translations/ \
    src/Module/*/translations/ \
  | grep "permissions:" -A 100 | head -50
```

Les deux listes doivent matcher (modulo le nombre - chaque clé apparaît
2× dans les translations : fr + en).

## Localisation par module

Chaque module possède SES propres traductions de privilèges. Convention
post-Jalon 5 : nom de privilège = `<module_id>.<entity>.<action>` partout,
y compris pour les sous-modules Core (general, platform, media,
configuration). Les traductions vivent dans le YAML du module owner :

- `general.<entity>.<verb>` → `src/Core/Module/translations/messages.{fr,en}.yaml`
- `platform.<entity>.<verb>` → `src/Core/Module/translations/messages.{fr,en}.yaml`
- `media.<verb>` / `media.folders.<verb>` → `src/Core/Module/translations/messages.{fr,en}.yaml`
- `configuration.<entity>.<verb>` → `src/Core/Module/translations/messages.{fr,en}.yaml`
- `<module>.<entity>.<verb>` (modules métier) → `src/Module/<Module>/translations/messages.{fr,en}.yaml`

Voir [[convention-privilege-naming]] pour la règle de nommage uniforme.

## Source

Convention demandée par l'utilisateur le 2026-05-09 après avoir
constaté que beaucoup de privilèges affichaient leur clé brute dans
l'UI d'admin. Ajout en bloc des traductions pour Core + tous les
modules existants (Editorial, Crm, Erp, Ecommerce, Photo, Billing, Ged,
Project, Planning).
