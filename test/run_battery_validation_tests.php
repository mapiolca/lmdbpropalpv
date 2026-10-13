<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

// Real page action/rendering and battery eligibility methods; simulated bootstrap, SQL and persistence.
function assertBattery(bool $condition, string $message): void
{
	if (!$condition) { throw new RuntimeException($message); }
}
/**
 * @param array<string,int|string> $request
	 * @param array{stored?:array<string,int|float|string>,readonly?:bool} $fixture
 * @return array{html:string,writes:int,stored:array<string,int|float|string>,messages:list<array{0:string,1:string}>,headers:list<string>}
 */
function runBatteryRequest(array $request, array $fixture = array()): array
{
	$process = proc_open(array(PHP_BINARY, __FILE__, 'request'), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	assertBattery(is_resource($process), 'Could not start request');
	fwrite($pipes[0], json_encode(array('request' => $request, 'fixture' => $fixture)));
	fclose($pipes[0]);
	$output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
	fclose($pipes[1]); fclose($pipes[2]);
	$exitCode = proc_close($process);
	assertBattery($exitCode === 0 && $errors === '', 'Request failed: '.$errors);
	$result = json_decode($output, true);
	assertBattery(is_array($result), 'Invalid request output: '.$output);
	return $result;
}
/** @return array{id:int,amount:?string,readonly:bool,total:string,refresh:bool} */
function batteryFormValues(string $html): array
{
	preg_match('/<select name="battery_proposal_id"[^>]*>(.*?)<\/select>/s', $html, $select);
	preg_match('/<option value="([0-9]+)" selected/', $select[1] ?? '', $selected);
	preg_match('/<input id="battery_extra_investment_ttc"[^>]*value="([^"]*)"([^>]*)>/', $html, $amount);
	preg_match('/LmdbPropalPVInvestmentTtcWithBattery<\/td><td>(.*?)<\/td>/s', $html, $total);
	return array('id' => (int) ($selected[1] ?? 0), 'amount' => $amount[1] ?? null,
		'readonly' => strpos($amount[2] ?? '', 'readonly') !== false,
		'total' => strip_tags($total[1] ?? ''), 'refresh' => strpos($html, 'id="lmdbpropalpv-refresh-battery-row"') !== false);
}
if (($argv[1] ?? '') !== 'request') {
	$invalid = array('battery_proposal_id' => 22, 'battery_extra_investment_ttc' => '99999', 'injection_limit_kva' => 'invalid');
	$failed = runBatteryRequest($invalid);
	$form = batteryFormValues($failed['html']);
	assertBattery($failed['writes'] === 0 && $failed['headers'] === array(), 'Invalid injection must not persist or redirect');
	assertBattery($form['id'] === 22 && $form['amount'] === '3500', 'Posted battery 22 / resolved 3500 must survive validation');
	assertBattery($form['readonly'] && $form['refresh'] && $form['total'] === '13500', 'Linked source, refresh and total must match displayed battery');
	assertBattery(strpos($failed['html'], 'name="injection_limit_kva" value="invalid"') !== false, 'Invalid input must remain displayed');
	assertBattery($failed['stored'] === array(), 'Failed request must not change saved study');
	$corrected = runBatteryRequest(array('battery_proposal_id' => $form['id'], 'battery_extra_investment_ttc' => $form['amount'], 'injection_limit_kva' => '6'));
	assertBattery($corrected['writes'] === 1 && count($corrected['headers']) === 1, 'Corrected POST must persist once and redirect');
	assertBattery(strpos($corrected['headers'][0], 'Location: ') === 0, 'Successful POST must redirect to a read URL');
	assertBattery($corrected['stored']['options_lmdbpropalpv_fk_battery_propal'] === 22
		&& $corrected['stored']['options_lmdbpropalpv_battery_extra_investment_ttc'] === 3500, 'Corrected POST must save preserved selection and authoritative amount');
	$read = runBatteryRequest(array('action' => ''), array('stored' => $corrected['stored']));
	assertBattery($read['writes'] === 0 && batteryFormValues($read['html'])['id'] === 22, 'Redirected GET must not replay the write');
	$readonly = runBatteryRequest(array('action' => ''), array('stored' => $corrected['stored'], 'readonly' => true));
	assertBattery($readonly['writes'] === 0 && strpos($readonly['html'], '<a>BAT-22</a>') !== false
		&& strpos($readonly['html'], 'LmdbPropalPVInvestmentTtcWithBattery</td><td><strong>13500</strong>') !== false
		&& strpos($readonly['html'], 'id="battery_extra_investment_ttc"') === false, 'Validated proposal must show the saved battery and total without an editable field');
	foreach (array(array('2500', '12500'), array('', 'LmdbPropalPVNotConfigured'), array('0', '10000')) as $case) {
		$manual = runBatteryRequest(array('battery_proposal_id' => 0, 'battery_extra_investment_ttc' => $case[0], 'injection_limit_kva' => 'invalid'));
		$manualForm = batteryFormValues($manual['html']);
		assertBattery($manual['writes'] === 0 && $manualForm['id'] === 0 && $manualForm['amount'] === $case[0], 'Manual/empty/zero input must survive validation');
		assertBattery(!$manualForm['readonly'] && !$manualForm['refresh'] && $manualForm['total'] === $case[1], 'Manual/empty/zero total and controls must remain distinct');
		$saved = runBatteryRequest(array('battery_proposal_id' => 0, 'battery_extra_investment_ttc' => $case[0], 'injection_limit_kva' => '6'));
		assertBattery($saved['writes'] === 1 && $saved['stored']['options_lmdbpropalpv_fk_battery_propal'] === 0, 'Removing the source must save manual mode');
		assertBattery($saved['stored']['options_lmdbpropalpv_battery_extra_investment_ttc'] === ($case[0] === '' ? '' : (int) $case[0]), 'Saved empty/zero/manual values must stay distinct');
	}
	$unchanged = runBatteryRequest(array('battery_proposal_id' => 7, 'injection_limit_kva' => 'invalid'));
	assertBattery(batteryFormValues($unchanged['html'])['amount'] === '1000', 'Ordinary save must preserve the existing snapshot');
	$refresh = runBatteryRequest(array('action' => 'reload_battery_investment', 'battery_proposal_id' => 7, 'injection_limit_kva' => 'invalid'));
	assertBattery($refresh['writes'] === 0 && batteryFormValues($refresh['html'])['amount'] === '1200', 'Explicit refreshed amount must survive an injection error');
	$refreshedSave = runBatteryRequest(array('action' => 'reload_battery_investment', 'battery_proposal_id' => 7, 'injection_limit_kva' => '6'));
	assertBattery($refreshedSave['writes'] === 1 && $refreshedSave['stored']['options_lmdbpropalpv_battery_extra_investment_ttc'] === 1200, 'Explicit refresh must persist authoritative source amount');
	foreach (array(23, 24, 25, 99) as $inaccessible) {
		$refused = runBatteryRequest(array('battery_proposal_id' => $inaccessible, 'injection_limit_kva' => '6'));
		assertBattery($refused['writes'] === 0 && strpos(json_encode($refused['messages']), 'LmdbPropalPVInvalidBatteryProposal') !== false, 'Other tier/entity/currency/missing source must be refused');
	}
	print "All battery validation page simulations passed.\n";
	exit;
}

error_reporting(E_ALL);
$payload = json_decode(stream_get_contents(STDIN), true);
$fixture = $payload['fixture'];
$request = array_replace(array('id' => 1, 'action' => 'save', 'token' => 'session-token', 'annual_production_kwh' => '8000',
	'self_consumption_pct' => '68', 'first_year_degradation_pct' => '0.45', 'panel_degradation_pct' => '0.45', 'electricity_growth_pct' => '3',
	'tariff_reference_datemonth' => 10, 'tariff_reference_dateday' => 13, 'tariff_reference_dateyear' => 2026,
	'retail_mode' => 'base', 'subscription_kva' => '6', 'connection_phase_mode' => 'single', 'injection_limit_kva' => '6',
	'retail_price_per_kwh' => '0.2', 'feed_in_price_per_kwh' => '0.04', 'premium_per_kwp' => '0', 'tariff_set_id' => 1,
	'battery_self_consumption_pct' => '80', 'battery_proposal_id' => 7, 'battery_extra_investment_ttc' => '1000'), $payload['request']);
define('MAIN_DB_PREFIX', 'test_'); define('DOL_URL_ROOT', '/erp');
$_SERVER['PHP_SELF'] = '/erp/custom/lmdbpropalpv/propal_financial.php';
$_SERVER['REQUEST_METHOD'] = $request['action'] === '' ? 'GET' : 'POST';
function GETPOST($name, $type) { return $GLOBALS['request'][$name] ?? ''; }
function GETPOSTINT($name) { return (int) GETPOST($name, 'int'); }
function isModEnabled($module) { return $module === 'lmdbpropalpv'; }
function getEntity($element) { return '1'; }
function getDolGlobalString($name, $default = '') { return $default; }
function getDolGlobalInt($name, $default = 0) { return $default; }
function price2num($value, $type = '') { return str_replace(',', '.', (string) $value); }
function price($value, ...$args) { return (string) $value; }
function dol_mktime(...$args) { return 1; }
function dol_print_date($value, $format) { return '2026-10-13'; }
function dol_stringtotime($value) { return 1; }
function dol_escape_htmltag($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
function setEventMessages($message, $errors, $type) { $GLOBALS['messages'][] = array($message, $type); }
function newToken() { return 'session-token'; }
function ajax_combobox($name) { return ''; }
function llxHeader(...$args) {}
function llxFooter() {}
function propal_prepare_head($object) { return array(); }
function dol_get_fiche_head(...$args) { return ''; }
function dol_get_fiche_end() { return ''; }
function dol_banner_tab(...$args) {}
function accessforbidden(...$args) { throw new RuntimeException('Access denied'); }
function batteryTestHeader($value) { $GLOBALS['redirects'][] = $value; }
class User
{
	public $socid = 0;
	public function hasRight(...$args) { return true; }
}
class BatteryPageDb
{
	public $writes = 0;
	public $stored = array();
	public function escape($value) { return $value; }
	public function query($sql)
	{
		if (strpos($sql, 'test_lmdbpropalpv_tariff_set') !== false) { return (object) array('rows' => array((object) array('rowid' => 1))); }
		if (strpos($sql, 'FROM test_propal AS p') !== false) {
			preg_match('/p.entity = ([0-9]+)/', $sql, $entity); preg_match('/p.fk_soc = ([0-9]+)/', $sql, $soc);
			$rows = array();
			foreach (array(array(7, 1, 10, 'EUR', 1200), array(22, 1, 10, 'EUR', 3500), array(23, 1, 11, 'EUR', 4000), array(24, 2, 10, 'EUR', 5000), array(25, 1, 10, 'USD', 6000)) as $candidate) {
				if ($candidate[1] === (int) $entity[1] && $candidate[2] === (int) $soc[1]) {
					$rows[] = (object) array('rowid' => $candidate[0], 'ref' => 'BAT-'.$candidate[0], 'ref_client' => '', 'total_ttc' => $candidate[4], 'multicurrency_code' => $candidate[3], 'multicurrency_total_ttc' => $candidate[4]);
				}
			}
			return (object) array('rows' => $rows);
		}
		throw new RuntimeException('Unexpected SQL: '.$sql);
	}
	public function fetch_object($result) { return array_shift($result->rows) ?? false; }
	public function free($result) {}
	public function close() {}
}
class Propal
{
	public const STATUS_DRAFT = 0;
	public $id = 1, $entity = 1, $socid = 10, $statut = 0;
	public $ref = 'PV-1', $ref_client = '', $thirdparty = null, $multicurrency_code = 'EUR';
	public $array_options = array();
	public function __construct($db) {}
	public function fetch($id)
	{
		$this->id = (int) $id;
		if ($id === 1) { $this->statut = !empty($GLOBALS['fixture']['readonly']) ? 1 : 0; }
		return 1;
	}
	public function getNomUrl($mode) { return '<a>BAT-'.$this->id.'</a>'; }
	public function fetch_thirdparty() { return 0; }
	public function insertExtraFields($suffix, $user) { $GLOBALS['db']->writes++; $GLOBALS['db']->stored = $this->array_options; return 1; }
}
class BatteryPageLang
{
	public function loadLangs($catalogues) {}
	public function trans($key, ...$args) { return $key; }
}
class Form
{
	public function __construct($db) {}
	public function __call($name, $args) { return ''; }
	public function textwithpicto($label, ...$args) { return $label; }
	public function selectarray($name, $options, $selected, ...$args)
	{
		$html = '<select name="'.$name.'">';
		if ($name === 'battery_proposal_id') { $options = array(0 => '') + $options; }
		foreach ($options as $id => $label) { $html .= '<option value="'.$id.'"'.((string) $selected === (string) $id ? ' selected' : '').'>'.$label.'</option>'; }
		return $html.'</select>';
	}
}
require_once dirname(__DIR__).'/lib/lmdbpropalpv.lib.php';
require_once dirname(__DIR__).'/class/lmdbpropalpvconnectionpowerchecker.class.php';
$serviceSource = file_get_contents(dirname(__DIR__).'/class/lmdbpropalpvstudyservice.class.php');
$serviceStart = strpos($serviceSource, 'class LmdbPropalPVStudyService');
assertBattery($serviceStart !== false, 'Study service missing');
eval(str_replace('class LmdbPropalPVStudyService', 'class BatteryPageRealStudyService', substr($serviceSource, $serviceStart)));
class LmdbPropalPVStudyService extends BatteryPageRealStudyService
{
	public function buildStudy($propal)
	{
		$values = $GLOBALS['request'];
		$values['reference_date'] = '2026-10-13';
		$stored = $GLOBALS['fixture']['stored'] ?? array();
		$values['battery_proposal_id'] = $stored['options_lmdbpropalpv_fk_battery_propal'] ?? 7;
		$values['battery_extra_investment_ttc'] = $stored['options_lmdbpropalpv_battery_extra_investment_ttc'] ?? 1000;
		return array('values' => $values, 'currency_code' => 'EUR', 'peak_power_kwp' => 7.14, 'investment_ttc' => 10000,
			'reference_date' => '2026-10-13', 'complete' => false, 'missing' => array(), 'degradation_warning_keys' => array(),
			'battery_proposal_id' => $values['battery_proposal_id'], 'battery_extra_investment_ttc' => $values['battery_extra_investment_ttc'],
			'battery_investment_ttc' => 10000 + (float) $values['battery_extra_investment_ttc'], 'battery_configured' => false,
			'battery_warning_keys' => array(), 'connection_warning_keys' => array(), 'connection_result' => null);
	}
}
$conf = (object) array('entity' => 1, 'currency' => 'EUR');
$db = new BatteryPageDb(); $user = new User(); $langs = new BatteryPageLang();
$messages = array(); $redirects = array();
ob_start();
register_shutdown_function(static function () {
	$html = ob_get_clean();
	echo json_encode(array('html' => $html, 'writes' => $GLOBALS['db']->writes, 'stored' => $GLOBALS['db']->stored,
		'messages' => $GLOBALS['messages'], 'headers' => $GLOBALS['redirects']));
});
$pageSource = file_get_contents(getenv('BATTERY_TEST_PAGE') ?: dirname(__DIR__).'/propal_financial.php');
$pageStart = strpos($pageSource, '$langs->loadLangs');
assertBattery($pageStart !== false, 'Page bootstrap missing');
// Capture Location headers in CLI; preserve actual exit/POST/rendering control flow.
eval(str_replace('header(', 'batteryTestHeader(', substr($pageSource, $pageStart)));
