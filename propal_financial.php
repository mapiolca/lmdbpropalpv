<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/comm/propal/class/propal.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/propal.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('/lmdbpropalpv/lib/lmdbpropalpv.lib.php');
dol_include_once('/lmdbpropalpv/class/lmdbpropalpvstudyservice.class.php');
dol_include_once('/lmdbpropalpv/class/lmdbpropalpvtariffresolver.class.php');

$langs->loadLangs(array('propal', 'bills', 'lmdbpropalpv@lmdbpropalpv'));
if (!isModEnabled('lmdbpropalpv')) {
	accessforbidden();
}

$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
$object = new Propal($db);
if ($id <= 0 || $object->fetch($id) <= 0) {
	accessforbidden($langs->trans('ErrorRecordNotFound'));
}
if (!in_array((int) $object->entity, array_map('intval', explode(',', getEntity('propal'))), true)) {
	accessforbidden();
}
if (!lmdbpropalpvCanDo($user, 'read', $object)) {
	accessforbidden();
}

$editable = (int) $object->statut === Propal::STATUS_DRAFT && lmdbpropalpvCanDo($user, 'write', $object);
$service = new LmdbPropalPVStudyService($db);
$study = $service->buildStudy($object);

if (in_array($action, array('save', 'reload_tariff', 'reload_panels', 'reload_battery_investment'), true)) {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$editable) {
		accessforbidden();
	}

	$referenceDate = lmdbpropalpvPostedDate('tariff_reference_date');
	$values = array(
		'annual_production_kwh' => (float) price2num(GETPOST('annual_production_kwh', 'alphanohtml')),
		'self_consumption_pct' => (float) price2num(GETPOST('self_consumption_pct', 'alphanohtml')),
		'first_year_degradation_pct' => (float) price2num(GETPOST('first_year_degradation_pct', 'alphanohtml')),
		'panel_degradation_pct' => (float) price2num(GETPOST('panel_degradation_pct', 'alphanohtml')),
		'electricity_growth_pct' => (float) price2num(GETPOST('electricity_growth_pct', 'alphanohtml')),
		'reference_date' => $referenceDate,
		'retail_mode' => GETPOST('retail_mode', 'alpha'),
		'subscription_kva' => (float) price2num(GETPOST('subscription_kva', 'alphanohtml')),
		'connection_phase_mode' => GETPOST('connection_phase_mode', 'alpha'),
		'retail_price_per_kwh' => (float) price2num(GETPOST('retail_price_per_kwh', 'alphanohtml'), 'MU'),
		'feed_in_price_per_kwh' => (float) price2num(GETPOST('feed_in_price_per_kwh', 'alphanohtml'), 'MU'),
		'premium_per_kwp' => (float) price2num(GETPOST('premium_per_kwp', 'alphanohtml'), 'MU'),
		'tariff_set_id' => GETPOSTINT('tariff_set_id'),
		'battery_self_consumption_pct' => lmdbpropalpvPostedOptionalNumber('battery_self_consumption_pct', ''),
		'battery_proposal_id' => GETPOSTINT('battery_proposal_id'),
		'battery_extra_investment_ttc' => lmdbpropalpvPostedOptionalNumber('battery_extra_investment_ttc', 'MT'),
	);
	if (!in_array($values['retail_mode'], array('base', 'peak', 'manual'), true)) {
		$values['retail_mode'] = 'base';
	}
	if (!in_array($values['connection_phase_mode'], array('single', 'three'), true)) {
		$values['connection_phase_mode'] = (string) $study['values']['connection_phase_mode'];
	}

	if ($action === 'reload_tariff') {
		$resolver = new LmdbPropalPVTariffResolver($db);
		$resolved = $resolver->resolveForProposal((int) $object->entity, $referenceDate, $study['currency_code'], $study['peak_power_kwp'], $values['retail_mode'], $values['subscription_kva']);
		if ($resolved['retail_price_per_kwh'] !== null) {
			$values['retail_price_per_kwh'] = $resolved['retail_price_per_kwh'];
		}
		if ($resolved['feed_in_price_per_kwh'] !== null) {
			$values['feed_in_price_per_kwh'] = $resolved['feed_in_price_per_kwh'];
		}
		if ($resolved['premium_per_kwp'] !== null) {
			$values['premium_per_kwp'] = $resolved['premium_per_kwp'];
		}
		$values['tariff_set_id'] = $resolved['tariff_set_id'];
		foreach ($resolved['errors'] as $errorKey) {
			setEventMessages($langs->trans($errorKey), null, 'warnings');
		}
	}
	$batterySelectionError = false;
	$storedBatteryProposalId = (int) $study['battery_proposal_id'];
	if ((int) $values['battery_proposal_id'] > 0) {
		if ($action === 'reload_battery_investment' || (int) $values['battery_proposal_id'] !== $storedBatteryProposalId) {
			$batterySource = $service->resolveBatteryProposalSnapshot($object, (int) $values['battery_proposal_id']);
			if ($batterySource === null) {
				$batterySelectionError = true;
				setEventMessages($langs->trans('LmdbPropalPVInvalidBatteryProposal'), null, 'errors');
			} else {
				$values['battery_extra_investment_ttc'] = $batterySource['amount_ttc'];
			}
		} else {
			$values['battery_extra_investment_ttc'] = $study['battery_extra_investment_ttc'] !== null ? $study['battery_extra_investment_ttc'] : '';
		}
	}
	if ($action === 'reload_panels') {
		$resolvedDegradation = $service->resolvePanelDegradation($object);
		$values['first_year_degradation_pct'] = $resolvedDegradation['first_year_degradation_pct'];
		$values['panel_degradation_pct'] = $resolvedDegradation['annual_degradation_pct'];
		foreach ($resolvedDegradation['warning_keys'] as $warningKey) {
			$references = implode(', ', $resolvedDegradation['fallback_product_refs']);
			setEventMessages($references !== '' ? $langs->trans($warningKey, $references) : $langs->trans($warningKey), null, 'warnings');
		}
	}
	if ((int) $values['tariff_set_id'] > 0) {
		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'lmdbpropalpv_tariff_set';
		$sql .= ' WHERE rowid = '.((int) $values['tariff_set_id']).' AND entity = '.((int) $object->entity);
		$sql .= " AND currency_code = '".$db->escape($study['currency_code'])."'";
		$resql = $db->query($sql);
		$validTariffSet = $resql && is_object($db->fetch_object($resql));
		if ($resql) {
			$db->free($resql);
		}
		if (!$validTariffSet) {
			$values['tariff_set_id'] = 0;
			setEventMessages($langs->trans('LmdbPropalPVNoPowerTariff'), null, 'warnings');
		}
	}

	if ($batterySelectionError) {
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.((int) $object->id));
		exit;
	}

	lmdbpropalpvAssignProposalOptions($object, $values);
	$result = $object->insertExtraFields('', $user);
	if ($result < 0) {
		setEventMessages($object->error, $object->errors, 'errors');
	} else {
		$successKey = $action === 'reload_tariff' ? 'LmdbPropalPVTariffReloaded' : ($action === 'reload_panels' ? 'LmdbPropalPVPanelCharacteristicsReloaded' : ($action === 'reload_battery_investment' ? 'LmdbPropalPVBatteryInvestmentReloaded' : 'LmdbPropalPVStudySaved'));
		setEventMessages($langs->trans($successKey), null, 'mesgs');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.((int) $object->id));
	exit;
}

