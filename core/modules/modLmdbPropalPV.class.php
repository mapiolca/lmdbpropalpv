<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/** Module descriptor. */
class modLmdbPropalPV extends DolibarrModules
{
	/** @param DoliDB $db Database handler */
	public function __construct($db)
	{
		global $conf, $langs;

		$this->db = $db;
		$this->numero = 450010;
		$this->rights_class = 'lmdbpropalpv';
		$this->family = 'Les Métiers du Bâtiment';
		$this->module_position = 90;
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'LmdbPropalPVModuleDescription';
		$this->descriptionlong = 'LmdbPropalPVModuleDescriptionLong';
		$this->version = '1.0.0';
		$this->editor_name = 'Les Métiers du Bâtiment';
		$this->editor_url = 'https://lesmetiersdubatiment.fr';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'solar-panel';
		$this->module_parts = array(
			'models' => 1,
			'hooks' => array('propalcard'),
		);
		$this->dirs = array();
		$this->config_page_url = array('setup.php@lmdbpropalpv');
		$this->hidden = false;
		$this->depends = array('modPropale', 'modPowerPlantPV');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array('lmdbpropalpv@lmdbpropalpv');
		$this->phpmin = array(8, 0);
		$this->need_dolibarr_version = array(20, 0);
		$this->need_javascript_ajax = 0;

		$langs->load('lmdbpropalpv@lmdbpropalpv');
		$this->warnings_activation = array('always' => $langs->trans('LmdbPropalPVActivationWarning'));
		$this->warnings_activation_ext = array();

		$this->const = array(
			array('LMDBPROPALPV_DEFAULT_SELF_CONSUMPTION_PCT', 'chaine', '68', 'Default self-consumption percentage', 0, 'current', 0),
			array('LMDBPROPALPV_DEFAULT_FIRST_YEAR_DEGRADATION_PCT', 'chaine', '0.45', 'Default first-year panel degradation percentage', 0, 'current', 0),
			array('LMDBPROPALPV_DEFAULT_PANEL_DEGRADATION_PCT', 'chaine', '0.45', 'Default annual panel degradation percentage', 0, 'current', 0),
			array('LMDBPROPALPV_DEFAULT_ELECTRICITY_GROWTH_PCT', 'chaine', '3', 'Default electricity annual growth percentage', 0, 'current', 0),
			array('LMDBPROPALPV_DEFAULT_RETAIL_TARIFF_MODE', 'chaine', 'base', 'Default retail tariff mode', 0, 'current', 0),
			array('LMDBPROPALPV_DEFAULT_RETAIL_SUBSCRIPTION_KVA', 'chaine', '6', 'Default retail subscribed power', 0, 'current', 0),
			array('LMDBPROPALPV_PDF_PRIMARY_COLOR', 'chaine', '#16324F', 'Primary PDF color', 0, 'current', 0),
			array('LMDBPROPALPV_PDF_ACCENT_COLOR', 'chaine', '#F2B705', 'Accent PDF color', 0, 'current', 0),
			array('LMDBPROPALPV_FINANCIAL_DISCLAIMER', 'chaine', '', 'Optional financial disclaimer', 0, 'current', 0),
		);

		$r = 0;
		$r++;
		$this->rights[$r][0] = $this->numero * 100 + $r;
		$this->rights[$r][1] = 'LmdbPropalPVRightReadStudy';
		$this->rights[$r][3] = 1;
		$this->rights[$r][4] = 'study';
		$this->rights[$r][5] = 'read';
		$r++;
		$this->rights[$r][0] = $this->numero * 100 + $r;
		$this->rights[$r][1] = 'LmdbPropalPVRightWriteStudy';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'study';
		$this->rights[$r][5] = 'write';
		$r++;
		$this->rights[$r][0] = $this->numero * 100 + $r;
		$this->rights[$r][1] = 'LmdbPropalPVRightConfigure';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'setup';
		$this->rights[$r][5] = 'write';

		$this->menus = array();
		$this->tabs = array(
			'propal:+lmdbpropalpv:FinancialStudyPV:lmdbpropalpv@lmdbpropalpv:'.
				'isModEnabled("lmdbpropalpv") && ($user->admin || (isModEnabled("multicompany") && ($user->hasRight("multicompany", "admin", "read") || $user->hasRight("multicompany", "admin", "write") || $user->hasRight("multicompany", "entities", "write"))) || ($user->hasRight("lmdbpropalpv", "study", "read") && $user->hasRight("propal", "lire")))'.
				':/lmdbpropalpv/propal_financial.php?id=__ID__',
		);
	}

