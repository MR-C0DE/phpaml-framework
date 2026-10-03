# Changelog

## 0.3.0-beta.6 — 2026-10-03

- ajoute `PHPAML\Console` pour afficher des valeurs structurées dans le terminal
  de développement sans contaminer les réponses HTTP ;
- redirige les `echo` applicatifs vers `aml serve`, avec masquage des secrets,
  neutralisation des caractères de contrôle et limites anti-inondation ;
- ajoute des pages d’erreur HTML personnalisables, des références de diagnostic
  en production et des détails complets uniquement en mode debug ;
- conserve les en-têtes HTTP des erreurs, retire `X-Powered-By` et renforce la
  couverture de sécurité des sorties hostiles ;
- autorise les attributs de style nécessaires aux modificateurs AML View tout en
  maintenant les scripts inline sous nonce CSP.

## 0.3.0-beta.5 — 2026-09-26

- adopte `src/views` comme emplacement par défaut des vues applicatives ;
- découvre en priorité les routes dans `src/routes` tout en conservant
  `routes/` comme compatibilité pour les anciens projets ;
- ajoute les non-régressions de configuration et de découverte associées à la
  structure unifiée.

## 0.3.0-beta.4 — 2026-09-12

- isole les réponses idempotentes par propriétaire authentifié et refuse tout
  rejeu privé avant validation du jeton ;
- rend atomiques les créations, rotations et révocations concurrentes des
  jetons API ;
- conserve la protection CSRF des pages web tout en exemptant correctement le
  préfixe API des applications mixtes ;
- ajoute des tests multiprocessus pour les jetons et des régressions dédiées à
  l’idempotence entre utilisateurs.

## 0.3.0-beta.3 — 2026-08-26

- ajoute un point de composition générique `bootstrappers` pour enregistrer
  les modules optionnels sans imposer leur dépendance au Framework ;
- conserve le branchement Data historique comme compatibilité temporaire pour
  les projets créés par les bêtas précédentes ;
- ajoute la détection automatique des ruptures de l’API publique dans la CI.

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