$displayValues = $study['values'];
if ((float) $displayValues['retail_price_per_kwh'] <= 0.0 || ((float) $displayValues['feed_in_price_per_kwh'] === 0.0 && (int) $displayValues['tariff_set_id'] === 0)) {
	$resolver = new LmdbPropalPVTariffResolver($db);
	$suggestion = $resolver->resolveForProposal((int) $object->entity, $study['reference_date'], $study['currency_code'], $study['peak_power_kwp'], (string) $displayValues['retail_mode'], (float) $displayValues['subscription_kva']);
	foreach (array('retail_price_per_kwh', 'feed_in_price_per_kwh', 'premium_per_kwp') as $key) {
		if ($suggestion[$key] !== null) {
			$displayValues[$key] = $suggestion[$key];
		}
	}
	if ($suggestion['tariff_set_id'] > 0) {
		$displayValues['tariff_set_id'] = $suggestion['tariff_set_id'];
	}
}

$form = new Form($db);
llxHeader('', $object->ref.' - '.$langs->trans('FinancialStudyPV'));
$head = propal_prepare_head($object);
print dol_get_fiche_head($head, 'lmdbpropalpv', $langs->trans('Proposal'), -1, 'propal');
$linkback = '<a href="'.DOL_URL_ROOT.'/comm/propal/list.php?restore_lastsearch_values=1">'.$langs->trans('BackToList').'</a>';
$thirdpartyLoaded = $object->fetch_thirdparty() > 0;
$morehtmlref = '<div class="refidno">';
$morehtmlref .= $form->editfieldkey('RefCustomer', 'ref_client', $object->ref_client, $object, 0, 'string', '', 0, 1);
$morehtmlref .= $form->editfieldval('RefCustomer', 'ref_client', $object->ref_client, $object, 0, 'string', '', null, null, '', 1);
if ($thirdpartyLoaded && is_object($object->thirdparty)) {
	$morehtmlref .= '<br>'.$object->thirdparty->getNomUrl(1, 'customer');
	if (!getDolGlobalString('MAIN_DISABLE_OTHER_LINK') && (int) $object->thirdparty->id > 0) {
		$morehtmlref .= ' (<a href="'.DOL_URL_ROOT.'/comm/propal/list.php?socid='.((int) $object->thirdparty->id).'&search_societe='.urlencode((string) $object->thirdparty->name).'">'.$langs->trans('OtherProposals').'</a>)';
	}
}
if (isModEnabled('project') && !empty($object->fk_project)) {
	require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';
	$langs->load('projects');
	$project = new Project($db);
	if ($project->fetch((int) $object->fk_project) > 0) {
		$morehtmlref .= '<br>'.img_picto($langs->trans('Project'), 'project', 'class="pictofixedwidth"').$project->getNomUrl(1);
		if (!empty($project->title)) {
			$morehtmlref .= '<span class="opacitymedium"> - '.dol_escape_htmltag($project->title).'</span>';
		}
	}
}
$morehtmlref .= '</div>';
dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', $morehtmlref);

print '<div class="fichecenter"><div class="fichehalfleft"><table class="border centpercent">';
print '<tr><td class="titlefield">'.$langs->trans('LmdbPropalPVPeakPower').'</td><td><strong>'.price(price2num($study['peak_power_kwp'], 'MT')).' kWc</strong></td></tr>';
print '<tr><td>'.$langs->trans('LmdbPropalPVProposalAmount').'</td><td><strong>'.price(price2num($study['investment_ttc'], 'MT'), 0, $langs, 1, -1, -1, $study['currency_code']).'</strong></td></tr>';
print '</table></div><div class="fichehalfright"><table class="border centpercent">';
print '<tr><td class="titlefield">'.$langs->trans('Status').'</td><td>';
$missingLabels = array();
if ($study['complete']) {
	print '<span class="badge badge-status4">'.$langs->trans('LmdbPropalPVStudyComplete').'</span>';
} else {
	$missingLabels = array_map(static function ($key) use ($langs) { return $langs->trans($key); }, $study['missing']);
	print '<span class="badge badge-status8">'.$langs->trans('LmdbPropalPVStudyIncomplete').'</span>';
}
print '</td></tr>';
if (!$editable) {
	print '<tr><td>'.$langs->trans('Mode').'</td><td><span class="badge badge-status6">'.$langs->trans('LmdbPropalPVStudyReadOnly').'</span></td></tr>';
}
print '</table>';
if (!$study['complete']) {
	print '<div class="warning">'.dol_escape_htmltag(implode(', ', $missingLabels)).'</div>';
}
foreach ($study['degradation_warning_keys'] as $warningKey) {
	$references = implode(', ', $study['degradation_fallback_product_refs']);
	print '<div class="warning">'.dol_escape_htmltag($references !== '' ? $langs->trans($warningKey, $references) : $langs->trans($warningKey)).'</div>';
}
print '</div></div><div class="clearboth"></div><br>';

