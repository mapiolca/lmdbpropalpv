# Cahier des charges V1 — Étude financière PV et devis commerciaux modernisés

## 1. Objet

Le module externe `lmdbpropalpv` complète les propositions commerciales Dolibarr avec une étude financière photovoltaïque sur 20 ans et deux modèles PDF commerciaux modernisés. Il ne remplace ni le module Propositions commerciales ni PowerPlantPV : il exploite le helper public de puissance-crête et lit en repli contrôlé la table technique normalisée des modules, sans modifier la dépendance.

Version : `1.0.0`. Identifiant : `450010`. Éditeur : Pierre Ardoin / Les Métiers du Bâtiment.

## 2. Socle et dépendances

- Dolibarr 20 ou supérieur ;
- PHP 8.0 ou supérieur ;
- MySQL/MariaDB ;
- module Propositions commerciales actif ;
- PowerPlantPV 1.3.0 ou supérieur ;
- français et anglais ;
- compatibilité Multicompany par isolation stricte des entités.

Aucun fichier du core, de PowerPlantPV, de JPSUN ou d’un autre module n’est modifié. Le module ne crée aucun menu haut. L’accès métier se fait par un onglet externe du devis.

## 3. Parcours utilisateur

### 3.1 Onglet du devis

L’onglet « Étude financière PV » affiche l’entête natif de la proposition, la puissance-crête en lecture seule et le total TTC dans la devise du devis.

La puissance-crête provient exclusivement de `powerplantpvGetObjectPeakPowerKwc($propal)`. Le module ne recalcule pas et ne duplique pas cette valeur.

Les hypothèses saisies sont :

- production annuelle initiale en kWh, obligatoire ;
- taux d’autoconsommation ;
- dégradation des panneaux la première année ;
- dégradation annuelle à partir de l’année 2 ;
- augmentation annuelle du prix de l’électricité ;
- date de référence tarifaire ;
- profil Base, Heures pleines ou Manuel ;
- puissance souscrite ;
- prix de l’électricité ;
- tarif de vente du surplus ;
- prime par kWc.

Les valeurs initiales sont 68 %, 0,45 % pour chacune des deux dégradations et 3 %. Elles sont configurables par entité. Sur un devis sans snapshot, les deux dégradations sont proposées depuis les produits MODULE de PowerPlantPV : moyenne pondérée par `quantité × pmax`, avec remplacement du seul taux absent ou invalide par le défaut de l’entité. Une valeur nulle est valide. En l’absence de module pondérable ou de schéma compatible, les deux défauts sont proposés avec un avertissement non bloquant.

Le premier enregistrement copie les deux taux dans le devis. Les modifications ultérieures des fiches produits n’ont aucun effet jusqu’à l’action POST explicite « Recharger les caractéristiques panneaux », réservée aux devis brouillons modifiables.

### 3.2 États

Le devis au brouillon est modifiable si l’utilisateur dispose des droits sur les propositions et du droit module `study/write`. Après validation, toutes les données sont consultatives.

Le rechargement d’un barème ou des caractéristiques panneaux est une action explicite. Une nouvelle release ou une modification administrative n’écrase jamais un devis existant.

### 3.3 Complétude

L’étude est complète lorsque la puissance-crête, le total TTC, la production, les pourcentages, le prix réseau, le barème de surplus et la date de référence sont valides. Les valeurs nulles de prime ou de tarif de vente restent autorisées lorsqu’elles sont prévues par le barème officiel.

Une étude incomplète liste les informations manquantes. La génération d’un modèle PV reste possible, mais les pages financières sont omises et un avertissement Dolibarr non bloquant est affiché.

La bannière reprend les informations natives de la fiche Proposition commerciale : référence client, tiers, lien vers les autres propositions et projet. Les états « Étude complète », « Étude incomplète » et « Lecture seule » sont affichés avec des badges Dolibarr natifs contenant leur libellé, dans un tableau `fichehalfright` aligné sur les lignes puissance-crête et investissement.

