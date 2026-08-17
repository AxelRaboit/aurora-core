# Prérequis — checklist Aurora

Inventaire **complet** de ce qu'il faut avoir pour qu'un Aurora (core ou
client) tourne *intégralement*, sur ta machine de dev comme sur un serveur.
Lis ce fichier avant d'ouvrir un ticket "ça marche pas".

Les modules dont une dépendance manque se **dégradent proprement** : ils
n'empêchent pas le boot du framework ; ils renvoient une erreur métier
parlante au premier usage. Tu n'es donc pas obligé de tout installer pour
démarrer — seulement ce que les modules que tu actives demandent.

---

## 1. Système (obligatoire)

| Outil | Version | Vérifier | Installer (Ubuntu/Debian) |
|-------|---------|----------|---------------------------|
| **PHP CLI + FPM** | `>= 8.4` | `php --version` | `sudo apt install php8.4-cli php8.4-fpm` |
| **Composer** | `>= 2.5` | `composer --version` | [getcomposer.org](https://getcomposer.org) |
| **Node.js + npm** | `>= 18` | `node --version` | [nodejs.org](https://nodejs.org) ou `nvm` |
| **PostgreSQL** | `>= 14` (testé sur 18) | `psql --version` | `sudo apt install postgresql` |
| **Make** | n'importe | `make --version` | `sudo apt install build-essential` |
| **Git** | `>= 2.30` | `git --version` | `sudo apt install git` |

### Extensions PHP requises

Le `composer.json` impose `ext-ctype` et `ext-iconv`. En plus Symfony 7
+ Aurora utilisent en pratique :

```
pdo_pgsql intl mbstring xml curl zip gd opcache
```

Vérifie d'un coup :

```bash
php -m | grep -iE "pdo_pgsql|intl|mbstring|xml|curl|zip|gd|opcache|ctype|iconv"
```

> Doit lister 10 lignes. Manquant ? `sudo apt install php8.4-pgsql php8.4-intl php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip php8.4-gd`

---

## 2. Binaires CLI optionnels (par module)

Aurora-core dégrade les modules dont l'outil est absent — tu ne casses
rien en les omettant.

| Binaire | Module | Dégradation si absent | Install |
|---------|--------|-----------------------|---------|
| `pdftoppm` (poppler-utils) | GED — aperçus PDF | Recours auto à `gs` (qualité moindre) ; si ni l'un ni l'autre n'est présent, fallback sur l'icône | `sudo apt install poppler-utils` |
| `gs` (Ghostscript) | GED — aperçus PDF (fallback) | Même chose que ci-dessus quand `pdftoppm` est aussi absent | `sudo apt install ghostscript` |

> **GED PDF thumbnails** : `PdfThumbnailGenerator` essaie d'abord `pdftoppm`,
> puis `gs`, puis renvoie `null` (icône fallback côté Vue). Pour
> re-générer les aperçus après une install :
> `php bin/console aurora:ged:thumbnails:generate --force`.

---

## 3. Services externes

| Service | Port par défaut | Modules concernés | Lancement |
|---------|----------------|-------------------|-----------|
| **PostgreSQL** | 5432 | Tous | `sudo systemctl start postgresql` |
| **SMTP** (Mailpit / Mailhog en dev) | 1025 | Mailer | `docker run -p 1025:1025 -p 8025:8025 axllent/mailpit` |

Les transports Symfony Messenger sont en `doctrine://default` par défaut
— **aucun broker externe** (RabbitMQ/Redis) requis pour faire tourner
Aurora tel quel.

---

## 4. Variables d'environnement

Les défauts sains vivent dans :
- **aurora-core** : `.env` (versionné) + `.env.local` (gitignored, perso)
- **aurora-client** : `.env` (versionné, défauts) + `.env.local.example`
  à copier en `.env.local` au setup initial

Les blocs à connaître (regroupés par `###> aurora/<truc> ###` markers) :

| Bloc | Vars | Régénérer la clé |
|------|------|------------------|
| `aurora/encryption` | `AURORA_ENCRYPTION_KEY` | `php -r "echo base64_encode(random_bytes(32));"` |
| `aurora/mount-point` | `AURORA_MOUNT_POINT_KEY` | idem |
| `doctrine/doctrine-bundle` | `DATABASE_URL` | adapter aux credentials locaux |
| `symfony/mailer` | `MAILER_DSN`, `MAILER_FROM`, `ADMIN_EMAIL` | DSN `smtp://localhost:1025` en dev |

⚠ **Les clés de chiffrement ne doivent PAS rester sur leurs valeurs
placeholder** (`replace_with_base64_32_bytes_key`) — `EncryptedTextType`
plantera silencieusement au déchiffrement, et tout champ chiffré déjà écrit
devient illisible.

`AURORA_MOUNT_POINT_KEY` est encore dans `.env` alors que le module MountPoint
a été extrait puis archivé : c'est un reliquat. Le retirer touche les `.env.local`
des projets consommateurs, donc il attend une décision plutôt qu'un nettoyage
silencieux.

---

## 5. Production — spécificités

Au-delà du dev :

- **Apache `mod_xsendfile`** : indispensable côté prod pour servir les
  fichiers de `var/uploads/` sans saturer PHP-FPM. Voir
  [apache_xsendfile.md](../../aurora-client/deployment/apache_xsendfile.md).
- **Droits du dossier `var/uploads/`** : l'utilisateur du web (`www-data`)
  doit avoir l'écriture sous `var/uploads/` et la lecture sur tout le
  contenu déjà uploadé.
- **Cron / scheduler** : Symfony Messenger doit tourner en worker
  (`bin/console messenger:consume async -vv`) sous systemd ou supervisor.
- **PostgreSQL** : sequences `seq_core_*_id` créées par les migrations.
  Si tu fais un dump → restore, restore avec `--no-owner --no-acl` puis
  rejoue les migrations (`doctrine:migrations:migrate`).

---

## 6. Vérification rapide

Un one-liner pour valider qu'un environnement a tout en place :

```bash
php --version | head -1 && \
node --version && \
psql --version && \
composer --version | head -1
```

Sortie attendue :
- PHP 8.4.x
- v20+ ou v22+ pour Node
- psql 14+
- Composer 2.x

---

## 7. Quand ajouter ici ?

Toute **nouvelle dépendance** (binaire CLI, service externe, modèle IA,
var d'env critique) ajoutée à un module Aurora **doit** être listée ici
dans le même PR. C'est l'unique source-of-truth — si tu ajoutes le
support d'un nouveau modèle ou d'une nouvelle commande système sans
toucher ce fichier, un onboarding va péter en silence.