$withoutBatteryColor = lmdbpropalpvGetEntityStringConstant($db, 'LMDBPROPALPV_WITHOUT_BATTERY_COLOR', '#16324F', (int) $object->entity);
$batteryColor = lmdbpropalpvGetEntityStringConstant($db, 'LMDBPROPALPV_BATTERY_COLOR', '#2E7D32', (int) $object->entity);
$batteryOptionsData = $service->getBatteryProposalOptions($object);
$batterySelectOptions = array();
foreach ($batteryOptionsData as $batteryOptionId => $batteryOption) {
	$batterySelectOptions[$batteryOptionId] = $batteryOption['label'].' - '.price($batteryOption['amount_ttc'], 0, $langs, 1, -1, -1, $study['currency_code']);
}
if ((int) $study['battery_proposal_id'] > 0 && !isset($batterySelectOptions[(int) $study['battery_proposal_id']])) {
	$batterySelectOptions[(int) $study['battery_proposal_id']] = '#'.((int) $study['battery_proposal_id']).' - '.$langs->trans('LmdbPropalPVUnavailable');
}

print '<div class="lmdbpropalpv-study">';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.((int) $object->id).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="tariff_set_id" value="'.((int) $displayValues['tariff_set_id']).'">';

print '<div class="lmdbpropalpv-production"><table class="noborder centpercent">';
print '<tr class="liste_titre"><th colspan="2">'.$langs->trans('LmdbPropalPVAnnualProduction').'</th></tr>';
lmdbpropalpvInputRow($form, 'annual_production_kwh', 'LmdbPropalPVAnnualProduction', $displayValues['annual_production_kwh'], ' kWh', $editable, 'LmdbPropalPVAnnualProductionHelp');
print '</table></div>';

print lmdbpropalpvScenarioLegend($withoutBatteryColor, $batteryColor);
print '<div class="fichecenter lmdbpropalpv-comparison-layout">';
print '<div class="fichehalfleft"><table class="noborder centpercent">';
print '<tr class="liste_titre"><th colspan="2">'.$langs->trans('LmdbPropalPVSelfConsumptionWithoutBattery').'</th></tr>';
lmdbpropalpvInputRow($form, 'self_consumption_pct', 'LmdbPropalPVSelfConsumptionRateWithoutBattery', $displayValues['self_consumption_pct'], ' %', $editable, '', 'MT');
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('LmdbPropalPVInvestmentTtcWithoutBattery').'</td><td><strong>'.price(price2num($study['investment_ttc'], 'MT'), 0, $langs, 1, -1, -1, $study['currency_code']).'</strong></td></tr>';
print '</table></div>';

print '<div class="fichehalfright"><table class="noborder centpercent">';
print '<tr class="liste_titre"><th colspan="2">'.$langs->trans('LmdbPropalPVSelfConsumptionWithBattery').'</th></tr>';
lmdbpropalpvInputRow($form, 'battery_self_consumption_pct', 'LmdbPropalPVSelfConsumptionRateWithBattery', $displayValues['battery_self_consumption_pct'] ?? '', ' %', $editable, '', 'MT');
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('LmdbPropalPVBatteryProposal').'</td><td>';
if ($editable) {
	print $form->selectarray('battery_proposal_id', $batterySelectOptions, (int) $study['battery_proposal_id'], 1, 0, 0, '', 0, 0, 0, '', 'minwidth300');
	print ajax_combobox('battery_proposal_id');
} elseif ((int) $study['battery_proposal_id'] > 0) {
	$batteryProposal = new Propal($db);
	if ($batteryProposal->fetch((int) $study['battery_proposal_id']) > 0 && lmdbpropalpvCanDo($user, 'read', $batteryProposal)) {
		print $batteryProposal->getNomUrl(1);
	} else {
		print '<span class="opacitymedium">#'.((int) $study['battery_proposal_id']).'</span>';
	}
} else {
	print '<span class="opacitymedium">'.$langs->trans('LmdbPropalPVNoBatteryProposal').'</span>';
}
print '</td></tr>';
print '<tr class="oddeven"><td class="titlefield">'.$form->textwithpicto($langs->trans('LmdbPropalPVBatteryExtraInvestment'), $langs->trans('LmdbPropalPVBatteryExtraInvestmentHelp'), 1, 'help').'</td><td>';
if ($editable) {
	$readonlySnapshot = (int) $study['battery_proposal_id'] > 0 ? ' readonly="readonly"' : '';
	print '<input id="battery_extra_investment_ttc" class="flat maxwidth150" inputmode="decimal" name="battery_extra_investment_ttc" value="'.dol_escape_htmltag($study['battery_extra_investment_ttc'] === null ? '' : (string) $study['battery_extra_investment_ttc']).'"'.$readonlySnapshot.'> '.dol_escape_htmltag($study['currency_code']);
} elseif ($study['battery_extra_investment_ttc'] !== null) {
	print price(price2num((float) $study['battery_extra_investment_ttc'], 'MT'), 0, $langs, 1, -1, -1, $study['currency_code']);
} else {
	print '<span class="opacitymedium">'.$langs->trans('LmdbPropalPVNotConfigured').'</span>';
}
print '</td></tr>';
$batteryInvestmentDisplay = $study['battery_extra_investment_ttc'] !== null
	? '<strong>'.price(price2num($study['battery_investment_ttc'], 'MT'), 0, $langs, 1, -1, -1, $study['currency_code']).'</strong>'
	: '<span class="opacitymedium">'.$langs->trans('LmdbPropalPVNotConfigured').'</span>';
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('LmdbPropalPVInvestmentTtcWithBattery').'</td><td>'.$batteryInvestmentDisplay.'</td></tr>';
if ($editable && (int) $study['battery_proposal_id'] > 0) {
	print '<tr id="lmdbpropalpv-refresh-battery-row" class="oddeven"><td></td><td><button class="button small" type="submit" name="action" value="reload_battery_investment">'.$langs->trans('LmdbPropalPVRefreshBatteryInvestment').'</button></td></tr>';
}
print '</table>';
if ($editable) {
	print '<script>jQuery(function () { var proposal = jQuery("#battery_proposal_id"); var amount = jQuery("#battery_extra_investment_ttc"); var refreshRow = jQuery("#lmdbpropalpv-refresh-battery-row"); var syncBatterySource = function () { var hasSource = parseInt(proposal.val() || "0", 10) > 0; amount.prop("readonly", hasSource); refreshRow.toggle(hasSource); }; proposal.on("change", syncBatterySource); syncBatterySource(); });</script>';
}
if ($study['battery_configured'] && !$study['battery_complete']) {
	$batteryMissingLabels = array_map(static function ($key) use ($langs) { return $langs->trans($key); }, $study['battery_missing']);
	print '<div class="warning">'.dol_escape_htmltag($langs->trans('LmdbPropalPVBatteryScenarioIncomplete')).' : '.dol_escape_htmltag(implode(', ', $batteryMissingLabels)).'</div>';
}
foreach ($study['battery_warning_keys'] as $batteryWarningKey) {
	print '<div class="warning">'.dol_escape_htmltag($langs->trans($batteryWarningKey)).'</div>';
}
print '</div></div><div class="clearboth"></div>';

