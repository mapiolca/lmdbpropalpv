# Qualification « Chantier Complexe »

Cette fonctionnalité est implémentée sur la branche `feat/chantier-complexe`, à partir du module `1.0.1`. Elle ne constitue pas une nouvelle release publiée. Elle qualifie exclusivement le devis ; elle ne modifie pas son projet, ses PDF ni le calcul des commissions.

## Activation et accès

Le réglage `LMDBPROPALPV_COMPLEX_SITE_ENABLED` est désactivé par défaut et conservé lors des désactivations/réactivations. Il est géré par le switch natif `ajax_constantonoff()` dans les paramètres, accessibles aux administrateurs Dolibarr. Le mode sans JavaScript utilise les actions natives `set_…` et `del_…`, avec token et vérification de l’entité courante ; OFF conserve explicitement la constante à `0`.

Après mise à jour du code, réactiver le module dans chaque entité concernée pour créer la définition de l’extrafield, puis activer le réglage. La définition est masquée du rendu standard et non imprimable : seule la ligne ajoutée par `formObjectOptions` l’affiche. L’activation ne requalifie aucun devis et n’appelle aucun trigger. La qualification initiale, absente, `NULL` ou `0` correspond à OFF. Une duplication suit le comportement natif de copie des extrafields.

Le réglage s’applique à l’entité de consultation. Un devis partagé de A consulté dans B affiche le switch lorsque le réglage de B est actif ; la valeur reste attachée au devis de A. Le prédicat `LmdbPropalPVComplexSiteService::isAvailable()` est commun au rendu, à l’action serveur et à l’onglet Compatibilité. Il exige le module actif, le socle Dolibarr 20/PHP 8.0, le réglage actif, les extrafields actifs et une définition booléenne activée pour le champ.

Chaque écriture exige un utilisateur interne avec les droits directs `propal/lire` et `propal/creer`. Le rôle administrateur n’accorde aucune permission métier implicite. Le serveur recharge le devis et contrôle son entité, son tiers, l’existence de ce tiers et son accessibilité via `checkUserAccessToObject()`. Seuls les brouillons sont modifiables ; le contrôle est refait après verrouillage.

## Enregistrement et contrat de trigger

Le switch utilise les pictogrammes Dolibarr `switch_on` / `switch_off` dans un formulaire POST avec token, état attendu et valeur cible. Il fonctionne sans JavaScript et annonce son état au clavier/lecteur d’écran. L’action `setcomplexsite` traite le POST puis redirige vers la fiche. Les entrées autres que les chaînes `0` et `1`, les GET modificatifs et les tokens absents ou invalides sont refusés.

La méthode métier verrouille le devis puis sa ligne d’extrafields dans une transaction MySQL/MariaDB, recharge l’objet et vérifie l’état attendu. Une valeur cible déjà enregistrée est un succès sans écriture ni trigger, y compris après un double clic ou deux formulaires demandant la même valeur. Une attente incompatible avec la valeur courante est refusée. Une validation concurrente ou une perte d’accès interdit la modification. Les transactions SQL ne peuvent pas annuler un effet externe déjà produit par un autre trigger, tel qu’un email.

L’enregistrement passe directement par :

```php
$proposal->oldcopy = dol_clone($proposal, 2);
$proposal->array_options['options_lmdbpropalpv_complex_site'] = $value;
$proposal->updateExtraField('lmdbpropalpv_complex_site', 'PROPAL_MODIFY', $user);
```

L’échec du trigger annule l’écriture. Le core peut convertir le booléen OFF en `NULL` avant l’appel du trigger et remplace `$object->context` par son contexte natif d’édition d’extrafield. Les consommateurs doivent normaliser l’ancienne et la nouvelle valeur, sans dépendre d’un contexte personnalisé :

```php
if ($action === 'PROPAL_MODIFY' && $object instanceof Propal
	&& is_object($object->oldcopy)) {
	$old = (int) !empty($object->oldcopy->array_options['options_lmdbpropalpv_complex_site']);
	$new = (int) !empty($object->array_options['options_lmdbpropalpv_complex_site']);
	if ($old !== $new) {
		// Apply the consuming module's own business rule once.
	}
}
```

