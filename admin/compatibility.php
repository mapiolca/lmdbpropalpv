<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

require '../../../main.inc.php';
dol_include_once('/lmdbpropalpv/lib/lmdbpropalpv.lib.php');
dol_include_once('/powerplantpv/lib/powerplantpv.lib.php');
dol_include_once('/lmdbpropalpv/class/lmdbpropalpvcompatibility.class.php');
if (!function_exists('getOnlineSignatureUrl')) {
	require_once DOL_DOCUMENT_ROOT.'/core/lib/signature.lib.php';
}

$langs->loadLangs(array('admin', 'lmdbpropalpv@lmdbpropalpv'));
if (!isModEnabled('lmdbpropalpv')) {
	accessforbidden();
}
if (!lmdbpropalpvCanDo($user, 'setup')) {
	accessforbidden();
}

llxHeader('', $langs->trans('Compatibility'));
print load_fiche_titre($langs->trans('LmdbPropalPVSetup'), lmdbpropalpvAdminLinkBack(), 'solar-panel');
print dol_get_fiche_head(lmdbpropalpvAdminPrepareHead(), 'compatibility', $langs->trans('LmdbPropalPVSetup'), -1, 'solar-panel');
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th>'.$langs->trans('LmdbPropalPVDetectedEnvironment').'</th><th>'.$langs->trans('LmdbPropalPVMinimumEnvironment').'</th></tr>';
print '<tr class="oddeven"><td>Dolibarr '.dol_escape_htmltag(DOL_VERSION).'</td><td>Dolibarr 20.0.0</td></tr>';
print '<tr class="oddeven"><td>PHP '.dol_escape_htmltag(PHP_VERSION).'</td><td>PHP 8.0.0</td></tr>';
print '</table><br>';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th>'.$langs->trans('Feature').'</th><th>'.$langs->trans('Description').'</th><th>'.$langs->trans('Status').'</th><th>'.$langs->trans('MinimumVersion').'</th><th>'.$langs->trans('LmdbPropalPVCompatibilityCheck').'</th></tr>';
foreach (LmdbPropalPVCompatibility::getFeatures() as $feature) {
	print '<tr class="oddeven"><td>'.$langs->trans($feature['label']).'</td><td>'.$langs->trans($feature['description']).'</td><td>';
	print $feature['available'] ? '<span class="badge badge-status4">'.$langs->trans('LmdbPropalPVAvailable').'</span>' : '<span class="badge badge-status8">'.$langs->trans('LmdbPropalPVUnavailable').'</span><br><span class="opacitymedium">'.$langs->trans($feature['reason']).'</span>';
	print '</td><td>Core Dolibarr '.dol_escape_htmltag($feature['core_available_from']).'<br>'.$langs->trans('LmdbPropalPVModuleAvailableFrom').' '.dol_escape_htmltag($feature['module_available_from']).'<br>PHP '.dol_escape_htmltag($feature['min_php']).'</td><td><code>'.dol_escape_htmltag($feature['compatibility_check']).'</code></td></tr>';
}
print '</table>';
print dol_get_fiche_end();
llxFooter();
$db->close();