print '<div class="fichecenter lmdbpropalpv-comparison-layout lmdbpropalpv-secondary-layout">';
print '<div class="fichehalfleft"><table class="noborder centpercent">';
print '<tr class="liste_titre"><th colspan="2">'.$langs->trans('LmdbPropalPVDefaultAssumptions').'</th></tr>';
lmdbpropalpvInputRow($form, 'first_year_degradation_pct', 'LmdbPropalPVFirstYearDegradation', $displayValues['first_year_degradation_pct'], ' %', $editable, 'LmdbPropalPVFirstYearDegradationHelp', 'MT');
lmdbpropalpvInputRow($form, 'panel_degradation_pct', 'LmdbPropalPVPanelDegradation', $displayValues['panel_degradation_pct'], ' %', $editable, 'LmdbPropalPVPanelDegradationHelp', 'MT');
lmdbpropalpvInputRow($form, 'electricity_growth_pct', 'LmdbPropalPVElectricityGrowth', $displayValues['electricity_growth_pct'], ' %', $editable, '', 'MT');
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('LmdbPropalPVTariffReferenceDate').'</td><td>';
$dateTimestamp = dol_stringtotime((string) $displayValues['reference_date']);
print $form->selectDate($dateTimestamp, 'tariff_reference_date', 0, 0, 0, '', 1, 0, $editable ? 0 : 1);
print '</td></tr>';
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('LmdbPropalPVRetailTariffMode').'</td><td>';
if ($editable) {
	print $form->selectarray('retail_mode', array('base' => $langs->trans('LmdbPropalPVBase'), 'peak' => $langs->trans('LmdbPropalPVPeakHours'), 'manual' => $langs->trans('LmdbPropalPVManual')), (string) $displayValues['retail_mode'], 0, 0, 0, '', 0, 0, 0, '', 'minwidth200');
	print ajax_combobox('retail_mode');
} else {
	$modeLabel = (string) $displayValues['retail_mode'] === 'base' ? 'LmdbPropalPVBase' : ((string) $displayValues['retail_mode'] === 'peak' ? 'LmdbPropalPVPeakHours' : 'LmdbPropalPVManual');
	print dol_escape_htmltag($langs->trans($modeLabel));
}
print '</td></tr>';
print '<tr class="oddeven"><td class="titlefield">'.$form->textwithpicto($langs->trans('LmdbPropalPVSubscribedPower'), $langs->trans('LmdbPropalPVSubscribedPowerHelp'), 1, 'help').'</td><td>';
if ($editable) {
	print $form->selectarray('subscription_kva', lmdbpropalpvGetSubscribedPowerOptions(), (string) $displayValues['subscription_kva'], 0, 0, 0, '', 0, 0, 0, '', 'minwidth150');
	print ajax_combobox('subscription_kva');
} else {
	print price(price2num((float) $displayValues['subscription_kva'], 'MT')).' kVA';
}
print '</td></tr>';
print '<tr class="oddeven"><td class="titlefield">'.$form->textwithpicto($langs->trans('LmdbPropalPVConnectionPhaseMode'), $langs->trans('LmdbPropalPVConnectionPhaseModeHelp'), 1, 'help').'</td><td>';
$phaseLabels = array('single' => $langs->trans('LmdbPropalPVSinglePhase'), 'three' => $langs->trans('LmdbPropalPVThreePhase'));
if ($editable) {
	print $form->selectarray('connection_phase_mode', $phaseLabels, (string) $displayValues['connection_phase_mode'], 0, 0, 0, '', 0, 0, 0, '', 'minwidth200');
	print ajax_combobox('connection_phase_mode');
} else {
	print dol_escape_htmltag($phaseLabels[(string) $displayValues['connection_phase_mode']] ?? (string) $displayValues['connection_phase_mode']);
}
print '</td></tr>';
lmdbpropalpvInputRow($form, 'retail_price_per_kwh', 'LmdbPropalPVRetailPrice', $displayValues['retail_price_per_kwh'], ' '.$study['currency_code'].'/kWh', $editable, '', 'MU');
lmdbpropalpvInputRow($form, 'feed_in_price_per_kwh', 'LmdbPropalPVFeedInPrice', $displayValues['feed_in_price_per_kwh'], ' '.$study['currency_code'].'/kWh', $editable, '', 'MU');
lmdbpropalpvInputRow($form, 'premium_per_kwp', 'LmdbPropalPVPremiumPerKwp', $displayValues['premium_per_kwp'], ' '.$study['currency_code'].'/kWc', $editable, '', 'MU');
print '</table></div>';

