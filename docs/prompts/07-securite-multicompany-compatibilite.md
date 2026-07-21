# Prompt 07 — Sécurité, Multicompany et compatibilité

Lire intégralement `AGENT.md`. Travailler uniquement dans la racine actuelle `lmdbpropalpv/`, sans créer `lmdbpropalpv/lmdbpropalpv/` et sans modifier Dolibarr, JPSUN, PowerPlantPV ou un autre module. Préserver les modifications existantes. Cibler Dolibarr v20+, PHP 8.0+, MySQL/MariaDB, Multicompany, FR/EN et PHPStan. Utiliser les mécanismes natifs Dolibarr et exécuter les tests ciblés avant le compte rendu au format imposé par `AGENT.md`.

Auditer droits, CSRF, entités, devis partagés, devise, stockage documentaire et dépendance PowerPlantPV. Contrôler sans erreur fatale la présence de `pmax`, `first_year_degradation` et `annual_degradation` dans PowerPlantPV 1.3.0 ; utiliser les défauts d’entité si le schéma est indisponible. Ajouter les gardes v20+ et la page Compatibilité. Vérifier que le hook `propalcard` ne travaille que lors de la génération d’un des deux modèles.

Tester administrateurs sans droits granulaires, utilisateurs standards, utilisateur externe et devis appartenant à une autre entité accessible par partage.
