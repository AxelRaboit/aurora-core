# Provisionner un serveur pour un projet aurora-client

D'une machine Ubuntu nue à une application servie en HTTPS. Ce document couvre
ce qui se fait **une fois**, avant le premier `make install-prod` ; la séquence
de déploiement elle-même est dans [`README.md`](README.md).

Les exemples supposent Ubuntu 24.04, Apache 2.4 et PHP-FPM 8.4. Adaptez les
noms : `<projet>` pour le répertoire applicatif, `<deployer>` pour le compte qui
lance les déploiements, `<db_user>` pour le rôle PostgreSQL.

> Prérequis de versions (PHP, Node, pnpm, PostgreSQL, binaires) :
> [`../../aurora-core/ops/prerequisites.md`](../../aurora-core/ops/prerequisites.md).

---

## 1. Paquets

```bash
sudo apt install libapache2-mod-xsendfile
sudo a2enmod xsendfile rewrite proxy_fcgi setenvif ssl headers
```

`mod_xsendfile` sert `var/uploads/` sans passer par PHP-FPM, voir
[`apache_xsendfile.md`](apache_xsendfile.md).

**Pas besoin de câbler PHP-FPM dans le vhost.** Sur Debian et Ubuntu, le paquet
`php8.4-fpm` installe `/etc/apache2/conf-enabled/php8.4-fpm.conf`, qui envoie
déjà tout `*.php` vers la socket via `proxy_fcgi`, pour tous les vhosts. C'est
pour ça que le vhost plus bas ne contient aucun `SetHandler`.

---

## 2. Base de données

Le rôle applicatif n'a besoin **ni** de `SUPERUSER` **ni** de `CREATEDB` :
`install-prod` ne crée plus la base, il crée le schéma dans une base qui existe
déjà. Créez donc les deux en amont, depuis le compte `postgres` :

```bash
DB_PASS=$(openssl rand -base64 24 | tr -d '/+=' | head -c 32)
sudo -u postgres psql -c "CREATE ROLE <db_user> LOGIN PASSWORD '$DB_PASS';"
sudo -u postgres createdb -O <db_user> <db_name>
```

Le mot de passe part ensuite dans le `DATABASE_URL` de `.env.local` (§4). Ne le
laissez pas dans l'historique du shell : générez-le comme ci-dessus plutôt que
de le taper.

---

## 3. Répertoire applicatif et permissions

C'est l'étape où l'on se trompe le plus souvent, parce que **deux comptes
distincts écrivent dans `var/`** :

- `www-data`, l'utilisateur de PHP-FPM, pour le cache runtime, les logs et les
  uploads ;
- `<deployer>`, le compte humain qui lance `make deploy-prod`, donc
  `cache:clear`, les migrations et le build.

Un `chown -R www-data:www-data var/` rend le second incapable d'écrire, et le
déploiement échoue dès `make cc-prod`. Le modèle qui marche donne la propriété
au déployeur, le groupe à `www-data`, et rend `var/` inscriptible par le groupe :

```bash
sudo install -d -o <deployer> -g www-data -m 2775 /var/www/<projet>
# après le git clone :
cd /var/www/<projet>
sudo chown -R <deployer>:www-data .
sudo install -d -o <deployer> -g www-data -m 2775 var/cache var/log var/share var/uploads
sudo find var -type d -exec chmod 2775 {} +
sudo find var -type f -exec chmod 664  {} +
```

Le bit **setgid** (`2775`) sur les répertoires est ce qui fait tenir le montage
dans le temps : tout fichier créé par l'un ou l'autre hérite du groupe
`www-data`. Complétez avec `umask 0002` dans le shell de déploiement pour que
les fichiers naissent inscriptibles par le groupe.

Ajoutez le déployeur au groupe : `sudo usermod -aG www-data <deployer>`.

### `.env.local` doit rester lisible par `www-data`

```bash
sudo chown <deployer>:www-data .env.local
sudo chmod 640 .env.local
```

En `600`, PHP-FPM ne peut plus le lire : Symfony retombe silencieusement sur les
valeurs de `.env` (DSN `localhost`, `APP_ENV=dev`) et l'application casse d'une
manière difficile à relier à un mode de fichier.

---

## 4. Variables d'environnement