print '<div class="fichehalfright">';
$connection = $study['connection_result'];
if ($connection instanceof LmdbPropalPVConnectionPowerResult) {
	$statusTranslation = array(
		LmdbPropalPVConnectionPowerResult::STATUS_COMPLIANT => 'LmdbPropalPVConnectionStatusCompliant',
		LmdbPropalPVConnectionPowerResult::STATUS_INCREASE_TO_CHECK => 'LmdbPropalPVConnectionStatusIncreaseToCheck',
		LmdbPropalPVConnectionPowerResult::STATUS_PHASE_INCOMPATIBLE => 'LmdbPropalPVConnectionStatusPhaseIncompatible',
		LmdbPropalPVConnectionPowerResult::STATUS_INCOMPLETE => 'LmdbPropalPVConnectionStatusIncomplete',
	);
	$statusClass = $connection->status === LmdbPropalPVConnectionPowerResult::STATUS_COMPLIANT ? 'badge-status4' : ($connection->status === LmdbPropalPVConnectionPowerResult::STATUS_INCREASE_TO_CHECK ? 'badge-status3' : 'badge-status8');
	$inverterPowerLabel = $connection->inverterNominalPowerKva === null
		? $langs->trans('LmdbPropalPVUnavailable')
		: price(price2num($connection->inverterNominalPowerKva, 'MT')).' kVA'.(!$connection->inverterDataComplete ? ' ('.$langs->trans('LmdbPropalPVPartialValue').')' : '');
	$recommendedLabel = $connection->recommendedSubscribedPowerKva === null
		? $langs->trans('LmdbPropalPVSpecificConnectionStudy')
		: price(price2num($connection->recommendedSubscribedPowerKva, 'MT')).' kVA';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><th colspan="2">'.$langs->trans('LmdbPropalPVConnectionPowerCheck').'</th></tr>';
	print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('LmdbPropalPVPeakPower').'</td><td>'.price(price2num($connection->peakPowerKwp, 'MT')).' kWc</td></tr>';
	print '<tr class="oddeven"><td>'.$form->textwithpicto($langs->trans('LmdbPropalPVInverterNominalPower'), $langs->trans('LmdbPropalPVInverterNominalPowerHelp'), 1, 'help').'</td><td>'.dol_escape_htmltag($inverterPowerLabel).'</td></tr>';
	print '<tr class="oddeven"><td>'.$form->textwithpicto($langs->trans('LmdbPropalPVConnectionReferencePower'), $langs->trans('LmdbPropalPVConnectionReferencePowerHelp'), 1, 'help').'</td><td><strong>'.price(price2num($connection->referencePowerKva, 'MT')).' kVA</strong></td></tr>';
	print '<tr class="oddeven"><td>'.$langs->trans('LmdbPropalPVRecommendedSubscribedPower').'</td><td>'.dol_escape_htmltag($recommendedLabel).'</td></tr>';
	print '<tr class="oddeven"><td>'.$langs->trans('Status').'</td><td><span class="badge '.$statusClass.'">'.dol_escape_htmltag($langs->trans($statusTranslation[$connection->status] ?? 'LmdbPropalPVConnectionStatusIncomplete')).'</span></td></tr>';
	print '</table>';
	foreach ($study['connection_warning_keys'] as $warningKey) {
		$references = implode(', ', $study['connection_product_refs']);
		$warning = $references !== '' && $warningKey === 'LmdbPropalPVConnectionInverterDataUnavailable' ? $langs->trans($warningKey, $references) : $langs->trans($warningKey);
		print '<div class="warning">'.dol_escape_htmltag($warning).'</div>';
	}
}
print '</div></div><div class="clearboth"></div>';
if ($editable) {
	print '<div class="center lmdbpropalpv-actions"><button class="button button-save" type="submit" name="action" value="save">'.$langs->trans('Save').'</button> ';
	print '<button class="button" type="submit" name="action" value="reload_panels">'.$langs->trans('LmdbPropalPVReloadPanelCharacteristics').'</button> ';
	print '<button class="button" type="submit" name="action" value="reload_tariff">'.$langs->trans('LmdbPropalPVReloadTariff').'</button></div>';
}
print '</form>';

