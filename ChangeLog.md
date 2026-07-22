# ChangeLog

## 1.0.0 - 2026-07-22

Première version publique du module « Propositions commerciales PV ».

### Étude financière

- Étude de rentabilité photovoltaïque sur une période administrable de 1 à 50 ans, avec projection de la production, des économies, des ventes de surplus, des gains, de la trésorerie, du rendement, du ROI, du temps de retour et du coût de production simplifié.
- Comparaison facultative des scénarios sans et avec batterie, avec taux d’autoconsommation distincts et investissement batterie saisi librement ou issu d’un devis compatible.
- Hypothèses propres à chaque proposition commerciale conservées sous forme de snapshots, avec rechargement explicite des données tarifaires, des caractéristiques des panneaux et du devis batterie.
- Dégradations des panneaux proposées depuis PowerPlantPV par moyenne pondérée sur la puissance, avec valeurs distinctes pour la première année et les années suivantes.
- Contrôle indicatif du raccordement à partir de la puissance-crête et des caractéristiques AC des onduleurs, avec Pmax, puissance souscrite suggérée et alertes non bloquantes.

### Tarifs et intégration Dolibarr

- Historique embarqué des tarifs S21 et TRVE depuis 2021, avec prise en charge des puissances du Tarif Bleu et du Tarif Jaune de 37 à 250 kVA.
- Intégration native aux propositions commerciales par un onglet dédié et des extrafields masqués.
- Réglages, barèmes et modèles configurables par entité, droits granulaires et compatibilité Multicompany.
- Compatibilité Dolibarr 20+, PHP 8.0+ et PowerPlantPV 1.3.0+.

### Interface et documents

- Courbes superposées, temps de retour distincts, projection annuelle et synthèse comparatives avec légende et couleurs indépendantes pour les scénarios sans et avec batterie.
- Deux modèles PDF natifs, « PV Signature illustré » et « PV Signature épuré », intégrant l’étude financière, les photos produits facultatives et la signature en ligne par QR code.
- Documents PDF multipages avec projection dynamique jusqu’à 50 ans et composition avec le corps commercial et ses annexes.

### Documentation et validation

- Documentation fonctionnelle, cahier des charges, sources tarifaires, prompts de réalisation et matrice de recette manuelle inclus.
- Tests du moteur financier couvrant les horizons de 1 à 50 ans, les bornes invalides et les scénarios sans et avec batterie.
