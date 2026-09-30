<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

// Contract simulations only: no Dolibarr instance, real SQL engine or external effects.
define('DOL_VERSION', '20.0.0');
define('DOL_DOCUMENT_ROOT', __DIR__);
define('DOL_URL_ROOT', '/erp');
define('MAIN_DB_PREFIX', 'test_');

class User
{
	public int $id = 7;
	public int $socid = 0;
	public int $admin = 0;
	public bool $read = true;
	public bool $write = true;
	public function hasRight($module, $action): bool { return $module === 'propal' && ($action === 'lire' ? $this->read : $this->write); }
}

class ComplexSiteTestDb
{
	public int $transaction_opened = 0;
	public array $proposals = array();
	public array $events = array();
	public array $queries = array();
	public array $allowedEntities = array(1);
	public bool $schemaDefined = true;
	public bool $schemaEnabled = true;
	public bool $proposalAccessible = true;
	public bool $thirdpartyAccessible = true;
	public bool $thirdpartyExists = true;
	public bool $queryFailure = false;
	public bool $beginFailure = false;
	public bool $commitFailure = false;
	public bool $triggerFailure = false;
	public bool $triggerThrows = false;
	public int $writes = 0;
	public int $triggerCalls = 0;
	private array $snapshot = array();
	public function begin(): int
	{
		if ($this->beginFailure) { return 0; }
		if ($this->transaction_opened++ === 0) { $this->snapshot = array($this->proposals, $this->events); }
		return 1;
	}
	public function commit(): int
	{
		if ($this->commitFailure && $this->transaction_opened === 1) { return 0; }
		$this->transaction_opened--;
		return 1;
	}
	public function rollback(): int
	{
		if (--$this->transaction_opened === 0) { list($this->proposals, $this->events) = $this->snapshot; }
		return 1;
	}
	public function query(string $sql)
	{
		$this->queries[] = $sql;
		if ($this->queryFailure) { return false; }
		if (preg_match('/SELECT rowid FROM test_propal WHERE rowid = (\d+) AND entity IN \(([\d,]+)\) FOR UPDATE$/', $sql, $matches)) {
			$id = (int) $matches[1];
			$entities = array_map('intval', explode(',', $matches[2]));
			return isset($this->proposals[$id]) && in_array($this->proposals[$id]['entity'], $entities, true) ? (object) array('rowid' => $id) : (object) array();
		}
		if (preg_match('/SELECT fk_object FROM test_propal_extrafields WHERE fk_object = (\d+) FOR UPDATE$/', $sql)) { return (object) array(); }
		throw new RuntimeException('Unexpected SQL: '.$sql);
	}
	public function fetch_object($result) { return isset($result->rowid) ? $result : false; }
	public function free($result): void {}
	public function sanitize(string $value): string { return $value; }
	public function lasterror(): string { return 'Simulated database failure'; }
}

class ExtraFields
{
	public array $attributes = array();
	private ComplexSiteTestDb $db;
	public function __construct($db) { $this->db = $db; }
	public function fetch_name_optionals_label($element): array
	{
		if ($this->db->schemaDefined) {
			$this->attributes['propal']['type']['lmdbpropalpv_complex_site'] = 'boolean';
			$this->attributes['propal']['enabled']['lmdbpropalpv_complex_site'] = $this->db->schemaEnabled ? '1' : '0';
		}
		return array();
	}
}

