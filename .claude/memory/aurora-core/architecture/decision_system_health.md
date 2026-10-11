---
name: Accès développeur - État du système
description: Bloc de santé en tête de la Vue d'ensemble (4.12.0) - battement du worker en cache, derniers passages des tâches par id du Scheduler, files Messenger, sondes ; unités systemd dans un réglage vide
type: project
---

## Règle

`src/Module/Dev/Health/` porte le bloc « État du système » de
`/dev/dashboard` (4.12.0, 10/10/2026), lu en XHR après l'affichage par
`dev_dashboard_health` (`SystemHealthReport::build()`).

- **Worker** : `WorkerHeartbeat` écoute `WorkerStartedEvent` et
  `WorkerRunningEvent`, écrit dans `cache.app` au plus toutes les 30 s ;
  muet plus de 120 s = en panne. Le worker doit être relancé après un
  déploiement pour charger l'écouteur.
- **Tâches** : `ScheduleRunRecorder` écoute `PostRunEvent` / `FailureEvent`
  du Scheduler, clé = hash de l'id du `RecurringMessage`.
  `ScheduleInspector` juge le retard sur le rythme du déclencheur (écart entre
  ses deux prochains passages). Jamais vue depuis le vidage du cache =
  « pas encore vue », pas en retard.
- **Files** : `QueueInspector` compte via `MessageCountAwareInterface`, l'âge
  du plus ancien par la table `messenger_messages` (Doctrine seulement),
  liste `failed`, relance (nouveau dispatch puis `reject`) ou supprime, jeton
  CSRF `admin` dans le corps JSON (`useRequest` ne pose pas d'en-tête).
- **Unités systemd** : réglage `system_health_units` (Réglages > Système),
  **vide par défaut**. Le dépôt est public : aucun nom d'unité ni d'hôte du VPS
  dans le code. Le nombre de redémarrages (`NRestarts`) s'affiche sans juger
  l'unité depuis la 4.12.1 : avec `Restart=always`, une sortie voulue
  (`--time-limit`, déploiement) compte comme un plantage.
- **Plantage du worker** : `WorkerHeartbeat` écrit `stoppedCleanly: true` sur
  `WorkerStoppedEvent` (SIGTERM, limite de temps ou de mémoire). Un démarrage
  qui trouve un battement à `false` garde son `at` comme `crashedAt` ; la ligne
  reste en avertissement `CRASH_SHOWN_FOR` (24 h). Un battement sans la clé
  (écrit avant la 4.12.1) ne compte pas.

## Pourquoi

Demande d'Axel après l'audit du 10/10 : le worker de prod a planté deux fois
le matin (PostgreSQL redémarré par unattended-upgrades) sans que rien dans
l'application ne le montre.

## Comment l'appliquer

Une nouvelle sonde = un `HealthCheck` (clé, statut, `suite.health.*` traduit
fr/en/es) ajouté à une section de `SystemHealthReport`. Une mesure qui part
sur le réseau reste sous 4 s de délai et ne lève jamais.