## 4. Calcul financier

Le moteur est pur : il ne lit ni la base, ni Dolibarr, ni le réseau et ne produit aucun rendu. Il retourne exactement 20 lignes calculées, jamais persistées.

Pour l’année `a`, de 1 à 20 :

```text
production année 1 = production_initiale × (1 - dégradation_première_année)
production année a, a ≥ 2 = production_initiale × (1 - dégradation_première_année) × (1 - dégradation_annuelle)^(a - 1)
prix_réseau = prix_initial × (1 + hausse)^(a - 1)
vente_surplus = production × (1 - autoconsommation) × tarif_vente
économie = production × autoconsommation × prix_réseau
prime = puissance_kWc × prime_kWc, uniquement en année 1
gain_annuel = vente_surplus + économie + prime
trésorerie_cumulée = -investissement + somme_des_gains
rendement_annuel = gain_annuel / investissement
```

Résultats : production totale, économies, ventes, prime, gains bruts, gain net, ROI à 20 ans, rendement annuel moyen, retour interpolé et coût de production simplifié.

Il n’existe aucun flux parasite en année zéro. Aucun arrondi intermédiaire n’est appliqué. Les prix unitaires sont normalisés avec `price2num(..., 'MU')`. Les montants, durées et pourcentages présentés sont normalisés avec `price2num(..., 'MT')` et affichés avec `price()` afin de suivre notamment le réglage « Nombre de décimales maximum pour les prix totaux ». Cette normalisation reste limitée à l’affichage et ne réduit pas la précision interne du moteur financier.

Cas de référence : 3 kWc, 3 456 kWh, 68 %, 0,45 % en première année, 0,45 % à partir de l’année 2, 3 %, 0,04 €/kWh, 0,2146 €/kWh, 80 €/kWc et 1 884,7575 € TTC. Le résultat attendu est un gain brut de 13 956,216903 €, un gain net de 12 071,459403 €, un retour de 2,944947 ans et un coût simplifié de 0,024941238 €/kWh.

## 5. Stockage

Les hypothèses sont stockées dans 12 extrafields `propal`, invisibles sur la fiche principale :

- `lmdbpropalpv_annual_production_kwh` ;
- `lmdbpropalpv_self_consumption_pct` ;
- `lmdbpropalpv_first_year_degradation_pct` ;
- `lmdbpropalpv_panel_degradation_pct` ;
- `lmdbpropalpv_electricity_growth_pct` ;
- `lmdbpropalpv_tariff_reference_date` ;
- `lmdbpropalpv_retail_tariff_mode` ;
- `lmdbpropalpv_retail_subscription_kva` ;
- `lmdbpropalpv_retail_price_per_kwh` ;
- `lmdbpropalpv_feed_in_price_per_kwh` ;
- `lmdbpropalpv_premium_per_kwp` ;
- `lmdbpropalpv_tariff_set_id`.

Les barèmes utilisent deux tables normalisées. Le parent porte l’entité, la période, la devise, la source, l’empreinte, le statut et l’audit. Les règles portent la métrique, l’option, la puissance souscrite, les bornes de kWc, la valeur et l’unité. Une règle hérite de l’entité par la jointure obligatoire au parent.

Un barème utilisé est archivé, pas supprimé. Aucune cascade SQL ne pilote une règle métier.

## 6. Historique tarifaire

L’historique embarqué couvre toutes les périodes disponibles depuis 2021 jusqu’au 21 juillet 2026 :

- S21 : tarif de vente du surplus et prime par tranche de puissance ;
- TRVE Base : composante énergie TTC selon la puissance souscrite ;
- TRVE Heures pleines : composante énergie TTC selon la puissance souscrite.

