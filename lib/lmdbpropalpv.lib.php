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
