# ChangeLog

## 1.0.0 - 2026-07-21

- Première version de l’étude financière photovoltaïque sur 20 ans.
- Moteur pur sans arrondi intermédiaire, temps de retour interpolé, ROI, rendement moyen et coût simplifié.
- Dégradations distinctes en première année et à partir de l’année 2, proposées depuis les modules PowerPlantPV par moyenne pondérée sur la puissance et enregistrées comme snapshots rechargeables.
- Montants, durées et pourcentages normalisés selon les décimales totales Dolibarr, avec graphiques enrichis des graduations et repères d’amortissement.
- États complet, incomplet et consultatif affichés avec les badges natifs Dolibarr.
- Historique officiel embarqué des tarifs S21 et TRVE depuis 2021, avec sources et empreintes SHA-256.
- Onglet natif sur les propositions commerciales, snapshots tarifaires et rechargement explicite.
- Modèles PDF « PV Signature illustré » et « PV Signature épuré », photos produits natives et signature en ligne par QR code.
- Première activation conservatrice, réglages par entité, droits granulaires et compatibilité Multicompany.
- Correction du chargement de la bibliothèque native des propositions commerciales sur Dolibarr v20+.
- Sécurisation de la mesure et du rendu des pieds de page TCPDF afin d’éviter les pages vides de pied lors de la génération du supplément PDF.
- Documentation fonctionnelle, paquet de prompts et matrice de tests manuels.
