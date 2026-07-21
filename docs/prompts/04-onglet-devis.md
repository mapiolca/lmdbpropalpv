# Prompt 04 — Onglet du devis

Lire intégralement `AGENT.md`. Travailler uniquement dans la racine actuelle `lmdbpropalpv/`, sans créer `lmdbpropalpv/lmdbpropalpv/` et sans modifier Dolibarr, JPSUN, PowerPlantPV ou un autre module. Préserver les modifications existantes. Cibler Dolibarr v20+, PHP 8.0+, MySQL/MariaDB, Multicompany, FR/EN et PHPStan. Utiliser les mécanismes natifs Dolibarr et exécuter les tests ciblés avant le compte rendu au format imposé par `AGENT.md`.

Réaliser l’onglet « Étude financière PV » avec entête de devis natif, puissance-crête PowerPlantPV en lecture seule, formulaire sécurisé, sélecteurs natifs, datepicker, validation, état de complétude, indicateurs, graphique et tableau annuel. Verrouiller l’édition hors brouillon.

Le rechargement de barème doit rester une action POST explicite et ne jamais être déclenché par un simple affichage.