if ($study['complete'] && $study['result'] instanceof LmdbPropalPVFinancialResult) {
	$result = $study['result'];
	$batteryResult = $study['battery_result'] instanceof LmdbPropalPVFinancialResult ? $study['battery_result'] : null;
	$projectionYears = (int) $study['projection_years'];
	print '<br>'.load_fiche_titre($langs->trans('LmdbPropalPVProjectionYearsTitle', $projectionYears), '', 'chart-area');
	print lmdbpropalpvScenarioLegend($withoutBatteryColor, $batteryColor);
	print '<div class="fichecenter"><div class="fichehalfleft"><table class="border centpercent">';
	print '<tr><td class="titlefield">'.$langs->trans('LmdbPropalPVTotalProduction').'</td><td><strong>'.price(price2num($result->totalProductionKwh, 'MT')).' kWh</strong></td></tr>';
	$leftMetrics = array(
		'LmdbPropalPVTotalSavings' => array($result->totalElectricitySavings, $batteryResult !== null ? $batteryResult->totalElectricitySavings : null, 'money'),
		'LmdbPropalPVTotalSales' => array($result->totalSurplusSale, $batteryResult !== null ? $batteryResult->totalSurplusSale : null, 'money'),
		'LmdbPropalPVTotalPremium' => array($result->totalPremium, null, 'singlemoney'),
		'LmdbPropalPVGrossGain' => array($result->totalGrossGain, $batteryResult !== null ? $batteryResult->totalGrossGain : null, 'money'),
	);
	foreach ($leftMetrics as $metricLabel => $metricValues) {
		if ($metricValues[2] === 'singlemoney') {
			$metricDisplay = '<strong>'.price(price2num((float) $metricValues[0], 'MT'), 0, $langs, 1, -1, -1, $study['currency_code']).'</strong>';
		} else {
			$metricDisplay = lmdbpropalpvComparisonBadges(lmdbpropalpvFormatMetric((float) $metricValues[0], 'money', $study['currency_code']), $metricValues[1] !== null ? lmdbpropalpvFormatMetric((float) $metricValues[1], 'money', $study['currency_code']) : null, $withoutBatteryColor, $batteryColor, true);
		}
		print '<tr><td class="titlefield">'.$langs->trans($metricLabel).'</td><td>'.$metricDisplay.'</td></tr>';
	}
	print '</table></div><div class="fichehalfright"><table class="border centpercent">';
	$rightMetrics = array(
		array($langs->trans('LmdbPropalPVNetGainAtYears', $projectionYears), $result->netGain, $batteryResult !== null ? $batteryResult->netGain : null, 'money'),
		array($langs->trans('LmdbPropalPVROIAtYears', $projectionYears), $result->roiRate * 100.0, $batteryResult !== null ? $batteryResult->roiRate * 100.0 : null, 'percent'),
		array($langs->trans('LmdbPropalPVAverageAnnualReturn'), $result->averageAnnualReturnRate * 100.0, $batteryResult !== null ? $batteryResult->averageAnnualReturnRate * 100.0 : null, 'percent'),
		array($langs->trans('LmdbPropalPVPayback'), $result->paybackYears, $batteryResult !== null ? $batteryResult->paybackYears : null, 'payback'),
		array($langs->trans('LmdbPropalPVSimplifiedProductionCost'), $result->simplifiedProductionCostPerKwh, $batteryResult !== null ? $batteryResult->simplifiedProductionCostPerKwh : null, 'cost'),
	);
	foreach ($rightMetrics as $metric) {
		$withoutValue = lmdbpropalpvFormatMetric($metric[1], $metric[3], $study['currency_code'], $projectionYears);
		$withValue = $batteryResult !== null ? lmdbpropalpvFormatMetric($metric[2], $metric[3], $study['currency_code'], $projectionYears) : null;
		print '<tr><td class="titlefield">'.$metric[0].'</td><td>'.lmdbpropalpvComparisonBadges($withoutValue, $withValue, $withoutBatteryColor, $batteryColor, true).'</td></tr>';
	}
	print '</table></div></div><div class="clearboth"></div><br>';
	print lmdbpropalpvCashflowGraph($result, $batteryResult, $study['currency_code'], (int) $object->entity);
	print '<div class="div-table-responsive"><table class="noborder centpercent lmdbpropalpv-projection-table">';
	print '<tr class="liste_titre"><th>'.$langs->trans('LmdbPropalPVYear').'</th><th class="right">'.$langs->trans('LmdbPropalPVProduction').'</th><th class="right">'.$langs->trans('LmdbPropalPVNetworkPrice').'</th><th class="right">'.$langs->trans('LmdbPropalPVSurplusSale').'</th><th class="right">'.$langs->trans('LmdbPropalPVElectricitySavings').'</th><th class="right">'.$langs->trans('LmdbPropalPVPremium').'</th><th class="right">'.$langs->trans('LmdbPropalPVAnnualGain').'</th><th class="right">'.$langs->trans('LmdbPropalPVCumulativeCashflow').'</th><th class="right">'.$langs->trans('LmdbPropalPVAnnualReturn').'</th></tr>';
	foreach ($result->years as $yearIndex => $year) {
		$batteryYear = $batteryResult !== null && isset($batteryResult->years[$yearIndex]) ? $batteryResult->years[$yearIndex] : null;
		print '<tr class="oddeven"><td>'.((int) $year->year).'</td>';
		print '<td class="right">'.price(price2num($year->productionKwh, 'MT')).'</td>';
		print '<td class="right">'.price(price2num($year->retailPricePerKwh, 'MU')).'</td>';
		print '<td class="right">'.lmdbpropalpvComparisonBadges(lmdbpropalpvFormatMetric($year->surplusSale, 'money', $study['currency_code']), $batteryYear !== null ? lmdbpropalpvFormatMetric($batteryYear->surplusSale, 'money', $study['currency_code']) : null, $withoutBatteryColor, $batteryColor).'</td>';
		print '<td class="right">'.lmdbpropalpvComparisonBadges(lmdbpropalpvFormatMetric($year->electricitySavings, 'money', $study['currency_code']), $batteryYear !== null ? lmdbpropalpvFormatMetric($batteryYear->electricitySavings, 'money', $study['currency_code']) : null, $withoutBatteryColor, $batteryColor).'</td>';
		print '<td class="right">'.price(price2num($year->premium, 'MT'), 0, $langs, 1, -1, -1, $study['currency_code']).'</td>';
		print '<td class="right">'.lmdbpropalpvComparisonBadges(lmdbpropalpvFormatMetric($year->annualGain, 'money', $study['currency_code']), $batteryYear !== null ? lmdbpropalpvFormatMetric($batteryYear->annualGain, 'money', $study['currency_code']) : null, $withoutBatteryColor, $batteryColor).'</td>';
		print '<td class="right">'.lmdbpropalpvComparisonBadges(lmdbpropalpvFormatMetric($year->cumulativeCashflow, 'money', $study['currency_code']), $batteryYear !== null ? lmdbpropalpvFormatMetric($batteryYear->cumulativeCashflow, 'money', $study['currency_code']) : null, $withoutBatteryColor, $batteryColor).'</td>';
		print '<td class="right">'.lmdbpropalpvComparisonBadges(lmdbpropalpvFormatMetric($year->annualReturnRate * 100.0, 'percent', $study['currency_code']), $batteryYear !== null ? lmdbpropalpvFormatMetric($batteryYear->annualReturnRate * 100.0, 'percent', $study['currency_code']) : null, $withoutBatteryColor, $batteryColor).'</td></tr>';
	}
	print '</table></div>';
}
print '</div>';

print dol_get_fiche_end();
llxFooter();
$db->close();

/** @return string */
function lmdbpropalpvPostedDate($prefix)
{
	$timestamp = dol_mktime(0, 0, 0, GETPOSTINT($prefix.'month'), GETPOSTINT($prefix.'day'), GETPOSTINT($prefix.'year'));
	return $timestamp > 0 ? dol_print_date($timestamp, '%Y-%m-%d') : '';
}

/**
 * Read an optional decimal number while preserving an empty field.
 *
 * @param string $name      Input name
 * @param string $priceType Optional Dolibarr price normalization type
 * @return float|string
 */
function lmdbpropalpvPostedOptionalNumber($name, $priceType = '')
{
	$value = trim((string) GETPOST($name, 'alphanohtml'));
	if ($value === '') {
		return '';
	}

	return (float) price2num($value, $priceType);
}

