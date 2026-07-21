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

if (in_array($action, array('save', 'reload_tariff', 'reload_panels'), true)) {
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
		'retail_price_per_kwh' => (float) price2num(GETPOST('retail_price_per_kwh', 'alphanohtml'), 'MU'),
		'feed_in_price_per_kwh' => (float) price2num(GETPOST('feed_in_price_per_kwh', 'alphanohtml'), 'MU'),
		'premium_per_kwp' => (float) price2num(GETPOST('premium_per_kwp', 'alphanohtml'), 'MU'),
		'tariff_set_id' => GETPOSTINT('tariff_set_id'),
	);
	if (!in_array($values['retail_mode'], array('base', 'peak', 'manual'), true)) {
		$values['retail_mode'] = 'base';
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

	lmdbpropalpvAssignProposalOptions($object, $values);
	$result = $object->insertExtraFields('', $user);
	if ($result < 0) {
		setEventMessages($object->error, $object->errors, 'errors');
	} else {
		$successKey = $action === 'reload_tariff' ? 'LmdbPropalPVTariffReloaded' : ($action === 'reload_panels' ? 'LmdbPropalPVPanelCharacteristicsReloaded' : 'LmdbPropalPVStudySaved');
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
llxHeader('', $langs->trans('FinancialStudyPV'));
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
print '<tr><td class="titlefield">'.$langs->trans('LmdbPropalPVPeakPower').'</td><td><strong>'.price($study['peak_power_kwp']).' kWc</strong></td></tr>';
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

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.((int) $object->id).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="tariff_set_id" value="'.((int) $displayValues['tariff_set_id']).'">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th colspan="2">'.$langs->trans('LmdbPropalPVDefaultAssumptions').'</th></tr>';
lmdbpropalpvInputRow('annual_production_kwh', 'LmdbPropalPVAnnualProduction', $displayValues['annual_production_kwh'], ' kWh', $editable, 'LmdbPropalPVAnnualProductionHelp');
lmdbpropalpvInputRow('self_consumption_pct', 'LmdbPropalPVSelfConsumption', $displayValues['self_consumption_pct'], ' %', $editable);
lmdbpropalpvInputRow('first_year_degradation_pct', 'LmdbPropalPVFirstYearDegradation', $displayValues['first_year_degradation_pct'], ' %', $editable, 'LmdbPropalPVFirstYearDegradationHelp');
lmdbpropalpvInputRow('panel_degradation_pct', 'LmdbPropalPVPanelDegradation', $displayValues['panel_degradation_pct'], ' %', $editable, 'LmdbPropalPVPanelDegradationHelp');
lmdbpropalpvInputRow('electricity_growth_pct', 'LmdbPropalPVElectricityGrowth', $displayValues['electricity_growth_pct'], ' %', $editable);
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('LmdbPropalPVTariffReferenceDate').'</td><td>';
$dateTimestamp = dol_stringtotime((string) $displayValues['reference_date']);
print $form->selectDate($dateTimestamp, 'tariff_reference_date', 0, 0, 0, '', 1, 0, $editable ? 0 : 1);
print '</td></tr>';
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('LmdbPropalPVRetailTariffMode').'</td><td>';
if ($editable) {
	print $form->selectarray('retail_mode', array('base' => $langs->trans('LmdbPropalPVBase'), 'peak' => $langs->trans('LmdbPropalPVPeakHours'), 'manual' => $langs->trans('LmdbPropalPVManual')), (string) $displayValues['retail_mode'], 0, 0, 0, '', 0, 0, 0, '', 'minwidth200');
} else {
	$modeLabel = (string) $displayValues['retail_mode'] === 'base' ? 'LmdbPropalPVBase' : ((string) $displayValues['retail_mode'] === 'peak' ? 'LmdbPropalPVPeakHours' : 'LmdbPropalPVManual');
	print dol_escape_htmltag($langs->trans($modeLabel));
}
print '</td></tr>';
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('LmdbPropalPVSubscribedPower').'</td><td>';
if ($editable) {
	print $form->selectarray('subscription_kva', array(3 => '3 kVA', 6 => '6 kVA', 9 => '9 kVA', 12 => '12 kVA', 15 => '15 kVA', 18 => '18 kVA', 24 => '24 kVA', 30 => '30 kVA', 36 => '36 kVA'), (string) $displayValues['subscription_kva'], 0, 0, 0, '', 0, 0, 0, '', 'minwidth150');
} else {
	print price($displayValues['subscription_kva']).' kVA';
}
print '</td></tr>';
lmdbpropalpvInputRow('retail_price_per_kwh', 'LmdbPropalPVRetailPrice', $displayValues['retail_price_per_kwh'], ' '.$study['currency_code'].'/kWh', $editable, '', 'MU');
lmdbpropalpvInputRow('feed_in_price_per_kwh', 'LmdbPropalPVFeedInPrice', $displayValues['feed_in_price_per_kwh'], ' '.$study['currency_code'].'/kWh', $editable, '', 'MU');
lmdbpropalpvInputRow('premium_per_kwp', 'LmdbPropalPVPremiumPerKwp', $displayValues['premium_per_kwp'], ' '.$study['currency_code'].'/kWc', $editable, '', 'MU');
print '</table>';
if ($editable) {
	print '<div class="center"><button class="button button-save" type="submit" name="action" value="save">'.$langs->trans('Save').'</button> ';
	print '<button class="button" type="submit" name="action" value="reload_panels">'.$langs->trans('LmdbPropalPVReloadPanelCharacteristics').'</button> ';
	print '<button class="button" type="submit" name="action" value="reload_tariff">'.$langs->trans('LmdbPropalPVReloadTariff').'</button></div>';
}
print '</form>';

if ($study['complete'] && $study['result'] instanceof LmdbPropalPVFinancialResult) {
	$result = $study['result'];
	print '<br>'.load_fiche_titre($langs->trans('LmdbPropalPVProjection20Years'), '', 'chart-area');
	print '<div class="fichecenter"><div class="fichehalfleft"><table class="border centpercent">';
	$metrics = array(
		'LmdbPropalPVTotalProduction' => price($result->totalProductionKwh).' kWh',
		'LmdbPropalPVTotalSavings' => price(price2num($result->totalElectricitySavings, 'MT'), 0, $langs, 1, -1, -1, $study['currency_code']),
		'LmdbPropalPVTotalSales' => price(price2num($result->totalSurplusSale, 'MT'), 0, $langs, 1, -1, -1, $study['currency_code']),
		'LmdbPropalPVTotalPremium' => price(price2num($result->totalPremium, 'MT'), 0, $langs, 1, -1, -1, $study['currency_code']),
		'LmdbPropalPVGrossGain' => price(price2num($result->totalGrossGain, 'MT'), 0, $langs, 1, -1, -1, $study['currency_code']),
	);
	foreach ($metrics as $label => $value) {
		print '<tr><td class="titlefield">'.$langs->trans($label).'</td><td><strong>'.$value.'</strong></td></tr>';
	}
	print '</table></div><div class="fichehalfright"><table class="border centpercent">';
	$payback = $result->paybackYears === null ? $langs->trans('LmdbPropalPVPaybackNotReached') : price($result->paybackYears).' '.$langs->trans('LmdbPropalPVYears');
	$metrics = array(
		'LmdbPropalPVNetGain' => price(price2num($result->netGain, 'MT'), 0, $langs, 1, -1, -1, $study['currency_code']),
		'LmdbPropalPVROI20' => price($result->roiRate * 100.0).' %',
		'LmdbPropalPVAverageAnnualReturn' => price($result->averageAnnualReturnRate * 100.0).' %',
		'LmdbPropalPVPayback' => $payback,
		'LmdbPropalPVSimplifiedProductionCost' => price(price2num($result->simplifiedProductionCostPerKwh, 'MU')).' '.$study['currency_code'].'/kWh',
	);
	foreach ($metrics as $label => $value) {
		print '<tr><td class="titlefield">'.$langs->trans($label).'</td><td><strong>'.$value.'</strong></td></tr>';
	}
	print '</table></div></div><div class="clearboth"></div><br>';
	print lmdbpropalpvCashflowGraph($result, $study['currency_code'], (int) $object->entity);
	print '<div class="div-table-responsive"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><th>'.$langs->trans('LmdbPropalPVYear').'</th><th class="right">'.$langs->trans('LmdbPropalPVProduction').'</th><th class="right">'.$langs->trans('LmdbPropalPVNetworkPrice').'</th><th class="right">'.$langs->trans('LmdbPropalPVSurplusSale').'</th><th class="right">'.$langs->trans('LmdbPropalPVElectricitySavings').'</th><th class="right">'.$langs->trans('LmdbPropalPVPremium').'</th><th class="right">'.$langs->trans('LmdbPropalPVAnnualGain').'</th><th class="right">'.$langs->trans('LmdbPropalPVCumulativeCashflow').'</th><th class="right">'.$langs->trans('LmdbPropalPVAnnualReturn').'</th></tr>';
	foreach ($result->years as $year) {
		print '<tr class="oddeven"><td>'.((int) $year->year).'</td><td class="right">'.price($year->productionKwh).'</td><td class="right">'.price(price2num($year->retailPricePerKwh, 'MU')).'</td><td class="right">'.price(price2num($year->surplusSale, 'MT')).'</td><td class="right">'.price(price2num($year->electricitySavings, 'MT')).'</td><td class="right">'.price(price2num($year->premium, 'MT')).'</td><td class="right">'.price(price2num($year->annualGain, 'MT')).'</td><td class="right">'.price(price2num($year->cumulativeCashflow, 'MT')).'</td><td class="right">'.price($year->annualReturnRate * 100.0).' %</td></tr>';
	}
	print '</table></div>';
}

print dol_get_fiche_end();
llxFooter();
$db->close();

/** @return string */
function lmdbpropalpvPostedDate($prefix)
{
	$timestamp = dol_mktime(0, 0, 0, GETPOSTINT($prefix.'month'), GETPOSTINT($prefix.'day'), GETPOSTINT($prefix.'year'));
	return $timestamp > 0 ? dol_print_date($timestamp, '%Y-%m-%d') : '';
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
		'retail_price_per_kwh' => 'lmdbpropalpv_retail_price_per_kwh',
		'feed_in_price_per_kwh' => 'lmdbpropalpv_feed_in_price_per_kwh',
		'premium_per_kwp' => 'lmdbpropalpv_premium_per_kwp',
		'tariff_set_id' => 'lmdbpropalpv_tariff_set_id',
	);
	foreach ($mapping as $key => $extrafield) {
		$object->array_options['options_'.$extrafield] = $values[$key];
	}
}

/** @return void */
function lmdbpropalpvInputRow($name, $label, $value, $suffix, $editable, $help = '', $priceType = '')
{
	global $langs;
	print '<tr class="oddeven"><td class="titlefield">'.$langs->trans($label);
	if ($help !== '') {
		print ' '.img_help(1, $langs->trans($help));
	}
	print '</td><td>';
	if ($editable) {
		print '<input class="flat maxwidth150" inputmode="decimal" name="'.dol_escape_htmltag($name).'" value="'.dol_escape_htmltag((string) $value).'">';
	} else {
		print price($priceType !== '' ? price2num((float) $value, $priceType) : (float) $value);
	}
	print dol_escape_htmltag($suffix).'</td></tr>';
}

/** @return string */
function lmdbpropalpvCashflowGraph(LmdbPropalPVFinancialResult $result, $currencyCode, $entity)
{
	global $db, $langs;

	$values = array($result->initialCashflow);
	foreach ($result->years as $year) {
		$values[] = $year->cumulativeCashflow;
	}
	$rawMin = min(0.0, min($values));
	$rawMax = max(0.0, max($values));
	$rawSpan = max(1.0, $rawMax - $rawMin);
	$magnitude = pow(10.0, floor(log10($rawSpan / 5.0)));
	$normalized = ($rawSpan / 5.0) / $magnitude;
	$step = ($normalized <= 1.0 ? 1.0 : ($normalized <= 2.0 ? 2.0 : ($normalized <= 5.0 ? 5.0 : 10.0))) * $magnitude;
	$min = floor($rawMin / $step) * $step;
	$max = ceil($rawMax / $step) * $step;
	if ($max <= $min) {
		$max = $min + $step;
	}
	$span = $max - $min;
	$left = 76.0;
	$top = 28.0;
	$plotWidth = 744.0;
	$plotHeight = 222.0;
	$points = array();
	foreach ($values as $index => $value) {
		$x = $left + ($index / 20.0) * $plotWidth;
		$y = $top + $plotHeight - (($value - $min) / $span) * $plotHeight;
		$points[] = $x.','.$y;
	}
	$zeroY = $top + $plotHeight - ((0.0 - $min) / $span) * $plotHeight;
	$primary = lmdbpropalpvGetEntityStringConstant($db, 'LMDBPROPALPV_PDF_PRIMARY_COLOR', '#16324F', (int) $entity);
	$accent = lmdbpropalpvGetEntityStringConstant($db, 'LMDBPROPALPV_PDF_ACCENT_COLOR', '#F2B705', (int) $entity);
	$svg = '<div class="center"><svg xmlns="http://www.w3.org/2000/svg" width="850" height="292" role="img" aria-label="'.dol_escape_htmltag($langs->trans('LmdbPropalPVCumulativeCashflow')).'" viewBox="0 0 850 292" class="centpercent" style="max-height:340px">';
	$svg .= '<rect x="0" y="0" width="850" height="292" fill="#fff"/>';
	$svg .= '<text x="448" y="17" text-anchor="middle" font-size="14" font-weight="bold" fill="#333">'.dol_escape_htmltag($langs->trans('LmdbPropalPVCumulativeCashflow')).'</text>';
	for ($year = 0; $year <= 20; $year++) {
		$x = $left + ($year / 20.0) * $plotWidth;
		$svg .= '<line x1="'.$x.'" y1="'.$top.'" x2="'.$x.'" y2="'.($top + $plotHeight).'" stroke="#d9dde2" stroke-width="1"/>';
		$svg .= '<text x="'.$x.'" y="270" text-anchor="middle" font-size="11" fill="#555">'.$year.'</text>';
	}
	for ($tick = $min; $tick <= $max + ($step / 2.0); $tick += $step) {
		$y = $top + $plotHeight - (($tick - $min) / $span) * $plotHeight;
		$svg .= '<line x1="'.$left.'" y1="'.$y.'" x2="'.($left + $plotWidth).'" y2="'.$y.'" stroke="#d9dde2" stroke-width="1"/>';
		$svg .= '<text x="68" y="'.($y + 4.0).'" text-anchor="end" font-size="11" fill="#555">'.dol_escape_htmltag(price(price2num($tick, 'MT'))).'</text>';
	}
	$svg .= '<text x="18" y="15" font-size="11" fill="#555">'.dol_escape_htmltag((string) $currencyCode).'</text>';
	if ($result->paybackYears !== null) {
		$paybackX = $left + ($result->paybackYears / 20.0) * $plotWidth;
		$svg .= '<line x1="'.$left.'" y1="'.$zeroY.'" x2="'.$paybackX.'" y2="'.$zeroY.'" stroke="'.dol_escape_htmltag($accent).'" stroke-width="2.5" stroke-dasharray="7 5"/>';
		$svg .= '<line x1="'.$paybackX.'" y1="'.$zeroY.'" x2="'.$paybackX.'" y2="'.($top + $plotHeight).'" stroke="'.dol_escape_htmltag($accent).'" stroke-width="2.5" stroke-dasharray="7 5"/>';
		$svg .= '<circle cx="'.$paybackX.'" cy="'.$zeroY.'" r="5" fill="'.dol_escape_htmltag($accent).'"/>';
		$svg .= '<text x="'.($paybackX + 6.0).'" y="'.($zeroY - 8.0).'" font-size="11" font-weight="bold" fill="'.dol_escape_htmltag($accent).'">'.dol_escape_htmltag($langs->trans('LmdbPropalPVPayback').' '.price($result->paybackYears).' '.$langs->trans('LmdbPropalPVYears')).'</text>';
	}
	$svg .= '<polyline points="'.dol_escape_htmltag(implode(' ', $points)).'" fill="none" stroke="'.dol_escape_htmltag($primary).'" stroke-width="4" stroke-linejoin="round" stroke-linecap="round"/>';
	foreach ($points as $point) {
		$coordinates = explode(',', $point);
		$svg .= '<circle cx="'.$coordinates[0].'" cy="'.$coordinates[1].'" r="3" fill="'.dol_escape_htmltag($primary).'"/>';
	}
	$svg .= '</svg></div>';

	return $svg;
}
