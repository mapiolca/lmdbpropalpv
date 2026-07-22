<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

/**
 * Prepare module administration tabs.
 *
 * @return array<int,array{0:string,1:string,2:string}>
 */
function lmdbpropalpvAdminPrepareHead()
{
	global $langs;

	$langs->load('lmdbpropalpv@lmdbpropalpv');
	$head = array();
	$head[] = array(dol_buildpath('/lmdbpropalpv/admin/setup.php', 1), $langs->trans('Settings'), 'settings');
	$head[] = array(dol_buildpath('/lmdbpropalpv/admin/tariffs.php', 1), $langs->trans('LmdbPropalPVTariffs'), 'tariffs');
	$head[] = array(dol_buildpath('/lmdbpropalpv/admin/compatibility.php', 1), $langs->trans('Compatibility'), 'compatibility');
	$head[] = array(dol_buildpath('/lmdbpropalpv/admin/about.php', 1), $langs->trans('About'), 'about');

	return $head;
}

/**
 * Return the supported subscribed powers for French Blue and Yellow tariffs.
 *
 * Yellow tariff powers are integer multiples of 1 kVA from 37 to 250 kVA.
 *
 * @return array<int,string>
 */
function lmdbpropalpvGetSubscribedPowerOptions()
{
	$options = array(
		3 => '3 kVA',
		6 => '6 kVA',
		9 => '9 kVA',
		12 => '12 kVA',
		15 => '15 kVA',
		18 => '18 kVA',
		24 => '24 kVA',
		30 => '30 kVA',
		36 => '36 kVA',
	);
	for ($powerKva = 37; $powerKva <= 250; $powerKva++) {
		$options[$powerKva] = ((string) $powerKva).' kVA';
	}

	return $options;
}

/**
 * Validate a subscribed power offered by the module.
 *
 * @param float $powerKva Subscribed power
 * @return bool
 */
function lmdbpropalpvSubscribedPowerIsSupported($powerKva)
{
	$integerPower = (int) $powerKva;
	if (abs($powerKva - (float) $integerPower) > 0.00000001) {
		return false;
	}

	return array_key_exists($integerPower, lmdbpropalpvGetSubscribedPowerOptions());
}

/**
 * Validate a technical proposal model name that may be used as commercial body.
 *
 * The two PV Signature models are excluded to prevent recursive generation.
 * Filename-based ODT variants are excluded because the PV renderer composes PDF
 * pages only.
 *
 * @param string $model Model technical name
 * @return bool
 */
function lmdbpropalpvBaseProposalModelNameIsSafe($model)
{
	if (!preg_match('/^[a-z0-9_]+$/', $model)) {
		return false;
	}

	return !in_array($model, array('lmdbpropalpv_withpictures', 'lmdbpropalpv_withoutpictures'), true);
}

/**
 * Build the final page order for the composed PV proposal.
 *
 * The body page count is read only after the selected proposal model has
 * finished generating its document. It therefore already includes every page
 * enabled by that model, including terms of sale and product data sheets.
 *
 * @param int $supplementPages Number of PV supplement pages
 * @param int $bodyPages       Number of generated commercial body pages
 * @return list<array{source:string,source_page:int,final_page:int,total_pages:int}>
 */
function lmdbpropalpvBuildPdfMergePagePlan($supplementPages, $bodyPages)
{
	$supplementPages = (int) $supplementPages;
	$bodyPages = (int) $bodyPages;
	if ($supplementPages < 1 || $bodyPages < 1) {
		return array();
	}

	$totalPages = $supplementPages + $bodyPages;
	$plan = array();
	$finalPage = 0;
	$plan[] = array(
		'source' => 'supplement',
		'source_page' => 1,
		'final_page' => ++$finalPage,
		'total_pages' => $totalPages,
	);
	for ($sourcePage = 1; $sourcePage <= $bodyPages; $sourcePage++) {
		$plan[] = array(
			'source' => 'body',
			'source_page' => $sourcePage,
			'final_page' => ++$finalPage,
			'total_pages' => $totalPages,
		);
	}
	for ($sourcePage = 2; $sourcePage <= $supplementPages; $sourcePage++) {
		$plan[] = array(
			'source' => 'supplement',
			'source_page' => $sourcePage,
			'final_page' => ++$finalPage,
			'total_pages' => $totalPages,
		);
	}

	return $plan;
}

/**
 * Return active PHP proposal models that can provide the commercial PDF body.
 *
 * The native Cyan model remains available as a safe fallback even when its row
 * is not active, preserving the historical behaviour of the module.
 *
 * @param DoliDB $db     Database handler
 * @param int    $entity Entity id
 * @return array<string,string>
 */
