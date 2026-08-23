# PHPAML Framework

Le moteur MVC de PHPAML, inspiré d’ASP.NET et de Java EE.

Ce dépôt contient uniquement le code interne du framework. Les développeurs
créent leurs applications avec la commande `aml create` et ne modifient pas
directement ce paquet.

Fonctionnalités principales : routage dynamique, objets HTTP, injection de
dépendances, middlewares, vues, validation, sécurité CSRF, sessions, PDO et
migrations.

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
