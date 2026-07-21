# Prompt 00 — Squelette du module

Lire intégralement `AGENT.md`. Travailler uniquement dans la racine actuelle `lmdbpropalpv/`, sans créer `lmdbpropalpv/lmdbpropalpv/` et sans modifier Dolibarr, JPSUN, PowerPlantPV ou un autre module. Préserver les modifications existantes. Cibler Dolibarr v20+, PHP 8.0+, MySQL/MariaDB, Multicompany, FR/EN et PHPStan. Utiliser les mécanismes natifs Dolibarr et exécuter les tests ciblés avant le compte rendu au format imposé par `AGENT.md`.

Créer le squelette complet du module `LmdbPropalPV` 1.0.0 avec l’ID 450010, les dépendances, permissions, onglet externe de devis, réglages internes, compatibilité centralisée, traductions, documentation racine et aucun menu haut. Ne pas créer de code métier factice.

Vérifier notamment l’unicité de l’ID, la famille exacte « Les Métiers du Bâtiment », les permissions `$this->numero * 100 + $r`, `config_page_url`, les dépendances `modPropale` et `modPowerPlantPV`, ainsi que la présence de `modulebuilder.txt` et `ChangeLog.md`.
