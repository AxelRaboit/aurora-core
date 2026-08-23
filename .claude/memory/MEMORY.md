# Aurora - index mémoire racine

Ce fichier est l'index de haut niveau. Les mémoires sont organisées par
périmètre :

- **[`aurora-core/`](aurora-core/MEMORY.md)** - conventions, décisions, pièges
  et préférences propres au bundle aurora-core (PHP/Symfony/Vue).
- **[`aurora-client/`](aurora-client/MEMORY.md)** - patterns d'extension côté
  consommateur. Distribués via composer : les clients les lisent depuis
  `vendor/axelraboit/aurora/.claude/memory/aurora-client/`.
- **[`aurora-shared/`](aurora-shared/MEMORY.md)** - conventions transversales
  (Vue, HTTP, JS, i18n, process) utiles aussi bien pour aurora-core que pour
  aurora-client. Distribués via composer : les clients les lisent depuis
  `vendor/axelraboit/aurora/.claude/memory/aurora-shared/`.

> Toute nouvelle mémoire va dans le bon sous-dossier, jamais directement à
> la racine.
>
> **Règle de placement** : la convention s'applique uniquement à aurora-core
> → `aurora-core/`. Elle s'applique à tout dev écrivant du code (core ou
> client) → `aurora-shared/`. Elle concerne uniquement le code client (extension
> Sylius) → `aurora-client/`.