Le consommateur reste responsable de ses permissions, de son entité, de sa configuration et de l’idempotence de ses effets. Les valeurs sont aussi consultables lors des autres triggers natifs du devis, notamment à sa validation. Aucun code trigger custom, déclaration Agenda/Notifications supplémentaire ou appel direct à un module de commissions n’est ajouté.

La lecture par l’API native reste celle des extrafields Dolibarr. Cette évolution ne crée aucun endpoint et ne modifie pas les écritures de l’API native : le verrouillage au brouillon et la disponibilité décrits ici concernent le switch et sa méthode métier. Un autre canal natif peut ne pas fournir `oldcopy` ; ce contrat de détection du changement ne doit pas lui être attribué sans vérification spécifique.

## Preuves et recette

Lecture de sources le 2026-09-30 : le hook `formObjectOptions`, `CommonObject::updateExtraField()` et son appel natif `PROPAL_MODIFY` depuis la fiche devis ont été retrouvés dans les tags `20.0.0`, `21.0.0`, `22.0.0`, `23.0.0` et `24.0.0`. Cette lecture n’est pas un essai de ces instances ni une preuve de la première version d’apparition.

Les mêmes sources contiennent `checkUserAccessToObject()` et `ajax_constantonoff()` avec l’option de conservation à `0`. Révisions examinées :

| Tag Dolibarr | Commit |
|---|---|
| 20.0.0 | `697bf01970740a3339cd99cf055b4428fc5e051c` |
| 21.0.0 | `fd970b582a4d8c5779a2958a4e9f4fce225cf085` |
| 22.0.0 | `49b9a6d19f3deb6d410c0e9b3310e95be4ea7710` |
| 23.0.0 | `57a1f05d490a7a80944a8232e9c613e6556d2704` |
| 24.0.0 | `769c7db907099643558e77d7002c109cfda919e5` |

Le socle est notamment figé au commit `697bf01970740a3339cd99cf055b4428fc5e051c` : [hook de rendu](https://github.com/Dolibarr/dolibarr/blob/697bf01970740a3339cd99cf055b4428fc5e051c/htdocs/core/tpl/extrafields_view.tpl.php#L55), [action native](https://github.com/Dolibarr/dolibarr/blob/697bf01970740a3339cd99cf055b4428fc5e051c/htdocs/comm/propal/card.php#L1702), [enregistrement et transaction](https://github.com/Dolibarr/dolibarr/blob/697bf01970740a3339cd99cf055b4428fc5e051c/htdocs/core/class/commonobject.class.php#L6934), [normalisation du booléen](https://github.com/Dolibarr/dolibarr/blob/697bf01970740a3339cd99cf055b4428fc5e051c/htdocs/core/class/commonobject.class.php#L7106), [remplacement du contexte](https://github.com/Dolibarr/dolibarr/blob/697bf01970740a3339cd99cf055b4428fc5e051c/htdocs/core/class/commonobject.class.php#L7213), [accès métier](https://github.com/Dolibarr/dolibarr/blob/697bf01970740a3339cd99cf055b4428fc5e051c/htdocs/core/lib/security.lib.php#L852).

`php test/run_complex_site_tests.php` exécute des simulations de contrats sous le PHP local : écritures ciblées, droits sans élévation, parent inaccessible/absent, statuts, entités et partage, idempotence, conflits, échecs SQL/triggers et transactions imbriquées, disponibilité, rendu sans JavaScript et traitement du POST. Les doubles de test ne remplacent ni MySQL/MariaDB réel, ni Multicompany, ni `main.inc.php`.

Les scénarios de recette sur instance figurent dans `Matrice_tests_manuels.md`. Aucune activation réelle, concurrence SQL réelle, vérification navigateur Dolibarr ou essai PHP 8.0 n’a été effectué lors de cette préparation. PHPStan n’est pas installé dans l’environnement local et aucune configuration PHPStan n’existe dans ce module. Le workflow GitHub utilise les commandes PHP du module ; il remplace le modèle Composer qui exigeait des fichiers absents du dépôt.
