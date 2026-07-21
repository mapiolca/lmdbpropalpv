<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

require_once __DIR__.'/lmdbpropalpvpaneldegradationresolver.class.php';

/** Compatibility registry for all conditional features. */
class LmdbPropalPVCompatibility
{
	/**
	 * @return array<string,array{label:string,description:string,min_dolibarr:string,core_available_from:string,module_available_from:string,min_php:string,compatibility_check:string,available:bool,reason:string}>
	 */
	public static function getFeatures()
	{
		global $db;

		$powerplantVersion = '';
		if (isModEnabled('powerplantpv')) {
			dol_include_once('/powerplantpv/core/modules/modPowerPlantPV.class.php');
			dol_include_once('/powerplantpv/lib/powerplantpv.lib.php');
			if (class_exists('modPowerPlantPV') && is_object($db)) {
				$descriptor = new modPowerPlantPV($db);
				$powerplantVersion = (string) $descriptor->version;
			}
		}
		$helperAvailable = function_exists('powerplantpvGetObjectPeakPowerKwc');
		$powerplantCompatible = isModEnabled('powerplantpv') && $helperAvailable && $powerplantVersion !== '' && version_compare($powerplantVersion, '1.3.0', '>=');
		$degradationSchemaAvailable = $powerplantCompatible && is_object($db) && LmdbPropalPVPanelDegradationResolver::isSchemaAvailable($db);

		return array(
			'financial_study' => array(
				'label' => 'LmdbPropalPVFeatureFinancialStudy',
				'description' => 'LmdbPropalPVFeatureFinancialStudyDescription',
				'min_dolibarr' => '20.0.0',
				'core_available_from' => '20.0.0',
				'module_available_from' => '20.0.0',
				'min_php' => '8.0.0',
				'compatibility_check' => "version_compare(DOL_VERSION, '20.0.0', '>=') && version_compare(PHP_VERSION, '8.0.0', '>=')",
				'available' => version_compare(DOL_VERSION, '20.0.0', '>=') && version_compare(PHP_VERSION, '8.0.0', '>='),
				'reason' => 'LmdbPropalPVRequiresDolibarr20Php80',
			),
			'powerplant_peak_power' => array(
				'label' => 'LmdbPropalPVFeaturePowerPlantPV',
				'description' => 'LmdbPropalPVFeaturePowerPlantPVDescription',
				'min_dolibarr' => '20.0.0',
				'core_available_from' => '20.0.0',
				'module_available_from' => '20.0.0',
				'min_php' => '8.0.0',
				'compatibility_check' => "isModEnabled('powerplantpv') && PowerPlantPV >= 1.3.0 && function_exists('powerplantpvGetObjectPeakPowerKwc')",
				'available' => $powerplantCompatible,
				'reason' => 'LmdbPropalPVRequiresPowerPlantPV13',
			),
			'powerplant_product_degradation' => array(
				'label' => 'LmdbPropalPVFeaturePanelDegradation',
				'description' => 'LmdbPropalPVFeaturePanelDegradationDescription',
				'min_dolibarr' => '20.0.0',
				'core_available_from' => '20.0.0',
				'module_available_from' => '20.0.0',
				'min_php' => '8.0.0',
				'compatibility_check' => "PowerPlantPV >= 1.3.0 && columns pmax, first_year_degradation, annual_degradation available",
				'available' => $degradationSchemaAvailable,
				'reason' => 'LmdbPropalPVPanelDegradationFallbackReason',
			),
			'online_signature' => array(
				'label' => 'LmdbPropalPVFeatureOnlineSignature',
				'description' => 'LmdbPropalPVFeatureOnlineSignatureDescription',
				'min_dolibarr' => '20.0.0',
				'core_available_from' => '20.0.0',
				'module_available_from' => '20.0.0',
				'min_php' => '8.0.0',
				'compatibility_check' => "version_compare(DOL_VERSION, '20.0.0', '>=') && function_exists('getOnlineSignatureUrl')",
				'available' => function_exists('getOnlineSignatureUrl'),
				'reason' => 'LmdbPropalPVOnlineSignatureUnavailable',
			),
			'pdf_composition' => array(
				'label' => 'LmdbPropalPVFeaturePdfComposition',
				'description' => 'LmdbPropalPVFeaturePdfCompositionDescription',
				'min_dolibarr' => '20.0.0',
				'core_available_from' => '20.0.0',
				'module_available_from' => '20.0.0',
				'min_php' => '8.0.0',
				'compatibility_check' => "!getDolGlobalInt('MAIN_DISABLE_TCPDI') && is_readable(TCPDI_PATH.'tcpdi.php')",
				'available' => !getDolGlobalInt('MAIN_DISABLE_TCPDI') && defined('TCPDI_PATH') && is_readable(TCPDI_PATH.'tcpdi.php'),
				'reason' => 'LmdbPropalPVPdfCompositionUnavailable',
			),
			'qr_code' => array(
				'label' => 'LmdbPropalPVFeatureQrCode',
				'description' => 'LmdbPropalPVFeatureQrCodeDescription',
				'min_dolibarr' => '20.0.0',
				'core_available_from' => '20.0.0',
				'module_available_from' => '20.0.0',
				'min_php' => '8.0.0',
				'compatibility_check' => "class_exists('TCPDF') || class_exists('TCPDI') || is_readable(TCPDF path)",
				'available' => class_exists('TCPDF') || class_exists('TCPDI') || is_readable(DOL_DOCUMENT_ROOT.'/includes/tecnickcom/tcpdf/tcpdf.php'),
				'reason' => 'LmdbPropalPVQrCodeUnavailable',
			),
		);
	}

	/** @return bool */
	public static function isFeatureAvailable($code)
	{
		$features = self::getFeatures();

		return isset($features[$code]) && $features[$code]['available'];
	}
}
