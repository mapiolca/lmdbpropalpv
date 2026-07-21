# AGENT.md — LmdbPropalPV

Ce dépôt est directement la racine du module externe Dolibarr `lmdbpropalpv`.

## Périmètre absolu

- Ne jamais créer de sous-répertoire `lmdbpropalpv/lmdbpropalpv`.
- Ne jamais modifier un fichier du core Dolibarr, PowerPlantPV, JPSUN ou un autre module.
- Ne jamais écrire de document utilisateur ou temporaire dans le code du module.
- Préserver les modifications existantes et les réglages administrateur.
- Utiliser l’auteur `Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>` dans les nouveaux fichiers.

## Socle

- Dolibarr 20 ou supérieur, PHP 8.0 ou supérieur, MySQL/MariaDB.
- Compatibilité Multicompany, `fr_FR` et `en_US` obligatoire.
- ID du module : `450010` ; famille exacte : `Les Métiers du Bâtiment`.
- Version initiale : `1.0.0`.
- `config_page_url` contient uniquement `setup.php@lmdbpropalpv`.
- Aucun menu haut. L’accès métier passe par l’onglet de proposition commerciale.
- Dépendances obligatoires : Propositions commerciales et PowerPlantPV 1.3.0+.

## Intégration Dolibarr

- Utiliser les mécanismes natifs : objets, extrafields, modèles de document, formulaires, datepicker, Select2, droits et messages.
- Utiliser directement `isModEnabled()`, `getDolGlobalInt()`, `getDolGlobalString()` et `$user->hasRight()` lorsque le contexte est l’entité courante.
- Un helper local n’est acceptable que s’il ajoute une vraie règle métier, par exemple la lecture de la configuration de l’entité propriétaire d’un objet partagé.
- Utiliser `MAIN_DB_PREFIX` dans le PHP ; ne jamais hardcoder `llx_` hors fichiers SQL d’installation.
- Toute requête métier principale filtre l’entité. Les règles tarifaires héritent de l’entité par jointure obligatoire au barème parent.
- Une liaison vers une donnée existante utilise une clé `fk_*`, jamais une copie de colonnes ni une liste sérialisée.

## Sécurité et droits

- Lire les entrées avec `GETPOST()` et un filtre adapté.
- Toute écriture utilise POST et un champ `token` alimenté par `newToken()`.
- Les contrôles de droits et d’entité restent côté serveur.
- Les permissions utilisent exactement `$this->rights[$r][0] = $this->numero * 100 + $r;`, avec `$r` initialisé à zéro puis incrémenté avant chaque droit.
- Le contrôle central donne tous les droits fonctionnels aux administrateurs Dolibarr et Multicompany, sans supprimer les contrôles d’entité, d’objet ou de CSRF.
- Un utilisateur standard doit cumuler le droit sur la proposition et le droit correspondant du module.

## Calculs financiers

- Le moteur de projection reste pur : aucune lecture SQL, aucun rendu, aucune dépendance Dolibarr.
- Il produit exactement 20 années et ne persiste jamais les lignes calculées.
- Aucun arrondi intermédiaire.
- Normaliser les prix unitaires avec `price2num($value, 'MU')`, les totaux avec `price2num($value, 'MT')` et afficher les montants avec `price()`.
- Ne jamais utiliser `round(..., 2)`, `number_format(..., 2)` ou une précision monétaire codée en dur.
- La puissance-crête provient exclusivement de `powerplantpvGetObjectPeakPowerKwc($propal)`.
- Les barèmes embarqués sont des données de release : aucun accès Internet à l’exécution.
- Un rechargement de barème est toujours explicite et ne modifie jamais rétroactivement les devis.

## Stockage et cycle de vie

- Les hypothèses du devis sont des extrafields `propal` masqués de la fiche principale.
- Les barèmes sont isolés par entité et devise.
- Un barème utilisé est archivé, jamais supprimé par une règle métier.
- `init()` est idempotent et n’écrase aucune configuration existante.
- `remove()` ne supprime ni constantes métier, ni extrafields, ni données, ni modèles actifs, ni modèle PDF par défaut.
- La première activation configure les deux modèles et le défaut uniquement si le marqueur d’initialisation est absent.

## PDF et documents

- Les modèles restent des modèles natifs `propal` et utilisent le stockage documentaire de l’entité propriétaire via `getMultidirOutput()` ou le fallback `multidir_output[$object->entity]`.
- Le corps commercial réutilise un modèle core comparable ; ne pas recopier le core dans le module.
- La variante illustrée utilise uniquement les photos natives des produits.
- Préserver les hooks PDF, les traductions, les notes, remises, TVA, multicurrency et conditions commerciales.
- Réserver la hauteur réelle du pied avant tout contenu.
- Appeler `_pagefoot(..., 1)` sur toutes les pages intermédiaires et `_pagefoot(..., 0)` seulement sur la dernière page.
- Tester les variantes sans photo, multipages, textes longs, signature en ligne et QR code.

## Compatibilité et qualité

- Toute capacité core absente d’une version supportée est filtrée par `DOL_VERSION` et documentée dans l’onglet Compatibilité ; ne pas la réimplémenter silencieusement.
- Déclarer les propriétés utilisées et documenter les tableaux complexes pour PHPStan.
- Ne pas ajouter de baseline, `ignoreErrors` ou `@phpstan-ignore-*` pour masquer une erreur.
- Initialiser les variables, vérifier tous les retours de requête et réduire les types issus de données externes.
- Conserver `README.md`, `ChangeLog.md`, `modulebuilder.txt` et le présent fichier à la racine.
- Le fichier d’historique s’appelle exactement `ChangeLog.md`.

## Vérifications minimales

1. PHP lint sur tous les fichiers PHP.
2. Tests du moteur financier et des bornes tarifaires.
3. PHPStan sur les composants testables avec l’environnement disponible.
4. Parité des clés de traduction FR/EN.
5. Recherche des accès superglobaux interdits, préfixes SQL hardcodés et arrondis monétaires locaux.
6. Sur une instance Dolibarr : activation, désactivation, réactivation, droits, deux entités, devis partagé, deux PDF et contrôle visuel des pieds.

## Compte rendu

Toujours fournir : résumé, fichiers modifiés, mécanismes Dolibarr natifs utilisés, tests exécutés, état de la vérification navigateur/déploiement, tests manuels restants, risques/limites, puis un titre et une description de commit proposés. Ne jamais annoncer comme réussi un test d’intégration ou PDF qui n’a pas réellement été exécuté.
