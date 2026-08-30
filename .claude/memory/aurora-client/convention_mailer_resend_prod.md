---
name: convention-mailer-resend-prod
description: En prod, MAILER_DSN passe par Resend (`symfony/resend-mailer`, déjà en require d'aurora-core). Le `smtp://localhost:1025` du .env est un défaut de dev qui n'envoie rien ailleurs.
metadata:
  type: feedback
---

## Règle

En production, un projet client Aurora envoie ses mails via **Resend**. Le DSN
va dans le `.env.local` du serveur, jamais dans `.env` ni dans un commit :

```dotenv
MAILER_DSN=resend+api://API_KEY@default
# ou, si le port 587 est préféré à l'API HTTP :
MAILER_DSN=resend+smtp://resend:API_KEY@default
```

Les deux formes sont déjà documentées en commentaire dans le `.env`
d'aurora-core, sous le marqueur `###> symfony/resend-mailer ###`.

Par environnement :

| Environnement | `MAILER_DSN` | Effet |
|---|---|---|
| dev | `smtp://localhost:1025` | Mailpit local, rien ne sort de la machine |
| test | `null://null` | Tout est jeté |
| prod | `resend+api://API_KEY@default` | Envoi réel |

`MAILER_FROM` doit porter un domaine **vérifié côté Resend** (SPF + DKIM
publiés chez le registrar). Sans ça Resend refuse l'envoi, et comme les mails
partent en async via Messenger, le refus n'apparaît pas dans la réponse HTTP :
il faut aller le lire dans `var/log/prod.log` ou dans le journal du worker.

## Pourquoi

`symfony/resend-mailer` est dans le `require` d'aurora-core, pas dans son
`require-dev`. C'est délibéré : le pont survit à un `composer install --no-dev`,
donc un serveur fraîchement provisionné n'a besoin que de la clé, pas d'un
paquet supplémentaire.

Le piège est que `.env` porte `MAILER_DSN=smtp://localhost:1025`. Sur un
serveur sans `.env.local` (ou avec un `.env.local` qui ne redéfinit pas la
variable), l'application démarre sans erreur et tente d'envoyer sur un SMTP
local inexistant. Aucun mail ne part, aucune alerte ne se déclenche, et la
réinitialisation de mot de passe est cassée en silence.

## Comment l'appliquer

Au premier déploiement, poser la clé sans qu'elle transite par un historique
shell ni par un transcript :

```bash
cd /var/www/<projet>
read -rsp "Resend API key: " K && echo
sed -i "s|^MAILER_DSN=.*|MAILER_DSN=resend+api://$K@default|" .env.local
```

Puis, **obligatoirement**, redémarrer les deux consommateurs de la variable :

```bash
sudo systemctl reload php8.4-fpm     # reset OPcache, FPM relit .env.local
sudo systemctl restart aurora-worker # le worker a lu .env.local à son boot
```

Oublier le `restart` du worker est le cas le plus fréquent : les requêtes HTTP
prennent bien le nouveau DSN, mais tout ce qui passe par le transport `async`
(c'est-à-dire l'essentiel des mails) continue de viser l'ancien. Symptôme :
`messenger_messages` se vide normalement, et aucun mail n'arrive.

Constaté au déploiement d'app.axelraboit.fr le 30/08/2026.

La liste complète des variables à fournir en prod (dont
`AURORA_MOUNT_POINT_KEY`, qui obéit à la même règle du défaut qu'il ne faut
surtout pas garder) vit dans `docs/aurora-client/deployment/README.md` §2.
