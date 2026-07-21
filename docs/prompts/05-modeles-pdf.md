# Prompt 05 — Modèles PDF

Lire intégralement `AGENT.md`. Travailler uniquement dans la racine actuelle `lmdbpropalpv/`, sans créer `lmdbpropalpv/lmdbpropalpv/` et sans modifier Dolibarr, JPSUN, PowerPlantPV ou un autre module. Préserver les modifications existantes. Cibler Dolibarr v20+, PHP 8.0+, MySQL/MariaDB, Multicompany, FR/EN et PHPStan. Utiliser les mécanismes natifs Dolibarr et exécuter les tests ciblés avant le compte rendu au format imposé par `AGENT.md`.

Construire un renderer PDF partagé et les modèles « PV Signature illustré » et « PV Signature épuré ». Reprendre toutes les capacités commerciales du modèle Dolibarr comparable, ajouter le design, les photos produits, l’étude conditionnelle avec les deux taux de dégradation, la signature en ligne native et le QR code. La courbe financière affiche des graduations lisibles et les repères horizontal/vertical du point d’amortissement. Tous les montants suivent les décimales totales Dolibarr via `price2num(..., 'MT')` et `price()`. Tester les pieds de page, documents multipages, accents et absence de photo.

Mesurer la zone du pied avant le contenu. Utiliser le répertoire de l’entité propriétaire du devis. Ne créer aucun stockage d’image parallèle.
