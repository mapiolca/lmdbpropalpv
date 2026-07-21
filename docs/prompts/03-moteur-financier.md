# Prompt 03 — Moteur financier

Lire intégralement `AGENT.md`. Travailler uniquement dans la racine actuelle `lmdbpropalpv/`, sans créer `lmdbpropalpv/lmdbpropalpv/` et sans modifier Dolibarr, JPSUN, PowerPlantPV ou un autre module. Préserver les modifications existantes. Cibler Dolibarr v20+, PHP 8.0+, MySQL/MariaDB, Multicompany, FR/EN et PHPStan. Utiliser les mécanismes natifs Dolibarr et exécuter les tests ciblés avant le compte rendu au format imposé par `AGENT.md`.

Implémenter les objets d’entrée/résultat et le moteur financier pur sur 20 ans. Distinguer la dégradation de première année de la dégradation annuelle à partir de l’année 2, selon les formules du cahier des charges. Reproduire les autres hypothèses du classeur en corrigeant l’année zéro, les tranches tarifaires et les erreurs de formule. Ajouter gain cumulé, ROI, rendement moyen, temps de retour interpolé et coût simplifié. Écrire les tests unitaires avant tout rendu.

Utiliser le cas de référence du cahier des charges comme test doré avec des tolérances explicites. Ne pratiquer aucun arrondi intermédiaire.
