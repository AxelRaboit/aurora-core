# Piège : un routage Messenger déclaré en YAML n'existe que chez core

**Règle.** Le routage d'un message d'aurora-core se déclare dans
`AuroraBundle::prependExtension`, jamais dans
`config/packages/messenger.yaml`. Le client ne fournit que le transport
`async`, que son propre `messenger.yaml` documente déjà comme obligatoire.

**Pourquoi.** `config/packages/messenger.yaml` d'aurora-core est la config de
*son* application de développement : elle n'est pas distribuée par composer.
Un message routé là-dedans n'est routé nulle part ailleurs.

Et contrairement aux limiteurs de débit, **ça ne casse rien**. Un message sans
route n'est pas une erreur : Symfony le passe au handler en ligne, dans la
requête courante. Le conteneur se construit, les tests passent, la file
n'existe pas. Constaté le 14/09/2026 : `RelocateDocumentMessage` était routé
en `async` depuis des mois et le déplacement d'un document GED tournait en
réalité dans la requête qui l'avait demandé, sur tous les projets clients.

C'est la même faute que [[pitfall_rate_limiter_client_config]], avec la panne
inverse : le limiteur manquant empêche le conteneur de se construire et se
répare en trois lignes, le routage manquant ne se voit pas du tout.

**Comment l'appliquer.**

1. Nouveau message long ou sortant → ajouter la classe au
   `prependExtensionConfig('framework', ['messenger' => ['routing' => …]])`
   d'`AuroraBundle`, avec les autres.
2. Pour vérifier qu'il arrive bien : `php bin/console debug:config framework
   messenger` doit montrer la route **sans** qu'elle soit dans le
   `messenger.yaml` de l'app.
3. Ne pas se fier à « le test passe » : en `test`, `async` est
   `in-memory://`, donc un message inline et un message mis en file ont le
   même effet observable si le test ne vérifie pas le transport lui-même.
   `FormSubmissionNotificationTest` le vérifie explicitement.
4. Le corollaire côté déploiement : une fonctionnalité qui dépend de la file
   dépend du worker. Voir `docs/aurora-client/deployment/worker_systemd.md`.
