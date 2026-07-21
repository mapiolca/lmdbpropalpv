# Matrice de tests manuels V1

| Domaine | Scénario | Résultat attendu |
|---|---|---|
| Activation | première activation d’une entité | deux modèles actifs, illustré par défaut, marqueur à 1 |
| Activation | confirmation d’activation | avertissement natif expliquant le premier réglage et la conservation lors des réactivations |
| Réactivation | choisir le modèle épuré, désactiver l’illustré, désactiver/réactiver le module | choix et modèles actifs inchangés |
| Calcul | cas doré 3 kWc | valeurs conformes au test automatisé |
| Dégradation | un module avec deux taux, avec taux nul, puis taux invalide | valeurs PowerPlantPV proposées ; zéro conservé ; repli ciblé et avertissement sur l’invalide |
| Dégradation | plusieurs références de modules | moyenne exacte pondérée par `quantité × pmax` |
| Dégradation | aucune caractéristique exploitable | deux défauts d’entité à 0,45 % et avertissement non bloquant |
| Snapshot | modifier une fiche produit après sauvegarde | aucun changement avant « Recharger les caractéristiques panneaux » |
| Snapshot | recharger les panneaux sur brouillon, puis sur devis validé | deux taux enregistrés sur le brouillon ; action refusée après validation |
| Hypothèses | enregistrer l’étude puis modifier les valeurs par défaut de l’entité | le devis conserve son instantané |
| Complétude | production absente | liste du champ manquant, aucune page financière PDF |
| Bannière et statut | fiche Proposition commerciale puis onglet Étude financière | mêmes référence client, tiers et projet ; ligne `Statut` dans le `fichehalfright` avec badge Dolibarr lisible |
| État | devis validé | formulaire entièrement consultatif |
| S21 | puissances 3, 9, 9,01, 36, 36,01 et 100 kWc | tranche correcte sans trou |
| S21 | 5 juin 2026 | prime nulle et vente à 0,011 €/kWh |
| TRVE initial | dates des 1er et 31 janvier 2021 | barème applicable issu de la période source août 2020–janvier 2021 |
| TRVE | Base 3/6/9/12/15 kVA | composante TTC correcte pour la date |
| TRVE | Heures pleines 6 à 36 kVA | composante HP TTC correcte pour la date |
| Devise | devis dans une devise sans barème | étude incomplète, message explicite |
| PDF illustré | aucune, quelques et toutes les photos produits | colonne propre, aucun emplacement cassé |
| PDF épuré | produits avec photos | aucune colonne image |
| PDF | lignes et notes longues, plusieurs pages | pas de chevauchement avec le pied |
| Graphiques | étude avec amortissement atteint | ligne horizontale jusqu’au point zéro et verticale jusqu’à l’axe des années dans l’onglet et le PDF |
| Graphique PDF | valeurs négatives et positives sur 20 ans | graduations de trésorerie et d’années lisibles, courbe non tronquée |
| Décimales | modifier `MAIN_MAX_DECIMALS_TOT` puis régénérer | tous les montants de l’onglet et des PDF suivent le réglage ; les prix unitaires suivent la précision unitaire |
| Signature | devis validé avec signature en ligne active | URL native et QR code |
| Signature | signature désactivée | bloc Bon pour accord |
| Multicompany | deux entités avec couleurs et devis distincts | réglages et documents isolés |
| Multicompany | produits MODULE partagés, caractéristiques et défauts différents | résolution conforme aux entités PowerPlantPV accessibles et défaut de l’entité propriétaire du devis |
| Droits | administrateur sans droits fins | accès complet dans son périmètre |
| Droits | lecture seule | consultation, aucune écriture |
| Droits | sans droit | accès refusé |
| Compatibilité | Dolibarr 20 / PHP 8.0 | activation et pages sans erreur fatale |
