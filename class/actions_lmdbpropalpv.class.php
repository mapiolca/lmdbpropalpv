<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

require_once __DIR__.'/lmdbpropalpvstudyservice.class.php';

/** Hooks for proposal document generation. */
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
	 * Warn without blocking when a PV proposal PDF is generated from an incomplete study.
	 *
	 * @param array<string,mixed> $parameters Hook parameters
	 * @param CommonObject        $object     Proposal
	 * @param string              $action     Current action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;

		$contexts = explode(':', isset($parameters['context']) ? (string) $parameters['context'] : '');
		if (!in_array('propalcard', $contexts, true) || !is_object($object) || $object->element !== 'propal') {
			return 0;
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
