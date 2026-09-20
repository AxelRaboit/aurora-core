# Délais d'attente du serveur web - les requêtes qui travaillent longtemps

Aurora sert presque tout en quelques dizaines de millisecondes. Trois choses
font exception, et elles ont en commun de **calculer longtemps avant d'écrire
le premier octet** : c'est ce moment-là que le serveur web mesure, et c'est
lui qui coupe.

| Ce qui travaille | Pourquoi c'est long |
|---|---|
| L'archive d'un dossier Google Drive | Chaque fichier est descendu de chez Google puis écrit dans un zip, en entier, avant que la réponse ne commence |
| Un export CSV volumineux | La requête et le formatage précèdent l'envoi |
| Une commande console lancée depuis le web | Rare, mais c'est le même cas |

## Apache : le réglage qui compte

Le défaut d'Apache est `Timeout 300` (`/etc/apache2/apache2.conf`), et PHP
passe par `mod_proxy_fcgi` sans délai propre : il hérite donc de ces trois
cents secondes.

**`max_execution_time` ne vous sauve pas.** Sur les paquets Debian et Ubuntu
il vaut souvent 3600 côté FPM, et `request_terminate_timeout` n'est pas posé :
PHP continue tranquillement de travailler pendant qu'Apache a déjà renvoyé un
`504` au visiteur. Le symptôme est déroutant : une erreur de passerelle au bout
de cinq minutes exactement, et rien dans les logs de l'application.

Dans le vhost du site :

```apache
<VirtualHost *:443>
  ServerName aurora.example.com

  # Combien de temps Apache attend PHP avant d'abandonner. Le défaut,
  # Timeout 300, se compte sur l'attente : une réponse qui ne commence
  # à arriver qu'au bout de cinq minutes est coupée, même si PHP
  # travaille encore.
  ProxyTimeout 900
```

Puis :

```bash
sudo apache2ctl configtest && sudo systemctl reload apache2
```

Sous nginx, l'équivalent est `fastcgi_read_timeout 900s;` dans le bloc qui
passe la main à PHP.

## Sous Caddy, il n'y a rien à régler

C'est la différence qui justifie de le noter ici plutôt que de renvoyer à la
documentation de chacun : **Caddy ne coupe pas par défaut**, là où Apache le
fait au bout de cinq minutes.

D'après sa documentation, `php_fastcgi` expose trois délais dans son transport,
et deux des trois sont illimités :

| Réglage | Défaut |
|---|---|
| `dial_timeout` | 3 s - la connexion à la socket, pas le travail |
| `read_timeout` | aucun |
| `write_timeout` | aucun |

Les délais du serveur lui-même (`servers { timeouts { … } }`) sont de la même
famille : `read_body`, `read_header` et `write` sont illimités par défaut, et
seul `idle` vaut cinq minutes - mais celui-là mesure l'attente **entre** deux
requêtes d'une connexion persistante, pas la durée d'une réponse.

Autrement dit, une migration vers Caddy fait disparaître ce plafond sans qu'on
ait rien à écrire, et la seule borne qui reste sur l'archive est celle du code.
Un Caddyfile minimal pour Aurora :

```caddy
aurora.example.com {
    root * /var/www/aurora-client/public
    php_fastcgi unix//run/php/php8.4-fpm.sock
    file_server
}
```

Si vous voulez malgré tout **poser** une borne plutôt que de vivre sans, c'est
le transport qu'il faut viser, et non les options globales :

```caddy
    php_fastcgi unix//run/php/php8.4-fpm.sock {
        transport fastcgi {
            read_timeout 900s
        }
    }
```

Un délai illimité n'est pas gratuit : une requête partie en boucle occupe un
processus FPM jusqu'à ce que quelqu'un s'en aperçoive. C'est un arbitrage, pas
un oubli d'Apache à corriger.

## Ce que ce compteur mesure, et ce qu'il ne mesure pas

C'est un délai **d'inactivité**, pas une durée totale. Une réponse qui envoie
des octets en continu peut durer des heures sans jamais le déclencher : c'est
le cas du téléchargement d'un fichier unique du Drive, qui est relayé en flux
et ne pose donc aucun problème quelle que soit sa taille.

Ce qui le déclenche, c'est le silence. Une réponse qui se construit en mémoire
ou sur disque avant d'être envoyée reste muette tout ce temps-là.

## Vérifier que le réglage s'applique vraiment

La présence de la directive ne prouve pas qu'elle gouverne les requêtes PHP :
suivant la façon dont la distribution déclare le connecteur FastCGI, le délai
peut venir d'ailleurs. Le contrôle honnête est une mesure, pas une relecture.

Sur un environnement **qui n'est pas la production**, posez temporairement un
délai court et appelez une page qui dort plus longtemps :

```apache
ProxyTimeout 5
```

```php
<?php sleep(8); echo 'fini';
```

Un `504` au bout de cinq secondes prouve que la directive gouverne. Remettez
ensuite la valeur voulue et rechargez. À ne pas faire sur un site en ligne :
l'abaissement, même bref, coupe les requêtes des visiteurs.

## L'archive du Drive reste bornée, et le timeout n'y change rien

La borne de l'archive vit dans le code d'aurora-core
(`DriveArchive::MAX_BYTES`, cent cinquante mégaoctets) et ne lit pas la
configuration du serveur. Elle est dimensionnée pour aboutir sur un Apache
laissé à son défaut de trois cents secondes, parce que c'est ce que rencontre
une installation qui n'a rien réglé.

Monter `ProxyTimeout` donne donc de la marge aux autres requêtes lentes, mais
**n'autorise pas des archives plus grosses** - pas plus que passer à Caddy, où
le plafond du serveur disparaît pourtant complètement. Les deux mesures qui fixent la
borne, prises sur un serveur réel contre un dossier partagé :

- **1,41 Mo par seconde** entre le serveur et Google ;
- **0,64 seconde par fichier**, qui est l'aller-retour vers Google, que le
  fichier pèse trois kilo-octets ou trois mégaoctets.

Un dossier de deux cents fichiers coûte donc cent vingt-sept secondes avant
même le premier octet de contenu. Le pire cas admis, deux cents fichiers et
cent cinquante mégaoctets, demande deux cent trente-quatre secondes.

Refaites la mesure sur votre serveur avant de conclure que la vôtre est
différente : la bande passante vers Google varie beaucoup d'un hébergeur à
l'autre.

## Voisins

- [`apache_xsendfile.md`](apache_xsendfile.md) - servir `var/uploads/` sans
  faire passer les octets par PHP
- [`server_provisioning.md`](server_provisioning.md) - le vhost complet
- [`README.md`](README.md) §9 - les autres pièges de déploiement
