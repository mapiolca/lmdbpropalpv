<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

require_once __DIR__.'/lmdbpropalpvstudyservice.class.php';
require_once __DIR__.'/lmdbpropalpvcomplexsiteservice.class.php';

/** Hooks for proposal qualification and document generation. */
class ActionsLmdbPropalPV
{
	/** @var DoliDB */
	public $db;
	/** @var string */
	public $error = '';
	/** @var array<int,string> */
	public $errors = array();
	/** @var array<string,mixed> */
	public $results = array();
	/** @var string */
	public $resprints = '';

	/** @param DoliDB $db Database handler */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Add the saved proposal's switch to the native information table.
	 *
	 * @param array<string,mixed> $parameters Hook parameters
	 * @param CommonObject $object Proposal
	 * @param string $action Current action
	 * @param HookManager $hookmanager Hook manager
	 * @return int
	 */
	public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
	{
		global $user, $langs;

		$this->resprints = '';
		$contexts = explode(':', isset($parameters['context']) ? (string) $parameters['context'] : '');
		if (!in_array('propalcard', $contexts, true) || !($object instanceof Propal) || $object->id <= 0
			|| !LmdbPropalPVComplexSiteService::isAvailable($this->db) || !$user->hasRight('propal', 'lire')) {
			return 0;
		}
		if (!checkUserAccessToObject($user, array('propal'), (int) $object->id, 'propal', '', 'fk_soc')
			|| $object->socid <= 0 || $object->fetch_thirdparty() <= 0
			|| !checkUserAccessToObject($user, array('societe'), (int) $object->socid, 'societe')) {
			return 0;
		}

		$langs->load('lmdbpropalpv@lmdbpropalpv');
		$value = (int) !empty($object->array_options[LmdbPropalPVComplexSiteService::OPTION_KEY]);
		$editable = $user->hasRight('propal', 'creer') && empty($user->socid)
			&& (int) $object->statut === Propal::STATUS_DRAFT;
		$label = $langs->trans('LmdbPropalPVComplexSite');
		$icon = img_picto($langs->trans($value ? 'Enabled' : 'Disabled'), $value ? 'switch_on' : 'switch_off');
		$cardUrl = DOL_URL_ROOT.'/comm/propal/card.php?id='.((int) $object->id);
		$this->resprints = '<tr><td class="titlefield">'.dol_escape_htmltag($label).'</td><td>';
		if ($editable) {
			$this->resprints .= '<form method="POST" action="'.dol_escape_htmltag($cardUrl).'" class="inline-block">';
			$this->resprints .= '<input type="hidden" name="token" value="'.dol_escape_htmltag(newToken()).'">';
			$this->resprints .= '<input type="hidden" name="action" value="setcomplexsite">';
			$this->resprints .= '<input type="hidden" name="complex_site" value="'.(1 - $value).'">';
			$this->resprints .= '<input type="hidden" name="expected_complex_site" value="'.$value.'">';
			$this->resprints .= '<button type="submit" class="nobordertransp linkobject valignmiddle" role="switch" aria-checked="'.($value ? 'true' : 'false').'" aria-label="'.dol_escape_htmltag($label).'">'.$icon.'</button>';
			$this->resprints .= '</form>';
		} else {
			$this->resprints .= $icon;
		}
		$this->resprints .= '</td></tr>';

		return 0; // Preserve every other native extrafield and hook.
	}

	/**
	 * Save proposal qualification or warn about an incomplete study before PDF generation.
	 *
	 * @param array<string,mixed> $parameters Hook parameters
	 * @param CommonObject        $object     Proposal
	 * @param string              $action     Current action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $user;

		$contexts = explode(':', isset($parameters['context']) ? (string) $parameters['context'] : '');
		if (!in_array('propalcard', $contexts, true) || !is_object($object) || $object->element !== 'propal') {
			return 0;
		}
		if ($action === 'setcomplexsite') {
			$token = GETPOST('token', 'alpha');
			if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !is_string($token) || $token === ''
				|| currentToken() === '' || !hash_equals(currentToken(), $token)) {
				accessforbidden();
			}
			$langs->load('lmdbpropalpv@lmdbpropalpv');
			$value = GETPOST('complex_site', 'alphanohtml');
			$expected = GETPOST('expected_complex_site', 'alphanohtml');
			if (!in_array($value, array('0', '1'), true) || !in_array($expected, array('0', '1'), true)) {
				setEventMessages($langs->trans('LmdbPropalPVComplexSiteInvalidValue'), null, 'errors');
			} else {
				$service = new LmdbPropalPVComplexSiteService($this->db);
				$result = $service->setComplexSite((int) $object->id, $user, (int) $value, (int) $expected);
				if ($result < 0) {
					setEventMessages($service->error, $service->errors, 'errors');
				} elseif ($result > 0) {
					setEventMessages($langs->trans('RecordSaved'), null, 'mesgs');
				}
			}
			header('Location: '.DOL_URL_ROOT.'/comm/propal/card.php?id='.((int) $object->id));
			exit;
		}
		if ($action !== 'builddoc') {
			return 0;
		}
		$model = GETPOST('model', 'alpha');
		if (!in_array($model, array('lmdbpropalpv_withpictures', 'lmdbpropalpv_withoutpictures'), true)) {
			return 0;
		}

		$service = new LmdbPropalPVStudyService($this->db);
		$study = $service->buildStudy($object);
		if (!$study['complete']) {
			$langs->load('lmdbpropalpv@lmdbpropalpv');
			$missingLabels = array_map(static function ($key) use ($langs) { return $langs->trans($key); }, $study['missing']);
			setEventMessages($langs->trans('LmdbPropalPVIncompletePdfWarning', implode(', ', $missingLabels)), null, 'warnings');
		}

		return 0;
	}
}
