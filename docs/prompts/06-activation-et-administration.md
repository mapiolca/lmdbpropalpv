# Prompt 06 — Activation et administration

Lire intégralement `AGENT.md`. Travailler uniquement dans la racine actuelle `lmdbpropalpv/`, sans créer `lmdbpropalpv/lmdbpropalpv/` et sans modifier Dolibarr, JPSUN, PowerPlantPV ou un autre module. Préserver les modifications existantes. Cibler Dolibarr v20+, PHP 8.0+, MySQL/MariaDB, Multicompany, FR/EN et PHPStan. Utiliser les mécanismes natifs Dolibarr et exécuter les tests ciblés avant le compte rendu au format imposé par `AGENT.md`.

Implémenter la déclaration et la première activation des deux modèles, le modèle illustré par défaut, le marqueur persistant et le message `warnings_activation`. Ajouter les blocs natifs de modèles de document, le choix par entité du modèle PDF actif utilisé comme corps commercial, la gestion des barèmes, des couleurs et des valeurs par défaut. Corriger de manière idempotente les métadonnées des deux modèles PV sans les réactiver ni changer le modèle par défaut. Les sélecteurs de puissance souscrite couvrent les puissances usuelles du Tarif Bleu et toutes les valeurs entières du Tarif Jaune de 37 à 250 kVA. Prouver qu’une réactivation conserve tous les choix administrateur.

Simuler le choix du modèle épuré et la désactivation d’un modèle avant la réactivation. Aucune valeur ne doit être remise à zéro.