class Propal
{
	public const STATUS_DRAFT = 0;
	public string $element = 'propal';
	public int $id = 0;
	public int $entity = 1;
	public int $statut = 0;
	public int $socid = 10;
	public array $array_options = array();
	public ?Propal $oldcopy = null;
	public array $context = array();
	public string $error = '';
	public array $errors = array();
	private ComplexSiteTestDb $db;
	public function __construct($db) { $this->db = $db; }
	public function fetch($id): int
	{
		if (!isset($this->db->proposals[$id])) { return 0; }
		$this->id = $id;
		foreach ($this->db->proposals[$id] as $key => $value) { $this->$key = $value; }
		return 1;
	}
	public function fetch_thirdparty(): int { return $this->db->thirdpartyExists ? 1 : -1; }
	public function updateExtraField($key, $trigger, $user): int
	{
		$this->db->begin();
		$this->db->writes++;
		// Native boolean OFF may be persisted as NULL, including before the trigger.
		$value = !empty($this->array_options['options_'.$key]) ? 1 : null;
		$this->array_options['options_'.$key] = $value;
		$this->db->proposals[$this->id]['array_options']['options_'.$key] = $value;
		$this->context = array('extrafieldupdate' => 1);
		$this->db->triggerCalls++;
		$this->db->events[] = array(
			'code' => $trigger, 'entity' => $this->entity, 'user' => $user->id,
			'old' => (int) !empty($this->oldcopy->array_options['options_'.$key]),
			'new' => (int) !empty($this->array_options['options_'.$key]), 'context' => $this->context,
		);
		if ($this->db->triggerThrows) { throw new RuntimeException('Simulated throwing downstream trigger'); }
		if ($this->db->triggerFailure) { $this->error = 'Simulated trigger refusal'; $this->db->rollback(); return -1; }
		$this->db->commit();
		return 1;
	}
}

class ComplexSiteTestLanguages
{
	public function load($catalog): void {}
	public function trans($key): string { return $key; }
}

