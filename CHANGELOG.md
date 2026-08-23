# Changelog

## 0.3.0-beta.2 — 2026-08-23

- adopte la licence MIT et déclare cette licence dans Composer et le README.

## 0.3.0-beta.1 — 2026-08-21

- charge `phpaml.json` et `.env`, puis génère le cache privé
  `runtime/config/app.php` ;
- découvre automatiquement les classes de routes dans `routes/` et
  `src/routes/` ;
- ajoute la DSL `Route` et les ressources REST ;
- normalise les configurations API et Data déclaratives ;
- n’applique plus le CSRF de formulaire aux applications API pures, tout en le
  conservant pour les applications web et AML View ;
- maintient le chargement des anciens fichiers `config/app.php` et
  `configs/app.php`.

## 0.2.1-beta.1 — 2026-08-18

- pipeline HTTP compatible avec une destination AML View personnalisée ;
- contexte CSP par requête et nonce transmis sans analyser le HTML ;
- en-têtes de sécurité conservés sur les réponses CSRF et Rate Limit ;
- jeton CSRF exposé et renouvelé pour AML Engine ;
- initialisation des vues MVC facultative pour les applications AML View ;
- masquage renforcé des secrets et validation plus stricte des contrôleurs.
