# Prompt 01 — Schéma et barèmes

Lire intégralement `AGENT.md`. Travailler uniquement dans la racine actuelle `lmdbpropalpv/`, sans créer `lmdbpropalpv/lmdbpropalpv/` et sans modifier Dolibarr, JPSUN, PowerPlantPV ou un autre module. Préserver les modifications existantes. Cibler Dolibarr v20+, PHP 8.0+, MySQL/MariaDB, Multicompany, FR/EN et PHPStan. Utiliser les mécanismes natifs Dolibarr et exécuter les tests ciblés avant le compte rendu au format imposé par `AGENT.md`.

Implémenter les objets et tables normalisées des barèmes, leurs index et migrations idempotentes. Importer l’historique officiel complet depuis 2021 jusqu’à la release à partir des sources CRE/Légifrance, conserver URL, date et empreinte de source, détecter les trous ou chevauchements et ne prévoir aucune synchronisation réseau à l’exécution.

Conserver les deux cas officiels de mai–juillet 2022, les tranches réelles au-delà de 9 kWc et les valeurs ouvertes de la dernière période. Archiver sans supprimer. Tester deux entités et une réactivation.