function isModEnabled($module): bool { return $module === 'lmdbpropalpv' && $GLOBALS['moduleEnabled']; }
function getDolGlobalInt($name, $default = 0): int { return (int) ($GLOBALS['settings'][$name] ?? $default); }
function getEntity($element): string { return implode(',', $GLOBALS['db']->allowedEntities); }
function dol_eval($expression, $a, $b, $mode): int { return $expression === '1' ? 1 : 0; }
function dol_clone($object, $mode) { return clone $object; }
function dol_syslog($message, $level): void {}
function checkUserAccessToObject($user, $features, $id, $table, $feature = '', $fk = ''): bool
{
	return $features === array('societe') ? $GLOBALS['db']->thirdpartyAccessible : $GLOBALS['db']->proposalAccessible;
}
function dol_escape_htmltag($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function img_picto($label, $icon): string { return '<span data-icon="'.$icon.'" title="'.$label.'"></span>'; }
function newToken(): string { return 'next-session-token'; }
function currentToken(): string { return 'current-session-token'; }
function GETPOST($key, $type) { return $GLOBALS['request'][$key] ?? ''; }
function accessforbidden(): void { throw new RuntimeException('Access denied'); }
function setEventMessages($message, $errors, $kind): void { $GLOBALS['messages'][] = array($message, $kind); }

require_once dirname(__DIR__).'/class/actions_lmdbpropalpv.class.php';
require_once dirname(__DIR__).'/class/lmdbpropalpvcompatibility.class.php';

function resetComplexSiteTest(): LmdbPropalPVComplexSiteService
{
	global $db, $user, $langs, $moduleEnabled, $settings, $messages, $request;
	$db = new ComplexSiteTestDb();
	$user = new User();
	$langs = new ComplexSiteTestLanguages();
	$moduleEnabled = true;
	$settings = array('LMDBPROPALPV_COMPLEX_SITE_ENABLED' => 1);
	$messages = array();
	$request = array();
	$db->proposals[1] = array('entity' => 1, 'statut' => 0, 'socid' => 10, 'array_options' => array('options_other' => 'preserve'));
	return new LmdbPropalPVComplexSiteService($db);
}

function assertComplexSite($condition, string $label): void
{
	if (!$condition) { throw new RuntimeException($label); }
}

// Isolate terminating doActions calls so a successful redirect cannot fall through.
if (isset($argv[1])) {
	resetComplexSiteTest();
	$object = new Propal($db); $object->fetch(1); $hook = new ActionsLmdbPropalPV($db); $action = 'setcomplexsite'; $manager = new stdClass();
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$request = array('token' => 'current-session-token', 'complex_site' => '1', 'expected_complex_site' => '0');
	if ($argv[1] === 'hook_disabled') { $settings['LMDBPROPALPV_COMPLEX_SITE_ENABLED'] = 0; }
	if ($argv[1] === 'hook_invalid') { $request['complex_site'] = '2'; }
	if ($argv[1] === 'hook_array') { $request['complex_site'] = array('1'); }
	if ($argv[1] === 'hook_missing_expected') { unset($request['expected_complex_site']); }
	if ($argv[1] === 'hook_readonly') { $user->write = false; }
	register_shutdown_function(static function () {
		print json_encode(array('writes' => $GLOBALS['db']->writes, 'events' => count($GLOBALS['db']->events), 'messages' => $GLOBALS['messages']));
	});
	$hook->doActions(array('context' => 'propalcard'), $object, $action, $manager);
	throw new RuntimeException('The action must redirect and exit.');
}

$service = resetComplexSiteTest();
assertComplexSite($service->setComplexSite(1, $user, 1, 0) === 1, 'OFF -> ON');
assertComplexSite($db->proposals[1]['array_options']['options_lmdbpropalpv_complex_site'] === 1, 'ON persists');
assertComplexSite($db->proposals[1]['array_options']['options_other'] === 'preserve', 'Other fields survive');
assertComplexSite($db->events[0]['old'] === 0 && $db->events[0]['new'] === 1 && $db->events[0]['code'] === 'PROPAL_MODIFY', 'Consumer receives native trigger and oldcopy');
assertComplexSite($service->setComplexSite(1, $user, 1, 0) === 0 && $db->triggerCalls === 1, 'Retry from a second form is idempotent');
assertComplexSite($service->setComplexSite(1, $user, 0, 1) === 1 && count($db->events) === 2, 'ON -> OFF');
assertComplexSite($db->events[1]['old'] === 1 && $db->events[1]['new'] === 0 && $db->transaction_opened === 0, 'OFF normalization and transaction cleanup');
assertComplexSite($service->setComplexSite(1, $user, 1, 1) === -1 && $db->writes === 2, 'Conflicting expected value cannot overwrite');

foreach (array('read', 'write') as $right) {
	$service = resetComplexSiteTest(); $user->$right = false; $user->admin = 1;
	assertComplexSite($service->setComplexSite(1, $user, 1, 0) < 0 && $db->writes === 0, 'No administrator bypass for '.$right);
}
$service = resetComplexSiteTest(); $user->socid = 10;
assertComplexSite($service->setComplexSite(1, $user, 1, 0) < 0, 'External user denied');
foreach (array('proposalAccessible', 'thirdpartyAccessible', 'thirdpartyExists') as $restriction) {
	$service = resetComplexSiteTest(); $db->$restriction = false;
	assertComplexSite($service->setComplexSite(1, $user, 1, 0) < 0 && $db->writes === 0 && $db->transaction_opened === 0, 'Business scope: '.$restriction);
}
foreach (array(1, 2, 3, 4) as $status) {
	$service = resetComplexSiteTest(); $db->proposals[1]['statut'] = $status;
	assertComplexSite($service->setComplexSite(1, $user, 1, 0) < 0 && $db->writes === 0, 'Fresh status '.$status.' is locked');
}
$service = resetComplexSiteTest(); $db->proposals[1]['socid'] = 0;
assertComplexSite($service->setComplexSite(1, $user, 1, 0) < 0, 'Missing mandatory parent denied');
$service = resetComplexSiteTest(); $db->proposals[1]['entity'] = 2;
assertComplexSite($service->setComplexSite(1, $user, 1, 0) < 0 && $db->writes === 0, 'Other entity denied');
$db->allowedEntities = array(1, 2);
assertComplexSite($service->setComplexSite(1, $user, 1, 0) === 1 && $db->events[0]['entity'] === 2, 'Shared proposal retains owner entity');
$service = resetComplexSiteTest(); $db->allowedEntities = array(2);
$db->proposals[2] = array('entity' => 2, 'statut' => 0, 'socid' => 20, 'array_options' => array());
assertComplexSite($service->setComplexSite(2, $user, 1, 0) === 1 && $db->proposals[1]['array_options'] === array('options_other' => 'preserve'), 'Entity B changes do not touch A');

foreach (array('beginFailure', 'queryFailure', 'commitFailure', 'triggerFailure', 'triggerThrows') as $failure) {
	$service = resetComplexSiteTest(); $db->$failure = true;
	assertComplexSite($service->setComplexSite(1, $user, 1, 0) < 0, 'Failure propagates: '.$failure);
	assertComplexSite($db->proposals[1]['array_options'] === array('options_other' => 'preserve') && $db->events === array() && $db->transaction_opened === 0, 'Rollback: '.$failure);
}
foreach (array(array(2, 0), array(1, -1), array(-1, 0)) as $invalid) {
	$service = resetComplexSiteTest();
	assertComplexSite($service->setComplexSite(1, $user, $invalid[0], $invalid[1]) < 0 && $db->writes === 0, 'Binary input validation');
}
$service = resetComplexSiteTest();
assertComplexSite($service->setComplexSite(999, $user, 1, 0) < 0, 'Unknown proposal denied');
$settings = array();
assertComplexSite(!LmdbPropalPVComplexSiteService::isAvailable($db), 'Missing setting defaults to disabled');

foreach (array('setting', 'module', 'schema', 'enabled', 'extrafields') as $disabled) {
	$service = resetComplexSiteTest();
	if ($disabled === 'setting') { $settings['LMDBPROPALPV_COMPLEX_SITE_ENABLED'] = 0; }
	if ($disabled === 'module') { $moduleEnabled = false; }
	if ($disabled === 'schema') { $db->schemaDefined = false; }
	if ($disabled === 'enabled') { $db->schemaEnabled = false; }
	if ($disabled === 'extrafields') { $settings['MAIN_EXTRAFIELDS_DISABLED'] = 1; }
	assertComplexSite(!LmdbPropalPVComplexSiteService::isAvailable($db) && $service->setComplexSite(1, $user, 1, 0) < 0 && $db->writes === 0, 'Disabled action refused: '.$disabled);
	assertComplexSite(!LmdbPropalPVCompatibility::getFeatures()['complex_site']['available'], 'Compatibility uses the same predicate: '.$disabled);
	$object = new Propal($db); $object->fetch(1); $hook = new ActionsLmdbPropalPV($db); $action = ''; $manager = new stdClass();
	$hook->formObjectOptions(array('context' => 'propalcard'), $object, $action, $manager);
	assertComplexSite($hook->resprints === '', 'Disabled interface hidden: '.$disabled);
}
$service = resetComplexSiteTest(); $service->setComplexSite(1, $user, 1, 0);
$settings['LMDBPROPALPV_COMPLEX_SITE_ENABLED'] = 0; $moduleEnabled = false;
assertComplexSite(!LmdbPropalPVComplexSiteService::isAvailable($db), 'Module off');
$moduleEnabled = true; $settings['LMDBPROPALPV_COMPLEX_SITE_ENABLED'] = 1;
assertComplexSite(LmdbPropalPVComplexSiteService::isAvailable($db) && $service->setComplexSite(1, $user, 1, 0) === 0, 'Re-enabling retains the saved flag');

$service = resetComplexSiteTest(); $object = new Propal($db); $object->fetch(1);
$hook = new ActionsLmdbPropalPV($db); $action = ''; $manager = new stdClass();
$hook->formObjectOptions(array('context' => 'propalcard:globalcard'), $object, $action, $manager);
assertComplexSite(strpos($hook->resprints, 'method="POST"') !== false && strpos($hook->resprints, 'name="token"') !== false && strpos($hook->resprints, 'role="switch"') !== false && strpos($hook->resprints, 'aria-checked="false"') !== false && strpos($hook->resprints, '<script') === false && strpos($hook->resprints, 'class="nobordertransp linkobject valignmiddle"') !== false && strpos($hook->resprints, 'class="button') === false, 'Accessible native switch without button decoration works without JS');
$user->write = false;
$hook->formObjectOptions(array('context' => 'propalcard'), $object, $action, $manager);
assertComplexSite(strpos($hook->resprints, '<form') === false && strpos($hook->resprints, 'switch_off') !== false, 'Read-only rendering');
$user->write = true; $user->socid = 10;
$hook->formObjectOptions(array('context' => 'propalcard'), $object, $action, $manager);
assertComplexSite(strpos($hook->resprints, '<form') === false, 'External rendering is read-only');
$user->socid = 0; $object->statut = 1;
$hook->formObjectOptions(array('context' => 'propalcard'), $object, $action, $manager);
assertComplexSite(strpos($hook->resprints, '<form') === false, 'Validated proposal rendering is read-only');
$object->id = 0;
$hook->formObjectOptions(array('context' => 'propalcard'), $object, $action, $manager);
assertComplexSite($hook->resprints === '', 'Creation form is untouched');
$object->id = 1; $user->read = false;
$hook->formObjectOptions(array('context' => 'propalcard'), $object, $action, $manager);
assertComplexSite($hook->resprints === '', 'No disclosure without read rights');
$user->read = true; $db->thirdpartyAccessible = false;
$hook->formObjectOptions(array('context' => 'propalcard'), $object, $action, $manager);
assertComplexSite($hook->resprints === '', 'No disclosure from an inaccessible thirdparty');
$db->thirdpartyAccessible = true;
$hook->formObjectOptions(array('context' => 'projectcard'), $object, $action, $manager);
assertComplexSite($hook->resprints === '', 'No rendering in other contexts');

$object->id = 1; $action = 'setcomplexsite';
foreach (array(array('GET', 'current-session-token'), array('POST', ''), array('POST', 'wrong-token')) as $transport) {
	$_SERVER['REQUEST_METHOD'] = $transport[0]; $request = array('token' => $transport[1]);
	$refused = false;
	try { $hook->doActions(array('context' => 'propalcard'), $object, $action, $manager); } catch (RuntimeException $exception) { $refused = $exception->getMessage() === 'Access denied'; }
	assertComplexSite($refused && $db->writes === 0, 'Transport and token validation');
}
$action = 'builddoc'; $request = array('model' => 'cyan');
assertComplexSite($hook->doActions(array('context' => 'propalcard'), $object, $action, $manager) === 0, 'Unrelated PDF hook still returns control to native code');

foreach (array('hook_success', 'hook_disabled', 'hook_invalid', 'hook_array', 'hook_missing_expected', 'hook_readonly') as $scenario) {
	$process = proc_open(array(PHP_BINARY, __FILE__, $scenario), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	if (!is_resource($process)) { throw new RuntimeException('Could not run hook scenario'); }
	$output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
	fclose($pipes[1]); fclose($pipes[2]); $exitCode = proc_close($process);
	$data = json_decode($output, true);
	assertComplexSite($exitCode === 0 && $errors === '' && is_array($data), 'Hook subprocess completes without warnings: '.$scenario);
	$expectedWrites = $scenario === 'hook_success' ? 1 : 0;
	assertComplexSite($data['writes'] === $expectedWrites && $data['events'] === $expectedWrites, 'Redirecting hook validates and writes exactly once: '.$scenario);
	assertComplexSite($scenario === 'hook_success' ? $data['messages'][0][1] === 'mesgs' : $data['messages'][0][1] === 'errors', 'Hook reports the result: '.$scenario);
}

print "All complex-site contract simulations passed.\n";
