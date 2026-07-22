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
| Indicateur de retour | étude complète avec amortissement atteint | valeur du temps de retour normalisée selon les décimales totales et affichée dans un badge bleu natif |
| État | devis validé | formulaire entièrement consultatif |
| S21 | puissances 3, 9, 9,01, 36, 36,01 et 100 kWc | tranche correcte sans trou |
| S21 | 5 juin 2026 | prime nulle et vente à 0,011 €/kWh |
| TRVE initial | dates des 1er et 31 janvier 2021 | barème applicable issu de la période source août 2020–janvier 2021 |
| TRVE | Base 3/6/9/12/15 kVA | composante TTC correcte pour la date |
| TRVE | Heures pleines 6 à 36 kVA | composante HP TTC correcte pour la date |
| Tarif Jaune | sélectionner 37, 42, 100 et 250 kVA dans les réglages, l’étude et un barème personnalisé | chaque puissance est proposée et enregistrée ; 36,5 et 251 kVA sont refusés |
| Tarif Jaune | recharger un barème personnalisé correspondant exactement à la date, la devise, le profil et la puissance | prix réseau rechargé ; aucune extrapolation depuis une autre puissance |
| Raccordement | puissance-crête inférieure, égale puis supérieure à la somme AC nominale des onduleurs | Pmax égale au minimum exact des deux puissances |
| Raccordement | plusieurs onduleurs avec quantités distinctes | somme exacte de `quantité × ac_nominal_power`, exprimée en kVA |
| Raccordement | puissance nominale, table ou colonne absente | statut « Vérification incomplète », repli sur la puissance-crête et aucune erreur fatale |
| Puissance souscrite | Pmax 10 kVA avec 9 kVA souscrits, puis Pmax 36,1 kVA | alerte informative et suggestions respectives de 12 et 37 kVA |
| Puissance souscrite | Pmax supérieure à 250 kVA | aucune suggestion automatique et demande d’étude spécifique |
| Phases | monophasé à 6 puis 6,01 kVA | premier cas accepté, second signalé comme raccordement à revoir |
| Phases | triphasé jusqu’à 36 kVA puis au-delà | rappel 12 kVA/phase et déséquilibre 6 kVA, puis orientation vers une étude BT dédiée |
| Snapshot raccordement | devis neuf, sauvegarde, duplication et devis validé | type de raccordement proposé puis conservé ; lecture seule après validation |
| Devise | devis dans une devise sans barème | étude incomplète, message explicite |
| PDF illustré | aucune, quelques et toutes les photos produits | colonne propre, aucun emplacement cassé |
| PDF épuré | produits avec photos | aucune colonne image |
| PDF | lignes et notes longues, plusieurs pages | pas de chevauchement avec le pied |
| PDF raccordement | générer les deux modèles avec contrôle conforme, alerte et vérification incomplète | même encadré jaune, valeurs et messages cohérents avec l’onglet |
| Modèles PDF | ouvrir l’administration native en français puis en anglais | noms traduits « PV Signature illustré/épuré » et équivalents anglais, identifiants techniques inchangés |
| Métadonnées PDF | mettre à jour une installation contenant les anciennes lignes avec `description` renseignée | libellés traduits visibles, `description` vidée, aucun modèle désactivé ou réactivé et modèle par défaut inchangé |
| Corps commercial configurable | sélectionner successivement Cyan puis un autre modèle PDF actif dans chaque entité | le corps commercial provient du modèle choisi et les pages PV sont assemblées autour de celui-ci |
| Pagination PDF globale | générer un devis dont le corps commercial comporte plusieurs pages | exactement N pages, sans page blanche de pagination, et numérotation continue de `1 / N` à `N / N`, sans reprise du compteur du corps commercial ou du supplément PV |
| Annexes PDF | activer puis désactiver séparément l’intégration des CGV et des fiches produits du modèle commercial | seules les annexes configurées sont présentes et chaque page ajoutée est incluse dans le total de la pagination globale |
| Corps commercial indisponible | désactiver après configuration le modèle commercial sélectionné puis générer | repli contrôlé sur Cyan, sans récursion ni erreur fatale |
| Graphiques | étude avec amortissement atteint | ligne horizontale jusqu’au point zéro et verticale jusqu’à l’axe des années dans l’onglet et le PDF |
| Graphiques onglet et PDF | valeurs négatives et positives sur 20 ans | graduations lisibles, repères d’amortissement visibles et libellé du temps de retour placé au-dessus de la courbe sans la chevaucher |
| Décimales | modifier `MAIN_MAX_DECIMALS_TOT` puis régénérer | toutes les données de la projection suivent la précision totale dans l’onglet et les PDF, sauf « Prix réseau » qui suit la précision unitaire |
| Signature | devis validé avec signature en ligne active | URL native et QR code |
| Signature | signature désactivée | bloc Bon pour accord |
| Multicompany | deux entités avec couleurs et devis distincts | réglages et documents isolés |
| Sélecteur de couleur | modifier les deux couleurs avec JavaScript activé puis avec `MAIN_USE_HTML5_COLOR_SELECTOR` actif | composant natif affiché, valeur sauvegardée en `#RRGGBB` et couleur reprise dans l’onglet et les PDF |
| Multicompany | produits MODULE partagés, caractéristiques et défauts différents | résolution conforme aux entités PowerPlantPV accessibles et défaut de l’entité propriétaire du devis |
| Droits | administrateur sans droits fins | accès complet dans son périmètre |
| Droits | lecture seule | consultation, aucune écriture |
| Droits | sans droit | accès refusé |
| Compatibilité | Dolibarr 20 / PHP 8.0 | activation et pages sans erreur fatale |
| Interface | survoler toutes les icônes d’aide de l’onglet, des réglages et des barèmes | tooltip natif visible avec `classfortooltip` et texte non vide |
| Navigateur | ouvrir le devis `PROV1215` en français | titre exact `PROV1215 - Étude financière PV` |