/** @param Propal $object @param array<string,mixed> $values @return void */
function lmdbpropalpvAssignProposalOptions($object, array $values)
{
	$mapping = array(
		'annual_production_kwh' => 'lmdbpropalpv_annual_production_kwh',
		'self_consumption_pct' => 'lmdbpropalpv_self_consumption_pct',
		'first_year_degradation_pct' => 'lmdbpropalpv_first_year_degradation_pct',
		'panel_degradation_pct' => 'lmdbpropalpv_panel_degradation_pct',
		'electricity_growth_pct' => 'lmdbpropalpv_electricity_growth_pct',
		'reference_date' => 'lmdbpropalpv_tariff_reference_date',
		'retail_mode' => 'lmdbpropalpv_retail_tariff_mode',
		'subscription_kva' => 'lmdbpropalpv_retail_subscription_kva',
		'connection_phase_mode' => 'lmdbpropalpv_connection_phase_mode',
		'retail_price_per_kwh' => 'lmdbpropalpv_retail_price_per_kwh',
		'feed_in_price_per_kwh' => 'lmdbpropalpv_feed_in_price_per_kwh',
		'premium_per_kwp' => 'lmdbpropalpv_premium_per_kwp',
		'tariff_set_id' => 'lmdbpropalpv_tariff_set_id',
		'battery_self_consumption_pct' => 'lmdbpropalpv_battery_self_consumption_pct',
		'battery_proposal_id' => 'lmdbpropalpv_fk_battery_propal',
		'battery_extra_investment_ttc' => 'lmdbpropalpv_battery_extra_investment_ttc',
	);
	foreach ($mapping as $key => $extrafield) {
		$object->array_options['options_'.$extrafield] = $values[$key];
	}
}

/** @return void */
function lmdbpropalpvInputRow(Form $form, $name, $label, $value, $suffix, $editable, $help = '', $priceType = '')
{
	global $langs;
	$translatedLabel = $langs->trans($label);
	print '<tr class="oddeven"><td class="titlefield">'.($help !== '' ? $form->textwithpicto($translatedLabel, $langs->trans($help), 1, 'help') : $translatedLabel).'</td><td>';
	if ($editable) {
		print '<input class="flat maxwidth150" inputmode="decimal" name="'.dol_escape_htmltag($name).'" value="'.dol_escape_htmltag((string) $value).'">';
	} else {
		print price($priceType !== '' ? price2num((float) $value, $priceType) : (float) $value);
	}
	print dol_escape_htmltag($suffix).'</td></tr>';
}

/**
 * Format one projection metric with Dolibarr helpers.
 *
 * @param float|null $value           Raw value
 * @param string     $type            money|percent|payback|cost
 * @param string     $currencyCode    Currency
 * @param int        $projectionYears Projection horizon
 * @return string
 */
function lmdbpropalpvFormatMetric($value, $type, $currencyCode, $projectionYears = 20)
{
	global $langs;

	if ($type === 'payback') {
		return $value === null
			? $langs->trans('LmdbPropalPVPaybackNotReachedAtYears', (int) $projectionYears)
			: price(price2num((float) $value, 'MT')).' '.$langs->trans('LmdbPropalPVYears');
	}
	if ($type === 'percent') {
		return price(price2num((float) $value, 'MT')).' %';
	}
	if ($type === 'cost') {
		return price(price2num((float) $value, 'MU'), 0, $langs, 1, 3, 3).' '.$currencyCode.'/kWh';
	}

	return price(price2num((float) $value, 'MT'), 0, $langs, 1, -1, -1, $currencyCode);
}

/** @return string */
function lmdbpropalpvScenarioLegend($withoutColor, $withColor)
{
	global $langs;

	$withoutColor = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $withoutColor) ? (string) $withoutColor : '#16324F';
	$withColor = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $withColor) ? (string) $withColor : '#2E7D32';

	return '<div class="lmdbpropalpv-scenario-legend">'
		.'<span><i style="background:'.dol_escape_htmltag($withoutColor).'"></i>'.dol_escape_htmltag($langs->trans('LmdbPropalPVWithoutBattery')).'</span>'
		.'<span><i style="background:'.dol_escape_htmltag($withColor).'"></i>'.dol_escape_htmltag($langs->trans('LmdbPropalPVWithBattery')).'</span>'
		.'</div>';
}

/** @return string */
function lmdbpropalpvComparisonBadges($withoutValue, $withValue, $withoutColor, $withColor, $large = false)
{
	$sizeClass = $large ? ' lmdbpropalpv-scenario-badge-large' : '';
	$html = '<span class="lmdbpropalpv-comparison-badges'.$sizeClass.'">';
	$html .= lmdbpropalpvScenarioBadge($withoutValue, $withoutColor);
	if ($withValue !== null) {
		$html .= lmdbpropalpvScenarioBadge($withValue, $withColor);
	}
	$html .= '</span>';

	return $html;
}

/** @return string */
function lmdbpropalpvScenarioBadge($value, $color)
{
	$normalizedColor = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $color) ? (string) $color : '#16324F';
	$red = hexdec(substr($normalizedColor, 1, 2));
	$green = hexdec(substr($normalizedColor, 3, 2));
	$blue = hexdec(substr($normalizedColor, 5, 2));
	$textColor = (($red * 299 + $green * 587 + $blue * 114) / 1000) >= 150 ? '#111111' : '#FFFFFF';

	return '<span class="lmdbpropalpv-scenario-badge" style="background-color:'.dol_escape_htmltag($normalizedColor).';color:'.$textColor.'">'
		.dol_escape_htmltag((string) $value).'</span>';
}

