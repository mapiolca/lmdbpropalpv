<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

/** Qualify a saved draft proposal without changing its project or other extrafields. */
class LmdbPropalPVComplexSiteService
{
	public const EXTRAFIELD = 'lmdbpropalpv_complex_site';
	public const OPTION_KEY = 'options_lmdbpropalpv_complex_site';

	/** @var DoliDB */
	private $db;
	public string $error = '';
	/** @var array<int,string> */
	public array $errors = array();

	/** @param DoliDB $db Database handler */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Check the current entity's setting and native storage before exposing the action.
	 *
	 * @param DoliDB $db Database handler
	 * @return bool
	 */
	public static function isAvailable($db): bool
	{
		if (!isModEnabled('lmdbpropalpv') || !getDolGlobalInt('LMDBPROPALPV_COMPLEX_SITE_ENABLED')
			|| getDolGlobalInt('MAIN_EXTRAFIELDS_DISABLED')
			|| version_compare(DOL_VERSION, '20.0.0', '<') || version_compare(PHP_VERSION, '8.0.0', '<')) {
			return false;
		}
		if (!class_exists('ExtraFields')) {
			require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		}
		$extrafields = new ExtraFields($db);
		$extrafields->fetch_name_optionals_label('propal');

		$enabled = $extrafields->attributes['propal']['enabled'][self::EXTRAFIELD] ?? '';
		return isset($extrafields->attributes['propal']['type'][self::EXTRAFIELD])
			&& $extrafields->attributes['propal']['type'][self::EXTRAFIELD] === 'boolean'
			&& is_string($enabled) && $enabled !== '' && (bool) dol_eval($enabled, 1, 1, '2');
	}

	/**
	 * Change the flag with a locked, freshly loaded proposal and an optimistic value check.
	 * The caller owns transport validation (POST and CSRF). Rights and business scope
	 * are checked here as well, independently of interface visibility.
	 *
	 * @param int  $proposalId  Saved proposal
	 * @param User $user        Actor
	 * @param int  $value       Requested flag (0 or 1)
	 * @param int  $expected    Flag displayed by the submitted form (0 or 1)
	 * @return int 1 changed, 0 already saved, -1 refused or failed
	 */
	public function setComplexSite(int $proposalId, User $user, int $value, int $expected): int
	{
		global $langs;

		$this->error = '';
		$this->errors = array();
		$langs->load('lmdbpropalpv@lmdbpropalpv');
		if (!self::isAvailable($this->db)) {
			$this->error = $langs->trans('LmdbPropalPVComplexSiteUnavailable');
			return -1;
		}
		if (!$user->hasRight('propal', 'lire') || !$user->hasRight('propal', 'creer') || !empty($user->socid)) {
			$this->error = $langs->trans('LmdbPropalPVComplexSiteAccessDenied');
			return -1;
		}
		if ($proposalId <= 0 || !in_array($value, array(0, 1), true) || !in_array($expected, array(0, 1), true)) {
			$this->error = $langs->trans('LmdbPropalPVComplexSiteInvalidValue');
			return -1;
		}

		if (!class_exists('Propal')) {
			require_once DOL_DOCUMENT_ROOT.'/comm/propal/class/propal.class.php';
		}
		$initialTransactionLevel = $this->db->transaction_opened;
		if ($this->db->begin() <= 0) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$committed = false;
		try {
			// Lock the parent before reloading; validation or reassignment cannot race the save.
			$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'propal WHERE rowid = '.$proposalId;
			$sql .= ' AND entity IN ('.$this->db->sanitize(getEntity('propal')).') FOR UPDATE';
			$resql = $this->db->query($sql);
			if (!$resql) {
				$this->error = $this->db->lasterror();
				return -1;
			}
			$found = is_object($this->db->fetch_object($resql));
			$this->db->free($resql);
			if (!$found) {
				$this->error = $langs->trans('LmdbPropalPVComplexSiteAccessDenied');
				return -1;
			}
			// Also serialize native extrafield writers, including the first row insertion.
			$resql = $this->db->query('SELECT fk_object FROM '.MAIN_DB_PREFIX.'propal_extrafields WHERE fk_object = '.$proposalId.' FOR UPDATE');
			if (!$resql) {
				$this->error = $this->db->lasterror();
				return -1;
			}
			$this->db->free($resql);

			$proposal = new Propal($this->db);
			if ($proposal->fetch($proposalId) <= 0) {
				$this->error = $langs->trans('LmdbPropalPVComplexSiteAccessDenied');
				return -1;
			}
			if (!checkUserAccessToObject($user, array('propal'), $proposalId, 'propal', '', 'fk_soc')
				|| $proposal->socid <= 0 || $proposal->fetch_thirdparty() <= 0
				|| !checkUserAccessToObject($user, array('societe'), (int) $proposal->socid, 'societe')) {
				$this->error = $langs->trans('LmdbPropalPVComplexSiteAccessDenied');
				return -1;
			}
			if ((int) $proposal->statut !== Propal::STATUS_DRAFT) {
				$this->error = $langs->trans('LmdbPropalPVComplexSiteDraftOnly');
				return -1;
			}
			$current = (int) !empty($proposal->array_options[self::OPTION_KEY]);
			if ($current === $value) {
				// An identical retry has no event or write, even if its expected value is old.
				return 0;
			}
			if ($current !== $expected) {
				$this->error = $langs->trans('LmdbPropalPVComplexSiteConflict');
				return -1;
			}

			$proposal->oldcopy = dol_clone($proposal, 2);
			$proposal->array_options[self::OPTION_KEY] = $value;
			$result = $proposal->updateExtraField(self::EXTRAFIELD, 'PROPAL_MODIFY', $user);
			if ($result <= 0) {
				$this->error = $proposal->error ?: $langs->trans('LmdbPropalPVComplexSiteSaveFailed');
				$this->errors = $proposal->errors;
				return -1;
			}
			if ($this->db->commit() <= 0) {
				$this->error = $this->db->lasterror();
				return -1;
			}
			$committed = true;
			return 1;
		} catch (Throwable $exception) {
			// Do not log proposal contents or the transport token.
			dol_syslog(__METHOD__.' failed during proposal qualification', LOG_ERR);
			$this->error = $langs->trans('LmdbPropalPVComplexSiteSaveFailed');
			return -1;
		} finally {
			if (!$committed) {
				// A throwing downstream trigger may leave a nested native transaction open.
				while ($this->db->transaction_opened > $initialTransactionLevel) {
					$this->db->rollback();
				}
			}
		}
	}
}
