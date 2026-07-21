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
		$recommended = self::nextSupportedPower($referencePowerKva);
		$warningKeys = array();
		$status = LmdbPropalPVConnectionPowerResult::STATUS_COMPLIANT;

		if (!$inverterPowerUsable || $referencePowerKva <= 0.0) {
			$status = LmdbPropalPVConnectionPowerResult::STATUS_INCOMPLETE;
			$warningKeys[] = 'LmdbPropalPVConnectionInverterDataIncomplete';
		}

		if ($referencePowerKva > 36.0) {
			$warningKeys[] = 'LmdbPropalPVConnectionAbove36Study';
		}
		if ($input->phaseMode === 'single' && $referencePowerKva > 6.0) {
			if ($status !== LmdbPropalPVConnectionPowerResult::STATUS_INCOMPLETE) {
				$status = LmdbPropalPVConnectionPowerResult::STATUS_PHASE_INCOMPATIBLE;
			}
			$warningKeys[] = 'LmdbPropalPVConnectionSinglePhaseLimit';
		} elseif ($input->phaseMode === 'three' && $referencePowerKva > 0.0 && $referencePowerKva <= 36.0) {
			$warningKeys[] = 'LmdbPropalPVConnectionThreePhaseBalanceCheck';
		}

		if ($referencePowerKva > 0.0 && $input->subscribedPowerKva + 0.00000001 < $referencePowerKva) {
			if ($status === LmdbPropalPVConnectionPowerResult::STATUS_COMPLIANT) {
				$status = LmdbPropalPVConnectionPowerResult::STATUS_INCREASE_TO_CHECK;
			}
			$warningKeys[] = 'LmdbPropalPVConnectionSubscriptionIncreaseCheck';
		}
		if ($referencePowerKva > 250.0) {
			$warningKeys[] = 'LmdbPropalPVConnectionNoAutomaticPowerRecommendation';
		}

		return new LmdbPropalPVConnectionPowerResult(
			$input->peakPowerKwp,
			$input->inverterNominalPowerKva,
			$referencePowerKva,
			$input->subscribedPowerKva,
			$recommended,
			$input->phaseMode,
			$status,
			array_values(array_unique($warningKeys)),
			$inverterPowerUsable
		);
	}

	/** Return the smallest supported Blue/Yellow subscribed power at or above Pmax. */
	private static function nextSupportedPower(float $powerKva): ?float
	{
		if ($powerKva <= 0.0) {
			return null;
		}
		foreach (array(3.0, 6.0, 9.0, 12.0, 15.0, 18.0, 24.0, 30.0, 36.0) as $bluePower) {
			if ($bluePower + 0.00000001 >= $powerKva) {
				return $bluePower;
			}
		}
		if ($powerKva <= 250.00000001) {
			return (float) ceil($powerKva - 0.00000001);
		}

		return null;
	}
}
