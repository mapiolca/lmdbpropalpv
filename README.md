# LmdbPropalPV

Module externe Dolibarr ajoutant une étude de retour sur investissement photovoltaïque aux propositions commerciales et deux modèles PDF modernes prêts à signer.

Version `1.0.0` — identifiant Dolibarr `450010` — famille « Les Métiers du Bâtiment ».

## Compatibilité

- Dolibarr 20 ou supérieur
- PHP 8.0 ou supérieur
- MySQL/MariaDB
- PowerPlantPV 1.3.0 ou supérieur
- Module Propositions commerciales actif
- TCPDI natif Dolibarr disponible et non désactivé pour assembler les modèles PDF

## Fonctionnalités V1

- puissance-crête lue depuis PowerPlantPV ;
- dégradations de première année et des années suivantes proposées depuis les modules PowerPlantPV, pondérées par puissance puis figées dans le devis ;
- hypothèses propres à chaque devis stockées en extrafields ;
- projection pure sur 20 ans, sans persistance des lignes calculées ;
- graphiques avec graduations et repères du point d’amortissement ;
- barèmes officiels S21 et TRVE embarqués depuis 2021, sans accès Internet à l’exécution ;
- deux modèles PDF natifs, avec ou sans photos produits ;
- réglages et barèmes isolés par entité ;
- conservation des choix administrateur lors des réactivations.

## Installation et première activation

1. Placer directement ce répertoire sous la racine des modules externes Dolibarr, sans ajouter un second dossier `lmdbpropalpv`.
2. Activer Propositions commerciales et PowerPlantPV 1.3.0 ou supérieur.
3. Activer LmdbPropalPV depuis la liste des modules.

Pour chaque entité, la première activation ajoute et active « PV Signature illustré » et « PV Signature épuré », puis sélectionne la variante illustrée comme modèle de proposition par défaut. Le marqueur `LMDBPROPALPV_INITIAL_PDF_SETUP_DONE` est ensuite enregistré. Une désactivation/réactivation ultérieure conserve les modèles actifs, le modèle par défaut et tous les réglages administrateur.

## Utilisation

L’étude est accessible depuis l’onglet « Étude financière PV » d’une proposition commerciale. La puissance-crête est lue exclusivement depuis PowerPlantPV ; la production annuelle reste une saisie obligatoire. Les hypothèses et le barème chargé sont enregistrés comme un instantané du devis.

Les boutons « Recharger le barème applicable » et « Recharger les caractéristiques panneaux » sont les seules actions qui remplacent explicitement leurs snapshots respectifs. Une mise à jour du module, des barèmes ou des fiches produits ne modifie jamais un devis existant.

Les montants, durées et pourcentages affichés dans l’onglet et les PDF respectent le réglage Dolibarr du nombre maximal de décimales des prix totaux. Les prix au kWh et par kWc conservent la précision configurée pour les prix unitaires.

Les réglages internes permettent de définir les hypothèses par défaut, les couleurs PDF, l’avertissement financier et les barèmes propres à l’entité. Les modèles actifs et le modèle de devis par défaut restent administrés par la page native des propositions commerciales.

## Données et documentation

- cahier des charges : [`docs/Cahier_des_charges_V1.md`](docs/Cahier_des_charges_V1.md) ;
- sources et empreintes des barèmes : [`docs/sources_tarifaires.md`](docs/sources_tarifaires.md) ;
- matrice de recette : [`docs/Matrice_tests_manuels.md`](docs/Matrice_tests_manuels.md) ;
- prompts séquentiels : [`docs/prompts/`](docs/prompts/).

La méthode de calcul est une estimation commerciale non contractuelle. La V1 exclut notamment PVGIS, financement, maintenance, fiscalité, VAN, TRI, batteries, ZNI, vente totale et synchronisation réseau des tarifs.

## Contrôles développeur

```bash
php test/run_financial_tests.php
find . -type f -name '*.php' -print0 | xargs -0 -n1 php -l
```

Les scénarios d’activation, de droits, Multicompany et de rendu PDF doivent être rejoués sur une instance Dolibarr disposant d’une base configurée.
