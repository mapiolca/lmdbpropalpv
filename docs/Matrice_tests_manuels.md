# Matrice de tests manuels V1

| Domaine | Scénario | Résultat attendu |
|---|---|---|
| Activation | première activation d’une entité | deux modèles actifs, illustré par défaut, marqueur à 1 |
| Activation | confirmation d’activation | avertissement natif expliquant le premier réglage et la conservation lors des réactivations |
| Réactivation | choisir le modèle épuré, désactiver l’illustré, désactiver/réactiver le module | choix et modèles actifs inchangés |
| Calcul | cas doré 3 kWc | valeurs conformes au test automatisé |
| Hypothèses | enregistrer l’étude puis modifier les valeurs par défaut de l’entité | le devis conserve son instantané |
| Complétude | production absente | liste du champ manquant, aucune page financière PDF |
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
| Signature | devis validé avec signature en ligne active | URL native et QR code |
| Signature | signature désactivée | bloc Bon pour accord |
| Multicompany | deux entités avec couleurs et devis distincts | réglages et documents isolés |
| Droits | administrateur sans droits fins | accès complet dans son périmètre |
| Droits | lecture seule | consultation, aucune écriture |
| Droits | sans droit | accès refusé |
| Compatibilité | Dolibarr 20 / PHP 8.0 | activation et pages sans erreur fatale |
