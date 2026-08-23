# aurora_worker - systemd service setup

> **Status**: à mettre en place sur le serveur de production si un module utilise le transport `async`.

Ce document décrit la configuration du service systemd `aurora-worker` sur le serveur de production,
qui gère le traitement des messages asynchrones (jobs lourds) et le scheduler via Symfony Messenger.

---

## Configuration en production

Fichier : `/etc/systemd/system/aurora-worker.service`

```ini
[Unit]
Description=Aurora Messenger Worker
After=network.target

[Service]
User=www-data
Group=www-data
WorkingDirectory=/var/www/aurora
ExecStart=/usr/bin/php bin/console messenger:consume async scheduler_main --time-limit=3600 --memory-limit=512M
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

---

## Mise en place du service

```bash
# Créer le fichier de service
sudo nano /etc/systemd/system/aurora-worker.service

# Activer et démarrer
sudo systemctl daemon-reload
sudo systemctl enable aurora-worker
sudo systemctl start aurora-worker
sudo systemctl status aurora-worker
```

---

## Commandes utiles

```bash
# Statut et logs
sudo systemctl status aurora-worker
sudo journalctl -u aurora-worker -f        # suivre les logs en direct
sudo journalctl -u aurora-worker --since "1 hour ago"

# Contrôle du service
sudo systemctl start aurora-worker
sudo systemctl stop aurora-worker
sudo systemctl restart aurora-worker
```

---

## Notes

- **`async`** : transport pour les messages asynchrones (notifications, jobs lourds)
- **`scheduler_main`** : transport pour les tâches planifiées (équivalent d'un cron géré par Messenger)
- **`--time-limit=3600`** : le worker se relance proprement toutes les heures (évite les fuites mémoire)
- **`--memory-limit=512M`** : arrêt automatique si le process dépasse 512 Mo
- **`RestartSec=5`** : 5 secondes d'attente avant redémarrage en cas de crash

---

## Dev local

En local, on ne passe pas par systemd : le worker se lance via Make :

```bash
make start-dev-worker
```

Cette cible relance automatiquement le process en cas de crash et applique les mêmes
limites (`--time-limit=3600 --memory-limit=512M`) que la prod.
