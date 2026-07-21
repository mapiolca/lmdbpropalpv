<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

require '../../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('/lmdbpropalpv/lib/lmdbpropalpv.lib.php');

$langs->loadLangs(array('admin', 'lmdbpropalpv@lmdbpropalpv'));
if (!isModEnabled('lmdbpropalpv')) {
	accessforbidden();
}
if (!lmdbpropalpvCanDo($user, 'setup')) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$id = GETPOSTINT('id');
if ($action === 'create') {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		accessforbidden();
	}
	$ref = GETPOST('ref', 'alphanohtml');
	$label = GETPOST('label', 'restricthtml');
	$currencyCode = strtoupper(GETPOST('currency_code', 'alpha'));
	$dateStart = dol_mktime(0, 0, 0, GETPOSTINT('date_startmonth'), GETPOSTINT('date_startday'), GETPOSTINT('date_startyear'));
	$dateEnd = dol_mktime(0, 0, 0, GETPOSTINT('date_endmonth'), GETPOSTINT('date_endday'), GETPOSTINT('date_endyear'));
	$subscriptionKva = (float) price2num(GETPOST('subscription_kva', 'alphanohtml'));
	$rules = array(
		array('retail', 'base', $subscriptionKva, null, null, (float) price2num(GETPOST('retail_base', 'alphanohtml'), 'MU'), $currencyCode.'/kWh'),
		array('retail', 'peak', $subscriptionKva, null, null, (float) price2num(GETPOST('retail_peak', 'alphanohtml'), 'MU'), $currencyCode.'/kWh'),
		array('feed_in', 'surplus', null, 0.0, 100.0, (float) price2num(GETPOST('feed_in', 'alphanohtml'), 'MU'), $currencyCode.'/kWh'),
		array('premium', 'surplus', null, 0.0, 100.0, (float) price2num(GETPOST('premium', 'alphanohtml'), 'MU'), $currencyCode.'/kWp'),
		);
		$error = 0;
		if ($ref === '' || $label === '' || !preg_match('/^[A-Z]{3}$/', $currencyCode) || $dateStart <= 0 || ($dateEnd > 0 && $dateEnd < $dateStart) || !lmdbpropalpvSubscribedPowerIsSupported($subscriptionKva) || $rules[0][5] <= 0.0 || $rules[1][5] <= 0.0 || $rules[2][5] < 0.0 || $rules[3][5] < 0.0) {
			$error++;
			setEventMessages($langs->trans('LmdbPropalPVInvalidTariffInput'), null, 'errors');
		}
	if (!$error) {
		$db->begin();
		$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'lmdbpropalpv_tariff_set (entity, ref, label, date_start, date_end, currency_code, official, status, date_creation, fk_user_creat) VALUES (';
		$sql .= ((int) $conf->entity).", '".$db->escape($ref)."', '".$db->escape($label)."', '".$db->idate($dateStart)."', ";
		$sql .= $dateEnd > 0 ? "'".$db->idate($dateEnd)."', " : 'NULL, ';
		$sql .= "'".$db->escape($currencyCode)."', 0, 1, '".$db->idate(dol_now())."', ".((int) $user->id).')';
		if (!$db->query($sql)) {
			$error++;
		} else {
			$setId = (int) $db->last_insert_id(MAIN_DB_PREFIX.'lmdbpropalpv_tariff_set');
			foreach ($rules as $rule) {
				$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'lmdbpropalpv_tariff_rule (fk_tariff_set, metric, option_code, subscription_kva, min_kwp, max_kwp, value, unit, date_creation, fk_user_creat) VALUES (';
				$sql .= $setId.", '".$db->escape($rule[0])."', '".$db->escape($rule[1])."', ";
				$sql .= $rule[2] === null ? 'NULL, ' : ((float) $rule[2]).', ';
				$sql .= $rule[3] === null ? 'NULL, ' : ((float) $rule[3]).', ';
				$sql .= $rule[4] === null ? 'NULL, ' : ((float) $rule[4]).', ';
				$sql .= ((float) $rule[5]).", '".$db->escape($rule[6])."', '".$db->idate(dol_now())."', ".((int) $user->id).')';
				if (!$db->query($sql)) {
					$error++;
					break;
				}
			}
		}
		if ($error) {
			$db->rollback();
			setEventMessages($db->lasterror(), null, 'errors');
		} else {
			$db->commit();
			setEventMessages($langs->trans('LmdbPropalPVTariffCreated'), null, 'mesgs');
		}
	}
	if (!$error) {
		header('Location: '.$_SERVER['PHP_SELF']);
		exit;
	}
}
if ($action === 'archive' && $id > 0) {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		accessforbidden();
	}
	$sql = 'UPDATE '.MAIN_DB_PREFIX.'lmdbpropalpv_tariff_set SET status = 0, fk_user_modif = '.((int) $user->id);
	$sql .= ' WHERE rowid = '.$id.' AND entity = '.((int) $conf->entity);
	if ($db->query($sql)) {
		setEventMessages($langs->trans('LmdbPropalPVTariffArchived'), null, 'mesgs');
	} else {
		setEventMessages($db->lasterror(), null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}
if ($action === 'duplicate' && $id > 0) {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		accessforbidden();
	}
	$db->begin();
	$error = 0;
	$sql = 'SELECT * FROM '.MAIN_DB_PREFIX.'lmdbpropalpv_tariff_set WHERE rowid = '.$id.' AND entity = '.((int) $conf->entity);
	$resql = $db->query($sql);
	$source = $resql ? $db->fetch_object($resql) : false;
	if (!is_object($source)) {
		$error++;
	} else {
		$ref = 'COPY-'.$source->ref.'-'.dol_print_date(dol_now(), '%Y%m%d%H%M%S');
		$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'lmdbpropalpv_tariff_set (entity, ref, label, date_start, date_end, currency_code, source_url, source_published_at, source_hash, official, status, date_creation, fk_user_creat)';
		$sql .= ' SELECT entity, \''.$db->escape($ref).'\', CONCAT(label, \' - copie\'), date_start, date_end, currency_code, source_url, source_published_at, source_hash, 0, 1, \''.$db->idate(dol_now()).'\', '.((int) $user->id);
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbpropalpv_tariff_set WHERE rowid = '.$id.' AND entity = '.((int) $conf->entity);
		if (!$db->query($sql)) {
			$error++;
		} else {
			$newId = (int) $db->last_insert_id(MAIN_DB_PREFIX.'lmdbpropalpv_tariff_set');
			$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'lmdbpropalpv_tariff_rule (fk_tariff_set, metric, option_code, subscription_kva, min_kwp, max_kwp, value, unit, date_creation, fk_user_creat)';
			$sql .= ' SELECT '.$newId.', metric, option_code, subscription_kva, min_kwp, max_kwp, value, unit, \''.$db->idate(dol_now()).'\', '.((int) $user->id);
			$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbpropalpv_tariff_rule WHERE fk_tariff_set = '.$id;
			if (!$db->query($sql)) {
				$error++;
			}
		}
	}
	if ($error) {
		$db->rollback();
		setEventMessages($db->lasterror(), null, 'errors');
	} else {
		$db->commit();
		setEventMessages($langs->trans('LmdbPropalPVTariffDuplicated'), null, 'mesgs');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

llxHeader('', $langs->trans('LmdbPropalPVTariffs'));
print load_fiche_titre($langs->trans('LmdbPropalPVSetup'), lmdbpropalpvAdminLinkBack(), 'solar-panel');
print dol_get_fiche_head(lmdbpropalpvAdminPrepareHead(), 'tariffs', $langs->trans('LmdbPropalPVSetup'), -1, 'solar-panel');
print load_fiche_titre($langs->trans('LmdbPropalPVTariffHistory'), '', 'list');
$anomalies = lmdbpropalpvTariffAnomalies($db, (int) $conf->entity);
if (!empty($anomalies)) {
	print '<div class="warning"><strong>'.$langs->trans('LmdbPropalPVTariffAnomalies').'</strong><ul>';
	foreach ($anomalies as $anomaly) {
		print '<li>'.dol_escape_htmltag($langs->trans($anomaly['type']).' — '.$anomaly['group'].' — '.$anomaly['from'].' / '.$anomaly['to']).'</li>';
	}
	print '</ul></div>';
}
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th>'.$langs->trans('LmdbPropalPVTariffRef').'</th><th>'.$langs->trans('LmdbPropalPVTariffLabel').'</th><th>'.$langs->trans('LmdbPropalPVTariffPeriod').'</th><th>'.$langs->trans('Currency').'</th><th>'.$langs->trans('LmdbPropalPVTariffOfficial').'</th><th>'.$langs->trans('LmdbPropalPVTariffSource').'</th><th class="center">'.$langs->trans('LmdbPropalPVTariffRules').'</th><th>'.$langs->trans('Status').'</th><th></th></tr>';
$sql = 'SELECT ts.*, COUNT(r.rowid) AS rule_count FROM '.MAIN_DB_PREFIX.'lmdbpropalpv_tariff_set AS ts';
$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'lmdbpropalpv_tariff_rule AS r ON r.fk_tariff_set = ts.rowid';
$sql .= ' WHERE ts.entity = '.((int) $conf->entity).' GROUP BY ts.rowid ORDER BY ts.date_start DESC, ts.ref DESC';
$resql = $db->query($sql);
$num = $resql ? $db->num_rows($resql) : 0;
while ($resql && is_object($obj = $db->fetch_object($resql))) {
	$viewUrl = $_SERVER['PHP_SELF'].'?id='.((int) $obj->rowid).'#tariff-rules';
	$source = '—';
	if (!empty($obj->source_url)) {
		$source = '<a href="'.dol_escape_htmltag($obj->source_url).'" target="_blank" rel="noopener noreferrer">'.$langs->trans('LmdbPropalPVTariffSource').'</a>';
		if (!empty($obj->source_published_at)) {
			$source .= '<br><span class="opacitymedium">'.dol_print_date($db->jdate($obj->source_published_at), 'day').'</span>';
		}
		if (!empty($obj->source_hash)) {
			$source .= '<br><span class="opacitymedium" title="'.dol_escape_htmltag($obj->source_hash).'">SHA-256 '.dol_escape_htmltag(substr((string) $obj->source_hash, 0, 12)).'…</span>';
		}
	}
	print '<tr class="oddeven"><td><a href="'.$viewUrl.'">'.dol_escape_htmltag($obj->ref).'</a></td><td>'.dol_escape_htmltag($obj->label).'</td><td>'.dol_print_date($db->jdate($obj->date_start), 'day').' – '.(!empty($obj->date_end) ? dol_print_date($db->jdate($obj->date_end), 'day') : '∞').'</td><td>'.dol_escape_htmltag($obj->currency_code).'</td><td>'.yn((int) $obj->official === 1).'</td><td>'.$source.'</td><td class="center">'.((int) $obj->rule_count).'</td><td>'.((int) $obj->status === 1 ? '<span class="badge badge-status4">'.$langs->trans('LmdbPropalPVTariffActive').'</span>' : '<span class="badge badge-status8">'.$langs->trans('LmdbPropalPVTariffArchivedStatus').'</span>').'</td><td class="right">';
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="id" value="'.((int) $obj->rowid).'"><button class="button small" name="action" value="duplicate">'.$langs->trans('LmdbPropalPVDuplicateTariff').'</button></form> ';
	if ((int) $obj->status === 1) {
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="id" value="'.((int) $obj->rowid).'"><button class="button-delete small" name="action" value="archive">'.$langs->trans('LmdbPropalPVArchiveTariff').'</button></form>';
	}
	print '</td></tr>';
}
if ($num === 0) {
	print '<tr class="oddeven"><td colspan="9"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>';
}
print '</table>';
if ($id > 0) {
	$sql = 'SELECT ts.ref, ts.label, r.metric, r.option_code, r.subscription_kva, r.min_kwp, r.max_kwp, r.value, r.unit';
	$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbpropalpv_tariff_set AS ts';
	$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'lmdbpropalpv_tariff_rule AS r ON r.fk_tariff_set = ts.rowid';
	$sql .= ' WHERE ts.rowid = '.$id.' AND ts.entity = '.((int) $conf->entity);
	$sql .= ' ORDER BY r.metric, r.option_code, r.subscription_kva, r.min_kwp';
	$resqlRules = $db->query($sql);
	if ($resqlRules) {
		$ruleCount = $db->num_rows($resqlRules);
		print '<br><span id="tariff-rules"></span>'.load_fiche_titre($langs->trans('LmdbPropalPVTariffRules'), '', 'list');
		print '<table class="noborder centpercent">';
		print '<tr class="liste_titre"><th>'.$langs->trans('LmdbPropalPVTariffMetric').'</th><th>'.$langs->trans('LmdbPropalPVTariffOption').'</th><th>'.$langs->trans('LmdbPropalPVSubscribedPower').'</th><th>'.$langs->trans('LmdbPropalPVTariffPeakRange').'</th><th class="right">'.$langs->trans('Value').'</th></tr>';
		$metricLabels = array('retail' => 'LmdbPropalPVRetailPrice', 'feed_in' => 'LmdbPropalPVFeedInPrice', 'premium' => 'LmdbPropalPVPremiumPerKwp');
		$optionLabels = array('base' => 'LmdbPropalPVBase', 'peak' => 'LmdbPropalPVPeakHours', 'surplus' => 'LmdbPropalPVSurplus');
		while (is_object($rule = $db->fetch_object($resqlRules))) {
			$subscription = $rule->subscription_kva !== null ? price((float) $rule->subscription_kva, 0, $langs, 0, 0, -1).' kVA' : '—';
			$peakRange = ($rule->min_kwp !== null ? price((float) $rule->min_kwp, 0, $langs, 0, 0, -1) : '−∞').' – '.($rule->max_kwp !== null ? price((float) $rule->max_kwp, 0, $langs, 0, 0, -1) : '+∞').' kWc';
			$metric = isset($metricLabels[$rule->metric]) ? $langs->trans($metricLabels[$rule->metric]) : (string) $rule->metric;
			$option = isset($optionLabels[$rule->option_code]) ? $langs->trans($optionLabels[$rule->option_code]) : (string) $rule->option_code;
			print '<tr class="oddeven"><td>'.dol_escape_htmltag($metric).'</td><td>'.dol_escape_htmltag($option).'</td><td>'.$subscription.'</td><td>'.$peakRange.'</td><td class="right">'.price((float) $rule->value, 0, $langs, 0, 0, -1).' '.dol_escape_htmltag($rule->unit).'</td></tr>';
		}
		if ($ruleCount === 0) {
			print '<tr class="oddeven"><td colspan="5"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>';
		}
		print '</table>';
		$db->free($resqlRules);
	}
}
print '<p class="opacitymedium">'.$langs->trans('LmdbPropalPVDefaultAssumptionsHelp').'</p>';
print '<br>'.load_fiche_titre($langs->trans('LmdbPropalPVCreateTariff'), '', 'add');
$form = new Form($db);
$formCurrency = strtoupper(GETPOST('currency_code', 'alpha') ?: (string) $conf->currency);
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="create">';
print '<table class="noborder centpercent">';
print '<tr class="oddeven"><td class="titlefieldcreate fieldrequired">'.$langs->trans('LmdbPropalPVTariffRef').'</td><td><input class="flat minwidth200" name="ref" value="'.dol_escape_htmltag(GETPOST('ref', 'alphanohtml')).'"></td></tr>';
print '<tr class="oddeven"><td class="fieldrequired">'.$langs->trans('LmdbPropalPVTariffLabel').'</td><td><input class="flat minwidth300" name="label" value="'.dol_escape_htmltag(GETPOST('label', 'restricthtml')).'"></td></tr>';
print '<tr class="oddeven"><td class="fieldrequired">'.$langs->trans('DateStart').'</td><td>'.$form->selectDate('', 'date_start', 0, 0, 0, '', 1).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('DateEnd').'</td><td>'.$form->selectDate('', 'date_end', 0, 0, 1, '', 1).'</td></tr>';
print '<tr class="oddeven"><td class="fieldrequired">'.$langs->trans('Currency').'</td><td>'.$form->selectCurrency($formCurrency, 'currency_code').'</td></tr>';
print '<tr class="oddeven"><td class="fieldrequired">'.$langs->trans('LmdbPropalPVSubscribedPower').' '.img_help(1, $langs->trans('LmdbPropalPVSubscribedPowerHelp')).'</td><td>'.$form->selectarray('subscription_kva', lmdbpropalpvGetSubscribedPowerOptions(), GETPOST('subscription_kva', 'alphanohtml') ?: '6', 0, 0, 0, '', 0, 0, 0, '', 'minwidth150').'</td></tr>';
print '<tr class="oddeven"><td class="fieldrequired">'.$langs->trans('LmdbPropalPVBase').'</td><td><input class="flat maxwidth100" inputmode="decimal" name="retail_base" value="'.dol_escape_htmltag(GETPOST('retail_base', 'alphanohtml')).'"> '.dol_escape_htmltag($formCurrency).'/kWh</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbPropalPVPeakHours').'</td><td><input class="flat maxwidth100" inputmode="decimal" name="retail_peak" value="'.dol_escape_htmltag(GETPOST('retail_peak', 'alphanohtml')).'"> '.dol_escape_htmltag($formCurrency).'/kWh</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbPropalPVFeedInPrice').'</td><td><input class="flat maxwidth100" inputmode="decimal" name="feed_in" value="'.dol_escape_htmltag(GETPOST('feed_in', 'alphanohtml')).'"> '.dol_escape_htmltag($formCurrency).'/kWh</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbPropalPVPremiumPerKwp').'</td><td><input class="flat maxwidth100" inputmode="decimal" name="premium" value="'.dol_escape_htmltag(GETPOST('premium', 'alphanohtml')).'"> '.dol_escape_htmltag($formCurrency).'/kWp</td></tr>';
print '</table><div class="center"><button class="button button-save" type="submit">'.$langs->trans('Create').'</button></div></form>';
print dol_get_fiche_end();
llxFooter();
$db->close();

