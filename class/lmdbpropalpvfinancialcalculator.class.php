<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

require_once __DIR__.'/lmdbpropalpvfinancialinput.class.php';
require_once __DIR__.'/lmdbpropalpvfinancialresult.class.php';

/** Pure, deterministic financial engine. */
class LmdbPropalPVFinancialCalculator
{
	/** @deprecated Use the projection duration carried by LmdbPropalPVFinancialInput. */
	public const PROJECTION_YEARS = 20;
	public const MIN_PROJECTION_YEARS = 1;
	public const MAX_PROJECTION_YEARS = 50;

	/**
	 * Calculate the full projection without SQL, rendering or intermediate rounding.
	 *
	 * @throws InvalidArgumentException When inputs are outside the supported domain
	 */
	public function calculate(LmdbPropalPVFinancialInput $input): LmdbPropalPVFinancialResult
	{
		$this->validate($input);
		$result = new LmdbPropalPVFinancialResult();
		$result->projectionYears = $input->projectionYears;
		$result->initialCashflow = -$input->investmentTtc;
		$cumulative = $result->initialCashflow;

		for ($yearNumber = 1; $yearNumber <= $input->projectionYears; $yearNumber++) {
			$exponent = $yearNumber - 1;
			$year = new LmdbPropalPVFinancialYear();
			$year->year = $yearNumber;
			$year->productionKwh = $input->annualProductionKwh
				* (1.0 - $input->firstYearPanelDegradationRate)
				* pow(1.0 - $input->annualPanelDegradationRate, $exponent);
			$year->retailPricePerKwh = $input->retailPricePerKwh * pow(1.0 + $input->electricityGrowthRate, $exponent);
			$year->surplusSale = $year->productionKwh * (1.0 - $input->selfConsumptionRate) * $input->feedInPricePerKwh;
			$year->electricitySavings = $year->productionKwh * $input->selfConsumptionRate * $year->retailPricePerKwh;
			$year->premium = $yearNumber === 1 ? $input->peakPowerKwp * $input->premiumPerKwp : 0.0;
			$year->annualGain = $year->surplusSale + $year->electricitySavings + $year->premium;
			$previousCumulative = $cumulative;
			$cumulative += $year->annualGain;
			$year->cumulativeCashflow = $cumulative;
			$year->annualReturnRate = $year->annualGain / $input->investmentTtc;

			if ($result->paybackYears === null && $cumulative >= 0.0 && $year->annualGain > 0.0) {
				$result->paybackYears = (float) ($yearNumber - 1) + (-$previousCumulative / $year->annualGain);
			}

			$result->totalProductionKwh += $year->productionKwh;
			$result->totalSurplusSale += $year->surplusSale;
			$result->totalElectricitySavings += $year->electricitySavings;
			$result->totalPremium += $year->premium;
			$result->totalGrossGain += $year->annualGain;
			$result->years[] = $year;
		}

		$result->netGain = $result->totalGrossGain - $input->investmentTtc;
		$result->roiRate = $result->netGain / $input->investmentTtc;
		$result->averageAnnualReturnRate = ($result->totalGrossGain / $input->investmentTtc) / $input->projectionYears;
		$result->simplifiedProductionCostPerKwh = $result->totalProductionKwh > 0.0
			? ($input->investmentTtc - $result->totalPremium) / $result->totalProductionKwh
			: 0.0;

		return $result;
	}

	/** @throws InvalidArgumentException */
	private function validate(LmdbPropalPVFinancialInput $input): void
	{
		if ($input->projectionYears < self::MIN_PROJECTION_YEARS || $input->projectionYears > self::MAX_PROJECTION_YEARS) {
			throw new InvalidArgumentException('Projection duration must be between one and fifty years.');
		}
		if ($input->investmentTtc <= 0.0 || $input->peakPowerKwp <= 0.0 || $input->annualProductionKwh <= 0.0) {
			throw new InvalidArgumentException('Investment, peak power and annual production must be positive.');
		}
		if ($input->selfConsumptionRate < 0.0 || $input->selfConsumptionRate > 1.0) {
			throw new InvalidArgumentException('Self-consumption rate must be between zero and one.');
		}
		if ($input->firstYearPanelDegradationRate < 0.0 || $input->firstYearPanelDegradationRate >= 1.0
			|| $input->annualPanelDegradationRate < 0.0 || $input->annualPanelDegradationRate >= 1.0) {
			throw new InvalidArgumentException('Panel degradation rates must be between zero and one, excluding one.');
		}
		if ($input->electricityGrowthRate <= -1.0 || $input->retailPricePerKwh <= 0.0 || $input->feedInPricePerKwh < 0.0 || $input->premiumPerKwp < 0.0) {
			throw new InvalidArgumentException('Growth and tariff values are outside the supported domain.');
		}
	}
}
