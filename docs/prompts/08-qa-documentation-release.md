# Prompt 08 — QA, documentation et release

Lire intégralement `AGENT.md`. Travailler uniquement dans la racine actuelle `lmdbpropalpv/`, sans créer `lmdbpropalpv/lmdbpropalpv/` et sans modifier Dolibarr, JPSUN, PowerPlantPV ou un autre module. Préserver les modifications existantes. Cibler Dolibarr v20+, PHP 8.0+, MySQL/MariaDB, Multicompany, FR/EN et PHPStan. Utiliser les mécanismes natifs Dolibarr et exécuter les tests ciblés avant le compte rendu au format imposé par `AGENT.md`.

Exécuter PHP lint, PHPStan, tests unitaires et intégration, activation/désactivation/réactivation, puis générer et rendre visuellement les deux PDF sur des jeux complets. Vérifier les deux dégradations, la pondération multi-produits, les replis, les graduations et repères d’amortissement des graphiques, ainsi que l’affichage monétaire avec plusieurs réglages de décimales totales. Finaliser README, cahier des charges, `ChangeLog.md` 1.0.0, onglet À propos et matrice de tests manuels.

Le compte rendu doit distinguer ce qui a été réellement exécuté de ce qui reste à valider sur une instance Dolibarr déployée. Ne déclarer aucun test navigateur ou PDF réussi sans preuve du code servi.