/**
 * Detect gaps and overlaps per homogeneous rule selector.
 *
 * @param DoliDB $db Database handler
 * @param int $entity Current entity
 * @return list<array{type:string,group:string,from:string,to:string}>
 */
function lmdbpropalpvTariffAnomalies($db, $entity)
{
	$groups = array();
	$sql = 'SELECT ts.ref, ts.currency_code, ts.date_start, ts.date_end, r.metric, r.option_code, r.subscription_kva, r.min_kwp, r.max_kwp';
	$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbpropalpv_tariff_set AS ts';
	$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'lmdbpropalpv_tariff_rule AS r ON r.fk_tariff_set = ts.rowid';
	$sql .= ' WHERE ts.entity = '.((int) $entity).' AND ts.status = 1';
	$sql .= ' ORDER BY ts.date_start ASC, ts.rowid ASC';
	$resql = $db->query($sql);
	if (!$resql) {
		return array();
	}
	while (is_object($obj = $db->fetch_object($resql))) {
		$key = implode('|', array((string) $obj->currency_code, (string) $obj->metric, (string) $obj->option_code, (string) $obj->subscription_kva, (string) $obj->min_kwp, (string) $obj->max_kwp));
		$groups[$key][] = array('ref' => (string) $obj->ref, 'start' => (string) $obj->date_start, 'end' => !empty($obj->date_end) ? (string) $obj->date_end : '');
	}
	$db->free($resql);

	$anomalies = array();
	foreach ($groups as $key => $periods) {
		$previous = null;
		foreach ($periods as $period) {
			if (is_array($previous) && $previous['end'] !== '') {
				$expectedStart = date('Y-m-d', strtotime($previous['end'].' +1 day'));
				if ($period['start'] <= $previous['end']) {
					$anomalies[] = array('type' => 'LmdbPropalPVTariffOverlap', 'group' => $key, 'from' => $previous['ref'], 'to' => $period['ref']);
				} elseif ($period['start'] > $expectedStart) {
					$anomalies[] = array('type' => 'LmdbPropalPVTariffGap', 'group' => $key, 'from' => $expectedStart, 'to' => $period['start']);
				}
			}
			if (!is_array($previous) || $previous['end'] === '' || $period['end'] === '' || $period['end'] > $previous['end']) {
				$previous = $period;
			}
		}
	}

	return $anomalies;
}
