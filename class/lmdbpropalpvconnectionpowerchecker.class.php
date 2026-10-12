<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

require_once __DIR__.'/lmdbpropalpvconnectionpowerinput.class.php';
require_once __DIR__.'/lmdbpropalpvconnectionpowerresult.class.php';

/** Pure Enedis-oriented connection-power check, without SQL or rendering. */
class LmdbPropalPVConnectionPowerChecker
{
	public function check(LmdbPropalPVConnectionPowerInput $input): LmdbPropalPVConnectionPowerResult
	{
		$inverterPowerUsable = $input->inverterDataComplete && $input->inverterNominalPowerKva !== null && $input->inverterNominalPowerKva > 0.0;
		$referencePowerKva = $inverterPowerUsable
			? min(max(0.0, $input->peakPowerKwp), (float) $input->inverterNominalPowerKva)
			: max(0.0, $input->peakPowerKwp);
		// Import subscription does not determine the allowed export power.
		$injectionPowerKva = $referencePowerKva;
		$limit = $input->injectionLimitKva;
		$limitValid = $limit === null || (is_finite($limit) && $limit >= 0.0);
		if ($limit !== null && $limitValid) {
			$injectionPowerKva = min($referencePowerKva, $limit);
		}
		$warningKeys = array();
		$status = LmdbPropalPVConnectionPowerResult::STATUS_COMPLIANT;

		if (!$inverterPowerUsable || $referencePowerKva <= 0.0) {
			$status = LmdbPropalPVConnectionPowerResult::STATUS_INCOMPLETE;
			$warningKeys[] = 'LmdbPropalPVConnectionInverterDataIncomplete';
		}

		if ($injectionPowerKva > 36.0) {
			$warningKeys[] = 'LmdbPropalPVConnectionAbove36Study';
		}
		if ($input->phaseMode === 'single' && $injectionPowerKva > 6.0) {
			if ($status !== LmdbPropalPVConnectionPowerResult::STATUS_INCOMPLETE) {
				$status = LmdbPropalPVConnectionPowerResult::STATUS_PHASE_INCOMPATIBLE;
			}
			$warningKeys[] = 'LmdbPropalPVConnectionSinglePhaseLimit';
		} elseif ($input->phaseMode === 'three' && $injectionPowerKva > 0.0 && $injectionPowerKva <= 36.0) {
			$warningKeys[] = 'LmdbPropalPVConnectionThreePhaseBalanceCheck';
		}

		if (!$limitValid) {
			$status = LmdbPropalPVConnectionPowerResult::STATUS_INCOMPLETE;
			$warningKeys[] = 'LmdbPropalPVInvalidInjectionLimit';
		} elseif ($limit !== null && $limit < $referencePowerKva) {
			$warningKeys[] = 'LmdbPropalPVConnectionInjectionLimited';
		}

		$result = new LmdbPropalPVConnectionPowerResult(
			$input->peakPowerKwp,
			$input->inverterNominalPowerKva,
			$referencePowerKva,
			$input->subscribedPowerKva,
			null,
			$input->phaseMode,
			$status,
			array_values(array_unique($warningKeys)),
			$inverterPowerUsable
		);
		$result->injectionPowerKva = $injectionPowerKva;
		return $result;
	}
}
