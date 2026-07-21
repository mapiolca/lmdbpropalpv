<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

require '../../../main.inc.php';
dol_include_once('/lmdbpropalpv/lib/lmdbpropalpv.lib.php');

$langs->loadLangs(array('admin', 'lmdbpropalpv@lmdbpropalpv'));
if (!isModEnabled('lmdbpropalpv')) {
	accessforbidden();
}
if (!lmdbpropalpvCanDo($user, 'setup')) {
	accessforbidden();
}

llxHeader('', $langs->trans('About'));
print load_fiche_titre($langs->trans('LmdbPropalPVSetup'), lmdbpropalpvAdminLinkBack(), 'solar-panel');
print dol_get_fiche_head(lmdbpropalpvAdminPrepareHead(), 'about', $langs->trans('LmdbPropalPVSetup'), -1, 'solar-panel');
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th colspan="2">LmdbPropalPV</th></tr>';
$rows = array(
	array($langs->trans('Version'), '1.0.0'),
	array($langs->trans('Author'), 'Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>'),
	array($langs->trans('Description'), $langs->trans('LmdbPropalPVAboutDescription')),
	array($langs->trans('Compatibility'), 'Dolibarr 20+ / PHP 8.0+ / MySQL-MariaDB'),
	array($langs->trans('LmdbPropalPVDependencies'), 'Propositions commerciales, PowerPlantPV 1.3.0+'),
	array($langs->trans('LmdbPropalPVMainFeatures'), $langs->trans('FinancialStudyPV').', '.$langs->trans('LmdbPropalPVFeaturePanelDegradation').', '.$langs->trans('LmdbPropalPVFeatureInverterConnectionPower').', '.$langs->trans('LmdbPropalPVFeatureYellowTariffPowers').', '.$langs->trans('LmdbPropalPVModelWithPictures').', '.$langs->trans('LmdbPropalPVModelWithoutPictures')),
	array($langs->trans('LmdbPropalPVLicense'), $langs->trans('LmdbPropalPVLicenseValue')),
);
foreach ($rows as $row) {
	print '<tr class="oddeven"><td class="titlefield">'.$row[0].'</td><td>'.dol_escape_htmltag($row[1]).'</td></tr>';
}
print '</table>';
print dol_get_fiche_end();
llxFooter();
$db->close();