	/** @param string $options Activation options @return int */
	public function init($options = '')
	{
		global $conf;

		if ($this->checkPowerPlantDependency() < 0) {
			return -1;
		}
		$result = $this->_load_tables('/lmdbpropalpv/sql/');
		if ($result <= 0) {
			return -1;
		}
		if ($this->createProposalExtraFields() < 0) {
			return -1;
		}
		if ($this->backfillFirstYearDegradation() < 0) {
			return -1;
		}

		require_once dirname(__DIR__, 2).'/class/lmdbpropalpvtariffseed.class.php';
		$seed = new LmdbPropalPVTariffSeed($this->db);
		if ($seed->seed((int) $conf->entity) < 0) {
			$this->error = $seed->error;
			return -1;
		}

		$initialPdfSetupRequired = !getDolGlobalInt('LMDBPROPALPV_INITIAL_PDF_SETUP_DONE');
		if ($initialPdfSetupRequired) {
			if ($this->initializeProposalModels() < 0) {
				return -1;
			}
		}

		$result = $this->_init(array(), $options);
		if ($result <= 0) {
			return -1;
		}
		if ($initialPdfSetupRequired && dolibarr_set_const($this->db, 'LMDBPROPALPV_INITIAL_PDF_SETUP_DONE', '1', 'chaine', 0, '', (int) $conf->entity) <= 0) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		return 1;
	}

	/** @return int */
	private function checkPowerPlantDependency()
	{
		global $langs;

		$langs->load('lmdbpropalpv@lmdbpropalpv');
		dol_include_once('/powerplantpv/core/modules/modPowerPlantPV.class.php');
		dol_include_once('/powerplantpv/lib/powerplantpv.lib.php');
		if (!isModEnabled('powerplantpv') || !class_exists('modPowerPlantPV') || !function_exists('powerplantpvGetObjectPeakPowerKwc')) {
			$this->error = $langs->trans('LmdbPropalPVPowerPlantDependencyMissing');
			return -1;
		}
		$descriptor = new modPowerPlantPV($this->db);
		if (version_compare((string) $descriptor->version, '1.3.0', '<')) {
			$this->error = $langs->trans('LmdbPropalPVPowerPlantVersionTooOld', (string) $descriptor->version);
			return -1;
		}

		return 1;
	}

	/** @param string $options Deactivation options @return int */
	public function remove($options = '')
	{
		// All module constants have deleteonunactive=0. The native removal therefore
		// disables the module while preserving its settings and first-run marker.
		return $this->_remove(array(), $options);
	}

