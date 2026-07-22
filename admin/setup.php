<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

require '../../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formother.class.php';
dol_include_once('/lmdbpropalpv/lib/lmdbpropalpv.lib.php');

$langs->loadLangs(array('admin', 'propal', 'lmdbpropalpv@lmdbpropalpv'));
if (!isModEnabled('lmdbpropalpv')) {
	accessforbidden();
}
if (!lmdbpropalpvCanDo($user, 'setup')) {
	accessforbidden();
}

$baseProposalModelOptions = lmdbpropalpvGetBaseProposalModelOptions($db, (int) $conf->entity);
$action = GETPOST('action', 'aZ09');
if ($action === 'save') {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		accessforbidden();
	}
	$primaryColor = strtoupper(GETPOST('LMDBPROPALPV_PDF_PRIMARY_COLOR', 'alphanohtml'));
	$accentColor = strtoupper(GETPOST('LMDBPROPALPV_PDF_ACCENT_COLOR', 'alphanohtml'));
	if ($primaryColor !== '' && substr($primaryColor, 0, 1) !== '#') {
		$primaryColor = '#'.$primaryColor;
	}
	if ($accentColor !== '' && substr($accentColor, 0, 1) !== '#') {
		$accentColor = '#'.$accentColor;
	}
	$values = array(
		'LMDBPROPALPV_DEFAULT_SELF_CONSUMPTION_PCT' => GETPOST('LMDBPROPALPV_DEFAULT_SELF_CONSUMPTION_PCT', 'alphanohtml'),
		'LMDBPROPALPV_DEFAULT_FIRST_YEAR_DEGRADATION_PCT' => GETPOST('LMDBPROPALPV_DEFAULT_FIRST_YEAR_DEGRADATION_PCT', 'alphanohtml'),
		'LMDBPROPALPV_DEFAULT_PANEL_DEGRADATION_PCT' => GETPOST('LMDBPROPALPV_DEFAULT_PANEL_DEGRADATION_PCT', 'alphanohtml'),
		'LMDBPROPALPV_DEFAULT_ELECTRICITY_GROWTH_PCT' => GETPOST('LMDBPROPALPV_DEFAULT_ELECTRICITY_GROWTH_PCT', 'alphanohtml'),
		'LMDBPROPALPV_DEFAULT_RETAIL_TARIFF_MODE' => GETPOST('LMDBPROPALPV_DEFAULT_RETAIL_TARIFF_MODE', 'alpha'),
		'LMDBPROPALPV_DEFAULT_RETAIL_SUBSCRIPTION_KVA' => GETPOST('LMDBPROPALPV_DEFAULT_RETAIL_SUBSCRIPTION_KVA', 'alphanohtml'),
		'LMDBPROPALPV_PDF_PRIMARY_COLOR' => $primaryColor,
		'LMDBPROPALPV_PDF_ACCENT_COLOR' => $accentColor,
		'LMDBPROPALPV_BASE_PROPOSAL_PDF_MODEL' => GETPOST('LMDBPROPALPV_BASE_PROPOSAL_PDF_MODEL', 'aZ09'),
		'LMDBPROPALPV_FINANCIAL_DISCLAIMER' => GETPOST('LMDBPROPALPV_FINANCIAL_DISCLAIMER', 'restricthtml'),
	);
	$error = 0;
	$selfConsumption = (float) price2num($values['LMDBPROPALPV_DEFAULT_SELF_CONSUMPTION_PCT']);
	$firstYearDegradation = (float) price2num($values['LMDBPROPALPV_DEFAULT_FIRST_YEAR_DEGRADATION_PCT']);
	$annualDegradation = (float) price2num($values['LMDBPROPALPV_DEFAULT_PANEL_DEGRADATION_PCT']);
	$growth = (float) price2num($values['LMDBPROPALPV_DEFAULT_ELECTRICITY_GROWTH_PCT']);
	$subscription = (float) price2num($values['LMDBPROPALPV_DEFAULT_RETAIL_SUBSCRIPTION_KVA']);
	$values['LMDBPROPALPV_DEFAULT_SELF_CONSUMPTION_PCT'] = (string) price2num($values['LMDBPROPALPV_DEFAULT_SELF_CONSUMPTION_PCT']);
	$values['LMDBPROPALPV_DEFAULT_FIRST_YEAR_DEGRADATION_PCT'] = (string) price2num($values['LMDBPROPALPV_DEFAULT_FIRST_YEAR_DEGRADATION_PCT']);
	$values['LMDBPROPALPV_DEFAULT_PANEL_DEGRADATION_PCT'] = (string) price2num($values['LMDBPROPALPV_DEFAULT_PANEL_DEGRADATION_PCT']);
	$values['LMDBPROPALPV_DEFAULT_ELECTRICITY_GROWTH_PCT'] = (string) price2num($values['LMDBPROPALPV_DEFAULT_ELECTRICITY_GROWTH_PCT']);
	$values['LMDBPROPALPV_DEFAULT_RETAIL_SUBSCRIPTION_KVA'] = (string) price2num($values['LMDBPROPALPV_DEFAULT_RETAIL_SUBSCRIPTION_KVA']);
	if ($values['LMDBPROPALPV_DEFAULT_SELF_CONSUMPTION_PCT'] === '' || $values['LMDBPROPALPV_DEFAULT_FIRST_YEAR_DEGRADATION_PCT'] === '' || $values['LMDBPROPALPV_DEFAULT_PANEL_DEGRADATION_PCT'] === '' || $values['LMDBPROPALPV_DEFAULT_ELECTRICITY_GROWTH_PCT'] === '' || $selfConsumption < 0.0 || $selfConsumption > 100.0 || $firstYearDegradation < 0.0 || $firstYearDegradation >= 100.0 || $annualDegradation < 0.0 || $annualDegradation >= 100.0 || $growth <= -100.0) {
		setEventMessages($langs->trans('LmdbPropalPVInvalidDefaultPercentage'), null, 'errors');
		$error++;
	}
	if (!in_array($values['LMDBPROPALPV_DEFAULT_RETAIL_TARIFF_MODE'], array('base', 'peak', 'manual'), true)) {
		setEventMessages($langs->trans('LmdbPropalPVInvalidTariffMode'), null, 'errors');
		$error++;
	}
	if (!lmdbpropalpvSubscribedPowerIsSupported($subscription)) {
		setEventMessages($langs->trans('LmdbPropalPVInvalidSubscription'), null, 'errors');
		$error++;
	}
	if (!lmdbpropalpvBaseProposalModelNameIsSafe($values['LMDBPROPALPV_BASE_PROPOSAL_PDF_MODEL']) || !isset($baseProposalModelOptions[$values['LMDBPROPALPV_BASE_PROPOSAL_PDF_MODEL']])) {
		setEventMessages($langs->trans('LmdbPropalPVInvalidBaseProposalModel'), null, 'errors');
		$error++;
	}
	foreach (array('LMDBPROPALPV_PDF_PRIMARY_COLOR', 'LMDBPROPALPV_PDF_ACCENT_COLOR') as $colorName) {
		if (!preg_match('/^#[0-9A-F]{6}$/', $values[$colorName])) {
			setEventMessages($langs->trans('LmdbPropalPVInvalidColor', $colorName), null, 'errors');
			$error++;
		}
	}
	if (!$error) {
		$db->begin();
		foreach ($values as $name => $value) {
			if (dolibarr_set_const($db, $name, $value, 'chaine', 0, '', (int) $conf->entity) <= 0) {
				$error++;
				break;
			}
		}
		if (!$error && lmdbpropalpvNormalizeProposalModelMetadata($db, (int) $conf->entity, $langs) < 0) {
			$error++;
		}
		if (!$error && dolibarr_set_const($db, 'LMDBPROPALPV_DOCUMENT_MODEL_METADATA_FIXED', '1', 'chaine', 0, '', (int) $conf->entity) <= 0) {
			$error++;
		}
		if ($error) {
			$db->rollback();
		} else {
			$db->commit();
		}
	}
	if ($error) {
		setEventMessages($db->lasterror(), null, 'errors');
	} else {
		setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

$form = new Form($db);
$formother = new FormOther($db);
llxHeader('', $langs->trans('LmdbPropalPVSetup'));
print load_fiche_titre($langs->trans('LmdbPropalPVSetup'), lmdbpropalpvAdminLinkBack(), 'solar-panel');
print dol_get_fiche_head(lmdbpropalpvAdminPrepareHead(), 'settings', $langs->trans('LmdbPropalPVSetup'), -1, 'solar-panel');

print '<div class="info">'.$langs->trans('LmdbPropalPVDefaultAssumptionsHelp').'</div><br>';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="save">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th colspan="2">'.$langs->trans('LmdbPropalPVDefaultAssumptions').'</th></tr>';
lmdbpropalpvSetupNumberRow('LMDBPROPALPV_DEFAULT_SELF_CONSUMPTION_PCT', 'LmdbPropalPVSelfConsumption', '68', ' %');
lmdbpropalpvSetupNumberRow('LMDBPROPALPV_DEFAULT_FIRST_YEAR_DEGRADATION_PCT', 'LmdbPropalPVFirstYearDegradation', '0.45', ' %');
lmdbpropalpvSetupNumberRow('LMDBPROPALPV_DEFAULT_PANEL_DEGRADATION_PCT', 'LmdbPropalPVPanelDegradation', '0.45', ' %');
lmdbpropalpvSetupNumberRow('LMDBPROPALPV_DEFAULT_ELECTRICITY_GROWTH_PCT', 'LmdbPropalPVElectricityGrowth', '3', ' %');
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('LmdbPropalPVRetailTariffMode').'</td><td>';
print $form->selectarray('LMDBPROPALPV_DEFAULT_RETAIL_TARIFF_MODE', array('base' => $langs->trans('LmdbPropalPVBase'), 'peak' => $langs->trans('LmdbPropalPVPeakHours'), 'manual' => $langs->trans('LmdbPropalPVManual')), getDolGlobalString('LMDBPROPALPV_DEFAULT_RETAIL_TARIFF_MODE', 'base'), 0, 0, 0, '', 0, 0, 0, '', 'minwidth200');
print '</td></tr>';
print '<tr class="oddeven"><td class="titlefield">'.$form->textwithpicto($langs->trans('LmdbPropalPVSubscribedPower'), $langs->trans('LmdbPropalPVSubscribedPowerHelp'), 1, 'help').'</td><td>';
print $form->selectarray('LMDBPROPALPV_DEFAULT_RETAIL_SUBSCRIPTION_KVA', lmdbpropalpvGetSubscribedPowerOptions(), getDolGlobalString('LMDBPROPALPV_DEFAULT_RETAIL_SUBSCRIPTION_KVA', '6'), 0, 0, 0, '', 0, 0, 0, '', 'minwidth150');
print '</td></tr>';
print '<tr class="liste_titre"><th colspan="2">'.$langs->trans('LmdbPropalPVPdfAppearance').'</th></tr>';
$currentBaseProposalModel = getDolGlobalString('LMDBPROPALPV_BASE_PROPOSAL_PDF_MODEL', 'cyan');
$baseProposalModelSelectOptions = $baseProposalModelOptions;
if (!isset($baseProposalModelSelectOptions[$currentBaseProposalModel])) {
	$baseProposalModelSelectOptions[$currentBaseProposalModel] = $langs->trans('LmdbPropalPVUnavailableBaseProposalModel', $currentBaseProposalModel);
}
print '<tr class="oddeven"><td class="titlefield">'.$form->textwithpicto($langs->trans('LmdbPropalPVBaseProposalModel'), $langs->trans('LmdbPropalPVBaseProposalModelHelp'), 1, 'help').'</td><td>';
print $form->selectarray('LMDBPROPALPV_BASE_PROPOSAL_PDF_MODEL', $baseProposalModelSelectOptions, $currentBaseProposalModel, 0, 0, 0, '', 0, 0, 0, '', 'minwidth300');
print '</td></tr>';
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('LmdbPropalPVPdfPrimaryColor').'</td><td>';
print $formother->selectColor(getDolGlobalString('LMDBPROPALPV_PDF_PRIMARY_COLOR', '#16324F'), 'LMDBPROPALPV_PDF_PRIMARY_COLOR', '', 1, array(), 'maxwidth100', '', '#16324F');
print '</td></tr>';
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('LmdbPropalPVPdfAccentColor').'</td><td>';
print $formother->selectColor(getDolGlobalString('LMDBPROPALPV_PDF_ACCENT_COLOR', '#F2B705'), 'LMDBPROPALPV_PDF_ACCENT_COLOR', '', 1, array(), 'maxwidth100', '', '#F2B705');
print '</td></tr>';
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('LmdbPropalPVFinancialDisclaimer').'</td><td><textarea class="flat centpercent" rows="3" name="LMDBPROPALPV_FINANCIAL_DISCLAIMER">'.dol_escape_htmltag(getDolGlobalString('LMDBPROPALPV_FINANCIAL_DISCLAIMER')).'</textarea></td></tr>';
print '</table>';
print '<div class="center"><input class="button button-save" type="submit" value="'.$langs->trans('Save').'"></div>';
print '</form><br>';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th>'.$langs->trans('LmdbPropalPVDocumentModels').'</th><th>'.$langs->trans('LmdbPropalPVModelActive').'</th><th>'.$langs->trans('LmdbPropalPVModelDefault').'</th></tr>';
$models = array('lmdbpropalpv_withpictures' => 'LmdbPropalPVModelWithPictures', 'lmdbpropalpv_withoutpictures' => 'LmdbPropalPVModelWithoutPictures');
foreach ($models as $model => $label) {
	$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'document_model WHERE entity = '.((int) $conf->entity)." AND type = 'propal' AND nom = '".$db->escape($model)."'";
	$resql = $db->query($sql);
	$active = $resql && is_object($db->fetch_object($resql));
	if ($resql) {
		$db->free($resql);
	}
	print '<tr class="oddeven"><td>'.$langs->trans($label).'</td><td>'.yn($active).'</td><td>'.yn(getDolGlobalString('PROPALE_ADDON_PDF') === $model).'</td></tr>';
}
print '</table>';
print '<div class="right"><a class="button" href="'.DOL_URL_ROOT.'/admin/propal.php">'.$langs->trans('LmdbPropalPVDocumentModels').'</a></div>';
if (getDolGlobalInt('LMDBPROPALPV_INITIAL_PDF_SETUP_DONE')) {
	print '<div class="opacitymedium">'.$langs->trans('LmdbPropalPVInitialSetupDone').'</div>';
}

print dol_get_fiche_end();
llxFooter();
$db->close();

/** @return void */
function lmdbpropalpvSetupNumberRow($name, $label, $default, $suffix)
{
	global $langs;
	print '<tr class="oddeven"><td class="titlefield">'.$langs->trans($label).'</td><td><input class="flat maxwidth100" inputmode="decimal" name="'.dol_escape_htmltag($name).'" value="'.dol_escape_htmltag(getDolGlobalString($name, $default)).'">'.$suffix.'</td></tr>';
}