/** @return string */
function lmdbpropalpvCashflowGraph(LmdbPropalPVFinancialResult $result, $batteryResult, $currencyCode, $entity)
{
	global $db, $langs;

	$projectionYears = max(1, (int) $result->projectionYears);
	$series = array(
		array('result' => $result, 'color' => lmdbpropalpvGetEntityStringConstant($db, 'LMDBPROPALPV_WITHOUT_BATTERY_COLOR', '#16324F', (int) $entity), 'label' => $langs->trans('LmdbPropalPVWithoutBattery')),
	);
	if ($batteryResult instanceof LmdbPropalPVFinancialResult) {
		$series[] = array('result' => $batteryResult, 'color' => lmdbpropalpvGetEntityStringConstant($db, 'LMDBPROPALPV_BATTERY_COLOR', '#2E7D32', (int) $entity), 'label' => $langs->trans('LmdbPropalPVWithBattery'));
	}
	$allValues = array(0.0);
	foreach ($series as $scenario) {
		/** @var LmdbPropalPVFinancialResult $scenarioResult */
		$scenarioResult = $scenario['result'];
		$allValues[] = $scenarioResult->initialCashflow;
		foreach ($scenarioResult->years as $scenarioYear) {
			$allValues[] = $scenarioYear->cumulativeCashflow;
		}
	}
	$rawMin = min($allValues);
	$rawMax = max($allValues);
	$rawSpan = max(1.0, $rawMax - $rawMin);
	$magnitude = pow(10.0, floor(log10($rawSpan / 5.0)));
	$normalized = ($rawSpan / 5.0) / $magnitude;
	$step = ($normalized <= 1.0 ? 1.0 : ($normalized <= 2.0 ? 2.0 : ($normalized <= 5.0 ? 5.0 : 10.0))) * $magnitude;
	$min = floor(min(0.0, $rawMin) / $step) * $step;
	$max = ceil(max(0.0, $rawMax) / $step) * $step;
	if ($max <= $min) {
		$max = $min + $step;
	}
	$span = $max - $min;
	$left = 76.0;
	$top = 34.0;
	$plotWidth = 744.0;
	$plotHeight = 216.0;
	$zeroY = $top + $plotHeight - ((0.0 - $min) / $span) * $plotHeight;
	$svg = '<div class="center"><svg xmlns="http://www.w3.org/2000/svg" width="850" height="300" role="img" aria-label="'.dol_escape_htmltag($langs->trans('LmdbPropalPVCumulativeCashflow')).'" viewBox="0 0 850 300" class="centpercent" style="max-height:350px">';
	$svg .= '<rect x="0" y="0" width="850" height="300" fill="#fff"/>';
	$svg .= '<text x="448" y="17" text-anchor="middle" font-size="14" font-weight="bold" fill="#333">'.dol_escape_htmltag($langs->trans('LmdbPropalPVCumulativeCashflow')).'</text>';
	$xTickStep = $projectionYears <= 20 ? 1 : (int) ceil($projectionYears / 10.0);
	for ($year = 0; $year <= $projectionYears; $year += $xTickStep) {
		$x = $left + ($year / $projectionYears) * $plotWidth;
		$svg .= '<line x1="'.$x.'" y1="'.$top.'" x2="'.$x.'" y2="'.($top + $plotHeight).'" stroke="#d9dde2" stroke-width="1"/>';
		$svg .= '<text x="'.$x.'" y="272" text-anchor="middle" font-size="11" fill="#555">'.$year.'</text>';
	}
	if ($projectionYears % $xTickStep !== 0) {
		$x = $left + $plotWidth;
		$svg .= '<line x1="'.$x.'" y1="'.$top.'" x2="'.$x.'" y2="'.($top + $plotHeight).'" stroke="#d9dde2" stroke-width="1"/>';
		$svg .= '<text x="'.$x.'" y="272" text-anchor="middle" font-size="11" fill="#555">'.$projectionYears.'</text>';
	}
	for ($tick = $min; $tick <= $max + ($step / 2.0); $tick += $step) {
		$y = $top + $plotHeight - (($tick - $min) / $span) * $plotHeight;
		$svg .= '<line x1="'.$left.'" y1="'.$y.'" x2="'.($left + $plotWidth).'" y2="'.$y.'" stroke="#d9dde2" stroke-width="1"/>';
		$svg .= '<text x="68" y="'.($y + 4.0).'" text-anchor="end" font-size="11" fill="#555">'.dol_escape_htmltag(price(price2num($tick, 'MT'))).'</text>';
	}
	$svg .= '<text x="18" y="15" font-size="11" fill="#555">'.dol_escape_htmltag((string) $currencyCode).'</text>';
	$previousPaybackX = null;
	foreach ($series as $scenarioIndex => $scenario) {
		/** @var LmdbPropalPVFinancialResult $scenarioResult */
		$scenarioResult = $scenario['result'];
		$scenarioValues = array($scenarioResult->initialCashflow);
		foreach ($scenarioResult->years as $scenarioYear) {
			$scenarioValues[] = $scenarioYear->cumulativeCashflow;
		}
		$points = array();
		foreach ($scenarioValues as $index => $value) {
			$x = $left + ($index / $projectionYears) * $plotWidth;
			$y = $top + $plotHeight - (($value - $min) / $span) * $plotHeight;
			$points[] = $x.','.$y;
		}
		$color = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $scenario['color']) ? (string) $scenario['color'] : '#16324F';
		$svg .= '<polyline points="'.dol_escape_htmltag(implode(' ', $points)).'" fill="none" stroke="'.dol_escape_htmltag($color).'" stroke-width="4" stroke-linejoin="round" stroke-linecap="round"/>';
		if ($scenarioResult->paybackYears !== null) {
			$paybackX = $left + ($scenarioResult->paybackYears / $projectionYears) * $plotWidth;
			$labelY = $top + 15.0 + ($scenarioIndex * 18.0);
			if ($previousPaybackX !== null && abs($previousPaybackX - $paybackX) < 105.0) {
				$labelY += 18.0;
			}
			$previousPaybackX = $paybackX;
			$svg .= '<line x1="'.$paybackX.'" y1="'.$top.'" x2="'.$paybackX.'" y2="'.($top + $plotHeight).'" stroke="'.dol_escape_htmltag($color).'" stroke-width="2" stroke-dasharray="7 5"/>';
			$svg .= '<circle cx="'.$paybackX.'" cy="'.$zeroY.'" r="5" fill="'.dol_escape_htmltag($color).'"/>';
			$paybackLabel = (string) $scenario['label'].' - '.price(price2num($scenarioResult->paybackYears, 'MT')).' '.$langs->trans('LmdbPropalPVYears');
			$anchor = $paybackX > ($left + $plotWidth * 0.65) ? 'end' : 'start';
			$labelX = $anchor === 'end' ? $paybackX - 7.0 : $paybackX + 7.0;
			$svg .= '<text x="'.$labelX.'" y="'.$labelY.'" text-anchor="'.$anchor.'" font-size="11" font-weight="bold" fill="'.dol_escape_htmltag($color).'">'.dol_escape_htmltag($paybackLabel).'</text>';
		}
	}
	$svg .= '</svg></div>';

	return $svg;
}
