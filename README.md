# PHPAML Framework

Le moteur MVC de PHPAML, inspiré d’ASP.NET et de Java EE.

Ce dépôt contient uniquement le code interne du framework. Les développeurs
créent leurs applications avec la commande `aml create` et ne modifient pas
directement ce paquet.

Fonctionnalités principales : routage dynamique, objets HTTP, injection de
dépendances, middlewares, vues, validation, sécurité CSRF, sessions, PDO et
migrations.

## Durée de vie des services

Le conteneur distingue trois durées de vie :

- `bind()` construit un service transitoire à chaque résolution ;
- `singleton()` conserve une instance pour toute la durée de l’application ;
- `scoped()` conserve une instance uniquement pendant la requête HTTP active.

```php
$application->container()->scoped(
    CurrentWorkspace::class,
    static fn (\PHPAML\Container $container): CurrentWorkspace =>
        new CurrentWorkspace($container->get(\PHPAML\Http\Request::class)),
);
```

`WebApplication::handle()` ouvre et ferme toujours la portée, même lorsqu’une
exception est convertie en réponse d’erreur. La requête active est disponible
dans cette portée sous `PHPAML\Http\Request`. Résoudre un service scoped hors
d’une requête échoue explicitement afin d’éviter une fuite d’état entre deux
requêtes, notamment dans un serveur PHP persistant. Les objets `Session` et
`View` sont eux aussi créés dans cette portée. La détection HTTPS (cookies et
HSTS) dépend de la requête active, et non d’un état global partagé.

`Session` délègue ses données à `SessionStoreInterface`. Le stockage natif est
fourni par `NativeSessionStore` et se ferme automatiquement avec la portée. Un
serveur persistant peut donc fournir un stockage adapté sans modifier l’API de
session utilisée par l’application.

`RedisSessionStore` fournit ce stockage pour les workers persistants. Chaque
valeur est enregistrée dans un champ Redis distinct : deux requêtes modifiant
des clés différentes ne réécrivent donc jamais toute la session. L’adaptateur
accepte `ext-redis` ou un client compatible avec les opérations de hash Redis.

## Console de développement

`PHPAML\Console` affiche des informations dans le terminal où `aml serve` est
en cours d’exécution, sans ajouter ces informations à la réponse HTTP :

```php
use PHPAML\Console;

Console::log('Utilisateur chargé', ['id' => 42]);
Console::info('Synchronisation terminée');
Console::warning('Cache bientôt expiré');
Console::error('Paiement refusé', $exception);
```

Les tableaux et objets sont affichés en JSON lisible. Dans une application AML
View, `PHPAML\Console` vise le terminal serveur, tandis que
`AML\View\Console` crée une instruction destinée à la console du navigateur.
Un alias permet d’utiliser les deux dans le même fichier :

```php
use PHPAML\Console as ServerConsole;
use AML\View\Console as BrowserConsole;
```

Pendant `aml serve`, un `echo` exécuté par le code applicatif est également
redirigé vers ce terminal. PHPAML le capture afin qu’il ne corrompe jamais le
HTML ou le JSON de la réponse :

```php
echo "Commande reçue\n";
// Terminal : [PHPAML] [ECHO] Commande reçue
```

Dans un projet Console exécuté avec `aml run`, `echo` conserve naturellement
le comportement standard de PHP.

La console de développement n’est pas un stockage sécurisé : n’y affichez
jamais de mot de passe, jeton d’accès, cookie, clé privée ou donnée personnelle.
PHPAML masque les clés sensibles courantes dans les tableaux et neutralise les
séquences de contrôle du terminal, mais les autres valeurs restent visibles par
toute personne ayant accès au terminal du serveur.

## Vérification

Le moteur nécessite PHP 8.2 ou une version ultérieure avec PDO. Pour exécuter
sa suite de tests :

```bash
composer test
```

Les migrations sont exécutées dans l’ordre des noms de fichiers sous un verrou
exclusif. Chaque migration utilise une transaction lorsque le pilote le permet.
Certaines bases valident implicitement les instructions DDL : les méthodes
`up()` et `down()` doivent donc être sûres pour le pilote ciblé et une sauvegarde
est obligatoire avant la production. `Migrator::rollback()` annule d’abord la
dernière migration et s’arrête à la première erreur.

## Licence

PHPAML Framework est un logiciel open source distribué sous
[licence MIT](LICENSE).
