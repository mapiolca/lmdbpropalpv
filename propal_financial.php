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

if (in_array($action, array('save', 'reload_tariff'), true)) {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$editable) {
		accessforbidden();
	}

	$referenceDate = lmdbpropalpvPostedDate('tariff_reference_date');
	$values = array(
		'annual_production_kwh' => (float) price2num(GETPOST('annual_production_kwh', 'alphanohtml')),
		'self_consumption_pct' => (float) price2num(GETPOST('self_consumption_pct', 'alphanohtml')),
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
		setEventMessages($langs->trans($action === 'reload_tariff' ? 'LmdbPropalPVTariffReloaded' : 'LmdbPropalPVStudySaved'), null, 'mesgs');
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
dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref');

print '<div class="fichecenter"><div class="fichehalfleft"><table class="border centpercent">';
print '<tr><td class="titlefield">'.$langs->trans('LmdbPropalPVPeakPower').'</td><td><strong>'.price($study['peak_power_kwp']).' kWc</strong></td></tr>';
print '<tr><td>'.$langs->trans('LmdbPropalPVProposalAmount').'</td><td><strong>'.price($study['investment_ttc'], 0, $langs, 1, -1, -1, $study['currency_code']).'</strong></td></tr>';
print '</table></div><div class="fichehalfright">';
if ($study['complete']) {
	print '<div class="ok">'.$langs->trans('LmdbPropalPVStudyComplete').'</div>';
} else {
	$missingLabels = array_map(static function ($key) use ($langs) { return $langs->trans($key); }, $study['missing']);
	print '<div class="warning">'.$langs->trans('LmdbPropalPVStudyIncomplete').' : '.dol_escape_htmltag(implode(', ', $missingLabels)).'</div>';
}
if (!$editable) {
	print '<div class="info">'.$langs->trans('LmdbPropalPVStudyReadOnly').'</div>';
}
print '</div></div><div class="clearboth"></div><br>';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.((int) $object->id).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="tariff_set_id" value="'.((int) $displayValues['tariff_set_id']).'">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th colspan="2">'.$langs->trans('LmdbPropalPVDefaultAssumptions').'</th></tr>';
lmdbpropalpvInputRow('annual_production_kwh', 'LmdbPropalPVAnnualProduction', $displayValues['annual_production_kwh'], ' kWh', $editable, 'LmdbPropalPVAnnualProductionHelp');
lmdbpropalpvInputRow('self_consumption_pct', 'LmdbPropalPVSelfConsumption', $displayValues['self_consumption_pct'], ' %', $editable);
lmdbpropalpvInputRow('panel_degradation_pct', 'LmdbPropalPVPanelDegradation', $displayValues['panel_degradation_pct'], ' %', $editable);
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
lmdbpropalpvInputRow('retail_price_per_kwh', 'LmdbPropalPVRetailPrice', $displayValues['retail_price_per_kwh'], ' '.$study['currency_code'].'/kWh', $editable);
lmdbpropalpvInputRow('feed_in_price_per_kwh', 'LmdbPropalPVFeedInPrice', $displayValues['feed_in_price_per_kwh'], ' '.$study['currency_code'].'/kWh', $editable);
lmdbpropalpvInputRow('premium_per_kwp', 'LmdbPropalPVPremiumPerKwp', $displayValues['premium_per_kwp'], ' '.$study['currency_code'].'/kWc', $editable);
print '</table>';
if ($editable) {
	print '<div class="center"><button class="button button-save" type="submit" name="action" value="save">'.$langs->trans('Save').'</button> ';
	print '<button class="button" type="submit" name="action" value="reload_tariff">'.$langs->trans('LmdbPropalPVReloadTariff').'</button></div>';
}
print '</form>';

if ($study['complete'] && $study['result'] instanceof LmdbPropalPVFinancialResult) {
	$result = $study['result'];
	print '<br>'.load_fiche_titre($langs->trans('LmdbPropalPVProjection20Years'), '', 'chart-area');
	print '<div class="fichecenter"><div class="fichehalfleft"><table class="border centpercent">';
	$metrics = array(
		'LmdbPropalPVTotalProduction' => price($result->totalProductionKwh).' kWh',
		'LmdbPropalPVTotalSavings' => price($result->totalElectricitySavings, 0, $langs, 1, -1, -1, $study['currency_code']),
		'LmdbPropalPVTotalSales' => price($result->totalSurplusSale, 0, $langs, 1, -1, -1, $study['currency_code']),
		'LmdbPropalPVTotalPremium' => price($result->totalPremium, 0, $langs, 1, -1, -1, $study['currency_code']),
		'LmdbPropalPVGrossGain' => price($result->totalGrossGain, 0, $langs, 1, -1, -1, $study['currency_code']),
	);
	foreach ($metrics as $label => $value) {
		print '<tr><td class="titlefield">'.$langs->trans($label).'</td><td><strong>'.$value.'</strong></td></tr>';
	}
	print '</table></div><div class="fichehalfright"><table class="border centpercent">';
	$payback = $result->paybackYears === null ? $langs->trans('LmdbPropalPVPaybackNotReached') : price($result->paybackYears).' '.$langs->trans('LmdbPropalPVYears');
	$metrics = array(
		'LmdbPropalPVNetGain' => price($result->netGain, 0, $langs, 1, -1, -1, $study['currency_code']),
		'LmdbPropalPVROI20' => price($result->roiRate * 100.0).' %',
		'LmdbPropalPVAverageAnnualReturn' => price($result->averageAnnualReturnRate * 100.0).' %',
		'LmdbPropalPVPayback' => $payback,
		'LmdbPropalPVSimplifiedProductionCost' => price($result->simplifiedProductionCostPerKwh).' '.$study['currency_code'].'/kWh',
	);
	foreach ($metrics as $label => $value) {
		print '<tr><td class="titlefield">'.$langs->trans($label).'</td><td><strong>'.$value.'</strong></td></tr>';
	}
	print '</table></div></div><div class="clearboth"></div><br>';
	print lmdbpropalpvCashflowGraph($result, $study['currency_code'], (int) $object->entity);
	print '<div class="div-table-responsive"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><th>'.$langs->trans('LmdbPropalPVYear').'</th><th class="right">'.$langs->trans('LmdbPropalPVProduction').'</th><th class="right">'.$langs->trans('LmdbPropalPVNetworkPrice').'</th><th class="right">'.$langs->trans('LmdbPropalPVSurplusSale').'</th><th class="right">'.$langs->trans('LmdbPropalPVElectricitySavings').'</th><th class="right">'.$langs->trans('LmdbPropalPVPremium').'</th><th class="right">'.$langs->trans('LmdbPropalPVAnnualGain').'</th><th class="right">'.$langs->trans('LmdbPropalPVCumulativeCashflow').'</th><th class="right">'.$langs->trans('LmdbPropalPVAnnualReturn').'</th></tr>';
	foreach ($result->years as $year) {
		print '<tr class="oddeven"><td>'.((int) $year->year).'</td><td class="right">'.price($year->productionKwh).'</td><td class="right">'.price($year->retailPricePerKwh).'</td><td class="right">'.price($year->surplusSale).'</td><td class="right">'.price($year->electricitySavings).'</td><td class="right">'.price($year->premium).'</td><td class="right">'.price($year->annualGain).'</td><td class="right">'.price($year->cumulativeCashflow).'</td><td class="right">'.price($year->annualReturnRate * 100.0).' %</td></tr>';
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
function lmdbpropalpvInputRow($name, $label, $value, $suffix, $editable, $help = '')
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
		print price((float) $value);
	}
	print dol_escape_htmltag($suffix).'</td></tr>';
}

/** @return string */
function lmdbpropalpvCashflowGraph(LmdbPropalPVFinancialResult $result, $currencyCode, $entity)
{
	global $conf, $db, $langs;

	$values = array($result->initialCashflow);
	foreach ($result->years as $year) {
		$values[] = $year->cumulativeCashflow;
	}
	if (!empty($conf->use_javascript_ajax)) {
		require_once DOL_DOCUMENT_ROOT.'/core/class/dolgraph.class.php';
		$data = array();
		foreach ($values as $year => $value) {
			$data[] = array((string) $year, $value);
		}
		$graph = new DolGraph();
		$graph->SetData($data);
		$graph->SetLegend(array($langs->trans('LmdbPropalPVCumulativeCashflow')));
		$graph->SetType(array('lines'));
		$graph->SetDataColor(array(lmdbpropalpvGetEntityStringConstant($db, 'LMDBPROPALPV_PDF_PRIMARY_COLOR', '#16324F', (int) $entity)));
		$graph->SetWidth('100%');
		$graph->SetHeight(280);
		$graph->SetYLabel((string) $currencyCode);
		$graph->SetTitle($langs->trans('LmdbPropalPVCumulativeCashflow'));
		$graph->SetHorizTickIncrement(1);
		$graph->draw('lmdbpropalpv_cashflow');
		$graphHtml = $graph->show();
		if ($graphHtml !== '') {
			return $graphHtml;
		}
	}

	$min = min($values);
	$max = max($values);
	$span = max(1.0, $max - $min);
	$points = array();
	foreach ($values as $index => $value) {
		$x = 20.0 + ($index / 20.0) * 760.0;
		$y = 190.0 - (($value - $min) / $span) * 160.0;
		$points[] = $x.','.$y;
	}
	$zeroY = 190.0 - ((0.0 - $min) / $span) * 160.0;
	$firstPoint = explode(',', $points[0]);
	$primary = lmdbpropalpvGetEntityStringConstant($db, 'LMDBPROPALPV_PDF_PRIMARY_COLOR', '#16324F', (int) $entity);
	$accent = lmdbpropalpvGetEntityStringConstant($db, 'LMDBPROPALPV_PDF_ACCENT_COLOR', '#F2B705', (int) $entity);
	return '<div class="center"><svg role="img" aria-label="'.dol_escape_htmltag($langs->trans('LmdbPropalPVCumulativeCashflow')).'" viewBox="0 0 800 220" class="centpercent" style="max-height:280px"><rect x="0" y="0" width="800" height="220" fill="#fff"/><line x1="20" y1="'.$zeroY.'" x2="780" y2="'.$zeroY.'" stroke="#aaa" stroke-dasharray="4 4"/><polyline points="'.dol_escape_htmltag(implode(' ', $points)).'" fill="none" stroke="'.dol_escape_htmltag($primary).'" stroke-width="4"/><circle cx="'.$firstPoint[0].'" cy="'.$firstPoint[1].'" r="4" fill="'.dol_escape_htmltag($accent).'"/></svg></div>';
}
