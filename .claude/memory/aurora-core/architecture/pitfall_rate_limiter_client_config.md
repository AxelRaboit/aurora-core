# Piège : un limiteur câblé par nom oblige chaque client à le déclarer

**Règle.** Quand un contrôleur d'aurora-core prend un `RateLimiterFactoryInterface`
en argument nommé (`$monSujetLimiter` pour le limiteur `mon_sujet`), l'entrée
correspondante doit être ajoutée **à la main dans `config/packages/rate_limiter.yaml`
de chaque projet client**, et la section `### Dans aurora-client` du CHANGELOG
doit le dire.

**Pourquoi.** `config/packages/rate_limiter.yaml` d'aurora-core est la config de
*son* application de développement : elle n'est pas distribuée par composer. Le
bundle ne préfixe rien pour `framework.rate_limiter`. Sans l'entrée, le client
ne démarre pas du tout :

```
Cannot autowire service "…\PublicDeckController": argument
"$deckSharePasswordLimiter" … references interface
"RateLimiterFactoryInterface" but no such service exists. Did you mean to
target one of "form_submission", "contract_signature", …
```

L'erreur tombe pendant `make aurora-update`, c'est-à-dire **après** que le tag
de core soit publié : on ne peut plus l'éviter, seulement la réparer côté
client. Constaté le 13/09/2026 en livrant la 0.9.166 (`deck_share_password`).

**Comment l'appliquer.**

1. En écrivant le contrôleur, ajouter l'entrée dans les deux dépôts au lieu d'un
   seul, et l'annoncer dans `### Dans aurora-client`.
2. Le commentaire au-dessus de l'entrée, côté client, dit qui la câble et que le
   conteneur ne se construit pas sans elle. Les deux limiteurs de contrat en
   portent déjà un : le copier.
3. Alternative écartée : rendre l'argument facultatif. Un limiteur absent qui se
   dégrade en « pas de limite » retire une protection sans que personne le voie,
   là où l'erreur de conteneur est bruyante et se répare en trois lignes.

Voir [[process_release]] pour la chaîne, et la mémoire utilisateur
`app-axelraboit-deployment` pour le VPS.