La liste complète est en [§2 du README](README.md#2-variables-denvironnement-à-fournir-en-prod).
Les clés se génèrent **une fois**, sur le serveur :

```bash
php -r "echo bin2hex(random_bytes(16)), PHP_EOL;"          # APP_SECRET
php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"    # AURORA_MOUNT_POINT_KEY
php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"    # AURORA_ENCRYPTION_KEY
```

⚠️ Ces trois valeurs n'existent nulle part ailleurs. Voir
[§11 du README](README.md#11-sauvegardes) avant de considérer le serveur comme
opérationnel.

---

## 5. VHost Apache

```apache
<VirtualHost *:80>
  ServerName app.example.com
  DocumentRoot /var/www/<projet>/public

  <Directory /var/www/<projet>/public>
    # +FollowSymLinks n'est PAS decoratif : public/build est un lien
    # symbolique vers vendor/axelraboit/aurora/public/build. Sans lui,
    # Apache renvoie 403 sur la totalite des assets Vite. Le bloc
    # <Directory /var/www/> livre par Ubuntu l'active par defaut, mais tout
    # durcissement qui pose Options -FollowSymLinks casse le site sans que
    # rien n'explique pourquoi.
    Options -Indexes +FollowSymLinks
    AllowOverride None
    Require all granted
    DirectoryIndex index.php

    # Front controller Symfony. Remplace le .htaccess de symfony/apache-pack,
    # que le template n'installe pas.
    FallbackResource /index.php
  </Directory>

  # Offload des uploads vers Apache. XSendFilePath doit lister tous les
  # repertoires que l'application peut viser.
  XSendFile On
  XSendFilePath /var/www/<projet>/var/uploads

  # Rien hors de public/ ne doit etre servi directement.
  <Directory /var/www/<projet>/var>
    Require all denied
  </Directory>

  ErrorLog ${APACHE_LOG_DIR}/<projet>-error.log
  CustomLog ${APACHE_LOG_DIR}/<projet>-access.log combined
</VirtualHost>
```

```bash
sudo a2ensite <projet>
sudo apachectl configtest
sudo systemctl reload apache2
```

---

## 6. HTTPS

Le DNS doit déjà pointer sur la machine, sinon la validation échoue.

```bash
sudo certbot --apache -d app.example.com --non-interactive --redirect
```

Ce que certbot fait, et qu'il faut savoir pour ne pas être surpris ensuite :

- il **recopie** votre vhost `:80` dans un nouveau `<projet>-le-ssl.conf` sur
  le port 443, directives `XSendFile` comprises ;
- il **modifie** le vhost `:80` d'origine en y ajoutant la redirection vers
  HTTPS (c'est l'effet de `--redirect`) ;
- il installe un `certbot.timer` qui renouvelle tout seul. Vérifiez-le avec
  `systemctl is-enabled certbot.timer` et `sudo certbot certificates`.

Conséquence pratique : après ce passage, votre fichier de vhost initial ne
décrit plus l'état réel du serveur. Toute modification ultérieure doit être
portée dans **les deux** fichiers.

---

## 7. Worker Messenger

Voir [`worker_systemd.md`](worker_systemd.md). À installer avant le premier
déploiement : `deploy-prod` arrête et relance l'unité, et devient un no-op
silencieux si elle n'existe pas.

Si le compte de déploiement n'est pas root, `systemctl start|stop` demandera un
mot de passe à chaque déploiement. Une règle sudoers limitée à cette unique
unité évite d'ouvrir plus large :

```
# /etc/sudoers.d/<projet>-worker
<deployer> ALL=(root) NOPASSWD: /usr/bin/systemctl start <projet>-worker, /usr/bin/systemctl stop <projet>-worker, /usr/bin/systemctl restart <projet>-worker
```

Attention au chemin : `sudo` résout la commande en `/usr/bin/systemctl` et
compare littéralement. Une règle écrite sur `/bin/systemctl` ne s'applique
jamais, même si `/bin` est un lien vers `/usr/bin`.

---

## 8. Vérification

Après le premier `make install-prod` :

```bash
make deploy-check
```

Boot en prod, migrations, worker, transport `failed` et réponse HTTP sur
`DEFAULT_URI`, une ligne par contrôle.