function lmdbpropalpvGetBaseProposalModelOptions($db, $entity)
{
	global $langs;

	$options = array('cyan' => $langs->trans('LmdbPropalPVCyanBaseModel'));
	$sql = 'SELECT nom, libelle, entity FROM '.MAIN_DB_PREFIX.'document_model';
	$sql .= " WHERE type = 'propal'";
	$sql .= ' AND entity IN (0, '.((int) $entity).')';
	$sql .= " AND (description IS NULL OR description = '')";
	$sql .= ' ORDER BY entity DESC, libelle ASC, nom ASC';
	$resql = $db->query($sql);
	if (!$resql) {
		return $options;
	}
	while (is_object($obj = $db->fetch_object($resql))) {
		$model = (string) $obj->nom;
		if (!lmdbpropalpvBaseProposalModelNameIsSafe($model) || isset($options[$model])) {
			continue;
		}
		$label = trim((string) $obj->libelle);
		$options[$model] = $label !== '' ? $label : $model;
	}
	$db->free($resql);

	return $options;
}

/**
 * Repair the native metadata of the two PV proposal models without changing
 * their activation state or the administrator's default model.
 *
 * Dolibarr reserves document_model.description for a directory constant used
 * by filename-based templates. PHP PDF models must therefore keep it empty.
 *
 * @param DoliDB    $db     Database handler
 * @param int       $entity Entity id
 * @param Translate $langs  Translation handler
 * @return int 1 on success, -1 on error
 */
function lmdbpropalpvNormalizeProposalModelMetadata($db, $entity, $langs)
{
	$models = array(
		'lmdbpropalpv_withpictures' => 'LmdbPropalPVModelWithPictures',
		'lmdbpropalpv_withoutpictures' => 'LmdbPropalPVModelWithoutPictures',
	);
	foreach ($models as $model => $translationKey) {
		$sql = 'UPDATE '.MAIN_DB_PREFIX.'document_model';
		$sql .= " SET libelle = '".$db->escape($langs->trans($translationKey))."', description = NULL";
		$sql .= " WHERE nom = '".$db->escape($model)."'";
		$sql .= " AND type = 'propal' AND entity = ".((int) $entity);
		if (!$db->query($sql)) {
			return -1;
		}
	}

	return 1;
}

/**
 * Return whether a user is an administrator for the current functional scope.
 *
 * This adds real elevation logic and is therefore intentionally not a wrapper
 * around User::hasRight().
 *
 * @param User $user Dolibarr user
 * @return bool
 */
function lmdbpropalpvUserIsAdministrator($user)
{
	if (!is_object($user)) {
		return false;
	}
	if (!empty($user->admin)) {
		return true;
	}

	return isModEnabled('multicompany')
		&& ($user->hasRight('multicompany', 'admin', 'read') || $user->hasRight('multicompany', 'admin', 'write') || $user->hasRight('multicompany', 'entities', 'write'));
}

/**
 * Central business permission check.
 *
 * @param User        $user   Dolibarr user
 * @param string      $action read|write|setup
 * @param Propal|null $propal Proposal when the action targets one
 * @return bool
 */
function lmdbpropalpvCanDo($user, $action, $propal = null)
{
	if (!is_object($user)) {
		return false;
	}
	if (lmdbpropalpvUserIsAdministrator($user)) {
		return true;
	}
	if ($action === 'setup') {
		return $user->hasRight('lmdbpropalpv', 'setup', 'write');
	}
	if (!$user->hasRight('lmdbpropalpv', 'study', $action)) {
		return false;
	}
	if ($action === 'read' && !$user->hasRight('propal', 'lire')) {
		return false;
	}
	if ($action === 'write' && !$user->hasRight('propal', 'creer')) {
		return false;
	}
	if (is_object($propal) && !empty($user->socid) && (int) $propal->socid !== (int) $user->socid) {
		return false;
	}

	return true;
}

/**
 * Return the link back to the native module list.
 *
 * @return string
 */
function lmdbpropalpvAdminLinkBack()
{
	global $langs;

	return '<a href="'.DOL_URL_ROOT.'/admin/modules.php?search_keyword=lmdbpropalpv">'.$langs->trans('BackToModuleList').'</a>';
}

/**
 * Read an entity-owned constant, including when a shared object belongs to a
 * different entity than the current UI context.
 *
 * @param DoliDB $db      Database handler
 * @param string $name    Constant name
 * @param string $default Default value
 * @param int    $entity  Owner entity
 * @return string
 */
function lmdbpropalpvGetEntityStringConstant($db, $name, $default, $entity)
{
	global $conf;
	/** @var array<string,string> $cache */
	static $cache = array();

	if ((int) $entity <= 0 || (int) $entity === (int) $conf->entity) {
		return getDolGlobalString($name, $default);
	}
	$cacheKey = ((string) ((int) $entity)).'|'.$name;
	if (array_key_exists($cacheKey, $cache)) {
		return $cache[$cacheKey];
	}
	$sql = 'SELECT value FROM '.MAIN_DB_PREFIX.'const';
	$sql .= " WHERE name = '".$db->escape($name)."' AND entity = ".((int) $entity);
	$sql .= ' ORDER BY rowid DESC';
	$resql = $db->query($sql);
	if (!$resql) {
		return $default;
	}
	$obj = $db->fetch_object($resql);
	$db->free($resql);

	$cache[$cacheKey] = is_object($obj) ? (string) $obj->value : $default;

	return $cache[$cacheKey];
}