	/** @return int */
	private function createProposalExtraFields()
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		$extrafields = new ExtraFields($this->db);
		$fields = array(
			array('lmdbpropalpv_annual_production_kwh', 'LmdbPropalPVAnnualProduction', 'double', '24,8', ''),
			array('lmdbpropalpv_self_consumption_pct', 'LmdbPropalPVSelfConsumption', 'double', '8,4', ''),
			array('lmdbpropalpv_first_year_degradation_pct', 'LmdbPropalPVFirstYearDegradation', 'double', '8,4', ''),
			array('lmdbpropalpv_panel_degradation_pct', 'LmdbPropalPVPanelDegradation', 'double', '8,4', ''),
			array('lmdbpropalpv_electricity_growth_pct', 'LmdbPropalPVElectricityGrowth', 'double', '8,4', ''),
			array('lmdbpropalpv_tariff_reference_date', 'LmdbPropalPVTariffReferenceDate', 'date', '', ''),
			array('lmdbpropalpv_retail_tariff_mode', 'LmdbPropalPVRetailTariffMode', 'select', '16', array('options' => array('base' => 'LmdbPropalPVBase', 'peak' => 'LmdbPropalPVPeakHours', 'manual' => 'LmdbPropalPVManual'))),
			array('lmdbpropalpv_retail_subscription_kva', 'LmdbPropalPVSubscribedPower', 'double', '8,4', ''),
			array('lmdbpropalpv_retail_price_per_kwh', 'LmdbPropalPVRetailPrice', 'double', '24,8', ''),
			array('lmdbpropalpv_feed_in_price_per_kwh', 'LmdbPropalPVFeedInPrice', 'double', '24,8', ''),
			array('lmdbpropalpv_premium_per_kwp', 'LmdbPropalPVPremiumPerKwp', 'double', '24,8', ''),
			array('lmdbpropalpv_tariff_set_id', 'LmdbPropalPVTariffSet', 'int', '11', ''),
		);
		$position = 5100;
		foreach ($fields as $field) {
			$result = $extrafields->addExtraField($field[0], $field[1], $field[2], $position++, $field[3], 'propal', 0, 0, '', $field[4], 0, '', '0', '', '', (string) ((int) $conf->entity), 'lmdbpropalpv@lmdbpropalpv', 'isModEnabled("lmdbpropalpv")', 0, 0);
			if ($result < 0) {
				$this->error = $extrafields->error;
				return -1;
			}
		}

		return 1;
	}

	/** Initialize the new snapshot only on proposals that already contain a saved study. @return int */
	private function backfillFirstYearDegradation()
	{
		global $conf;

		$default = (float) getDolGlobalString('LMDBPROPALPV_DEFAULT_FIRST_YEAR_DEGRADATION_PCT', '0.45');
		if ($default < 0.0 || $default >= 100.0) {
			$default = 0.45;
		}
		$sql = 'UPDATE '.MAIN_DB_PREFIX.'propal_extrafields as pe';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'propal as p ON p.rowid = pe.fk_object';
		$sql .= ' SET pe.lmdbpropalpv_first_year_degradation_pct = '.$this->db->escape((string) $default);
		$sql .= ' WHERE p.entity = '.((int) $conf->entity);
		$sql .= ' AND pe.lmdbpropalpv_first_year_degradation_pct IS NULL';
		$sql .= ' AND (pe.lmdbpropalpv_annual_production_kwh IS NOT NULL OR pe.lmdbpropalpv_panel_degradation_pct IS NOT NULL)';
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		return 1;
	}

	/** @return int */
	private function initializeProposalModels()
	{
		global $conf, $langs;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		$langs->load('lmdbpropalpv@lmdbpropalpv');
		foreach (array(
			'lmdbpropalpv_withpictures' => 'LmdbPropalPVModelWithPictures',
			'lmdbpropalpv_withoutpictures' => 'LmdbPropalPVModelWithoutPictures',
		) as $name => $label) {
			$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'document_model';
			$sql .= " WHERE nom = '".$this->db->escape($name)."' AND type = 'propal' AND entity = ".((int) $conf->entity);
			$resql = $this->db->query($sql);
			if (!$resql) {
				$this->error = $this->db->lasterror();
				return -1;
			}
			$exists = is_object($this->db->fetch_object($resql));
			$this->db->free($resql);
			if (!$exists && addDocumentModel($name, 'propal', $langs->trans($label), $langs->trans('LmdbPropalPVModuleDescription')) <= 0) {
				$this->error = $this->db->lasterror();
				return -1;
			}
		}

		if (dolibarr_set_const($this->db, 'PROPALE_ADDON_PDF', 'lmdbpropalpv_withpictures', 'chaine', 0, '', (int) $conf->entity) <= 0) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}
}