La plage S21 distingue correctement `0–9 kWc` et `9–100 kWc`, ainsi que les quatre tranches de prime. Depuis le 5 juin 2026, le fichier CRE indique 0 € de prime et 0,011 €/kWh pour les deux tranches de surplus ; ces valeurs nulles sont légitimes.

L’installation est idempotente. Les périodes d’une release ultérieure s’ajoutent sans réécrire les lignes existantes. Aucun appel Internet n’est exécuté en production.

## 7. PDF

Les modèles natifs `propal` sont :

- `lmdbpropalpv_withpictures` — PV Signature illustré ;
- `lmdbpropalpv_withoutpictures` — PV Signature épuré.

Les deux classes publiques sont minces et partagent un renderer. Le corps commercial s’appuie sur le modèle Cyan de la version Dolibarr installée afin de conserver TVA, remises, multicurrency, notes, conditions, hooks et photos produits. Le renderer ajoute une couverture moderne et, si l’étude est complète, une page financière avec indicateurs, courbe et tableau des 20 années. La courbe comporte des graduations d’années et de trésorerie, ainsi que les repères horizontal et vertical du point d’amortissement. Le même repère est affiché dans l’onglet du devis.

La variante illustrée active uniquement la mécanique native de photos produits. Elle ne crée aucun stockage parallèle. La variante épurée désactive la colonne image.

La couverture affiche le logo de l’entité, le client, la référence, la date, le total TTC et les indicateurs PV. Deux couleurs sont configurables par entité : bleu nuit `#16324F` et solaire `#F2B705`.

Si `PROPOSAL_ALLOW_ONLINESIGN` est actif et le devis validé, l’URL est construite par `getOnlineSignatureUrl()` et encodée dans un QR code natif TCPDF. Sinon, une zone « Bon pour accord » est affichée.

Les pages ajoutées calculent leur marge basse à partir de la hauteur du texte libre et des détails société avant tout contenu, puis appellent le pied natif Dolibarr page par page.

## 8. Première activation et réactivation

Lors de la première activation d’une entité :

1. les deux modèles sont ajoutés à `document_model` ;
2. ils sont donc actifs ;
3. `PROPALE_ADDON_PDF` reçoit `lmdbpropalpv_withpictures` ;
4. `LMDBPROPALPV_INITIAL_PDF_SETUP_DONE` reçoit `1` après réussite.

Les activations suivantes n’ajoutent, ne réactivent et ne sélectionnent aucun modèle. `remove()` conserve les constantes du module et ne supprime ni extrafields, ni données, ni modèles, ni réglages.

Le message d’activation explique ce comportement avant l’action administrateur.

## 9. Administration et droits

Les réglages internes sont `setup.php`, `tariffs.php`, `compatibility.php` et `about.php`. Seul `setup.php@lmdbpropalpv` est déclaré dans `config_page_url`.

Droits :

- lire l’étude ;
- modifier l’étude ;
- configurer le module et les barèmes.

Les identifiants sont calculés à partir de `450010 * 100 + r`. Un administrateur Dolibarr ou Multicompany bénéficie d’une élévation fonctionnelle centralisée. Les contrôles d’entité, d’objet et de CSRF restent obligatoires.

## 10. Exclusions V1

- PVGIS et calcul automatique de production ;
- financement, maintenance, remplacement d’onduleur, fiscalité, VAN et TRI ;
- ZNI, vente totale, batterie ;
- synchronisation automatique des tarifs ;
- API, cron, trigger métier, Agenda, Notifications, import et export propres au module ;
- migration automatique depuis `pvpropal` ou JPSUN ;
- photos de chantier.

## 11. Recette

La recette couvre le cas de référence, les bornes de puissance, les périodes absentes ou superposées, les données invalides, le verrouillage après validation, les deux modèles PDF, les photos, la signature, les pieds, deux entités, le multicurrency, les administrateurs et utilisateurs standards, Dolibarr 20/PHP 8.0, PHPStan et l’absence de modification hors module.
