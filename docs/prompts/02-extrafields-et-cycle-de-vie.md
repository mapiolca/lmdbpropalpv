# Prompt 02 — Extrafields et cycle de vie

Lire intégralement `AGENT.md`. Travailler uniquement dans la racine actuelle `lmdbpropalpv/`, sans créer `lmdbpropalpv/lmdbpropalpv/` et sans modifier Dolibarr, JPSUN, PowerPlantPV ou un autre module. Préserver les modifications existantes. Cibler Dolibarr v20+, PHP 8.0+, MySQL/MariaDB, Multicompany, FR/EN et PHPStan. Utiliser les mécanismes natifs Dolibarr et exécuter les tests ciblés avant le compte rendu au format imposé par `AGENT.md`.

Créer les extrafields `propal` définis dans le cahier des charges, les valeurs initiales par entité et leur persistance native. Ne jamais supprimer ces champs ou leurs données à la désactivation. Garantir copie native des devis, snapshot des valeurs et rechargement explicite d’un barème.

Masquer les extrafields de la fiche et des listes standard. Vérifier les formats date, devise et décimaux ainsi que la conservation après désactivation/réactivation.
