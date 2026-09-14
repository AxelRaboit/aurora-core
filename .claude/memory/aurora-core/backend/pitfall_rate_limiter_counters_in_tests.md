# Piège : les compteurs de limitation de débit survivent aux runs de test

**Règle.** Un test d'intégration qui frappe une route limitée remet le
compteur à zéro dans son `setUp()`, via le trait
`Aurora\Tests\Integration\Concern\ResetsRateLimiters` :

```php
use ResetsRateLimiters;

protected function setUp(): void
{
    parent::setUp();
    $this->client = static::createClient();
    $this->resetRateLimiter('form_submission');
}
```

**Pourquoi.** Les limiteurs écrivent dans `cache.rate_limiter`, un pool
filesystem : les compteurs vivent plus longtemps que le processus. Un fichier
de test qui poste trois fois dépense donc un budget horaire **partagé**, et
devient rouge au bout de quelques exécutions.

Mesuré le 14/09/2026 sur `FormCaptchaTest` : vert aux deux premières
exécutions consécutives, rouge à partir de la troisième. Après le reset, six
exécutions d'affilée sans un échec.

**Ce qui rend le diagnostic pénible.**

1. L'échec est un **429 sur une route que le test ne teste pas**. Rien dans le
   message ne parle de limitation : une soumission refusée n'est pas stockée,
   donc ce sont les assertions d'après qui tombent, sur des motifs sans rapport
   (« aucun mail envoyé », « la donnée est vide »).
2. Le budget étant **partagé par adresse**, un test voisin qui fait le reset
   masque le problème en suite complète pendant que le fichier seul reste
   rouge. C'était le cas ici : `FormSubmissionNotificationTest` rendait son
   budget à `FormCaptchaTest` par accident.
3. `rm -rf var/cache/test/pools` ne suffit pas à le reproduire ou à le
   corriger de façon fiable. Le reset explicite, si.

**Limiteurs existants** : `form_submission` (10/h), `contract_signature`
(10/h), `contract_signature_code` (15/h), `deck_share_password` (20/h). Seuls
les tests de formulaire dépassaient ; les autres frappent leur route trop
rarement, vérifié à quatre exécutions d'affilée.

Voir [[pitfall_rate_limiter_client_config]] pour l'autre bout du sujet : un
limiteur non déclaré côté client.
