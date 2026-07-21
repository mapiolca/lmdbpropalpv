<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

require_once dirname(__DIR__).'/class/lmdbpropalpvfinancialcalculator.class.php';
require_once dirname(__DIR__).'/class/lmdbpropalpvtariffmatcher.class.php';
require_once dirname(__DIR__).'/class/lmdbpropalpvpaneldegradationresolver.class.php';
require_once dirname(__DIR__).'/lib/lmdbpropalpv.lib.php';

/** @throws RuntimeException */
function assertNear(float $actual, float $expected, float $tolerance, string $label): void
{
	if (abs($actual - $expected) > $tolerance) {
		throw new RuntimeException($label.' expected '.$expected.', got '.$actual);
	}
}

$calculator = new LmdbPropalPVFinancialCalculator();
$subscribedPowerOptions = lmdbpropalpvGetSubscribedPowerOptions();
if (count($subscribedPowerOptions) !== 223 || !isset($subscribedPowerOptions[36], $subscribedPowerOptions[37], $subscribedPowerOptions[250]) || isset($subscribedPowerOptions[251])) {
	throw new RuntimeException('Subscribed power options must include Blue powers and every Yellow power from 37 to 250 kVA.');
}
foreach (array(3.0, 36.0, 37.0, 42.0, 250.0) as $supportedPower) {
	if (!lmdbpropalpvSubscribedPowerIsSupported($supportedPower)) {
		throw new RuntimeException('Supported subscribed power rejected: '.((string) $supportedPower).' kVA.');
	}
}
foreach (array(0.0, 4.0, 36.5, 250.5, 251.0) as $unsupportedPower) {
	if (lmdbpropalpvSubscribedPowerIsSupported($unsupportedPower)) {
		throw new RuntimeException('Unsupported subscribed power accepted: '.((string) $unsupportedPower).' kVA.');
	}
}
$input = new LmdbPropalPVFinancialInput(1884.7575, 'EUR', 3.0, 3456.0, 0.68, 0.0045, 0.0045, 0.03, 0.2146, 0.04, 80.0);
$result = $calculator->calculate($input);

if (count($result->years) !== 20) {
	throw new RuntimeException('The projection must contain exactly 20 years.');
}
assertNear($result->initialCashflow, -1884.7575, 0.000001, 'Year 0 cash flow');
assertNear($result->years[0]->productionKwh, 3456.0 * (1.0 - 0.0045), 0.000001, 'Year 1 production');
assertNear($result->years[1]->productionKwh, 3456.0 * (1.0 - 0.0045) * pow(1.0 - 0.0045, 1), 0.000001, 'Year 2 production');
assertNear($result->years[19]->productionKwh, 3456.0 * (1.0 - 0.0045) * pow(1.0 - 0.0045, 19), 0.000001, 'Year 20 production');
assertNear($result->years[0]->premium, 240.0, 0.000001, 'Year 1 premium');
assertNear($result->years[0]->cumulativeCashflow, -1098.662069856, 0.000001, 'Year 1 cumulative cash flow');
assertNear($result->years[19]->premium, 0.0, 0.000001, 'Year 20 premium');
assertNear($result->totalProductionKwh, 65945.302371288, 0.000001, 'Total production');
assertNear($result->totalElectricitySavings, 12872.117032902, 0.000001, 'Total savings');
assertNear($result->totalSurplusSale, 844.099870352, 0.000001, 'Total feed-in sales');
assertNear($result->totalGrossGain, 13956.216903254, 0.000001, 'Gross gain');
assertNear($result->netGain, 12071.459403254, 0.000001, 'Net gain');
assertNear($result->roiRate, 6.404781200369, 0.000000001, '20-year ROI');
assertNear($result->averageAnnualReturnRate, 0.37023906001845, 0.000000001, 'Average annual return');
assertNear((float) $result->paybackYears, 2.944947177809, 0.000001, 'Interpolated payback');
assertNear($result->simplifiedProductionCostPerKwh, 0.024941238282, 0.000000001, 'Simplified production cost');

$slow = $calculator->calculate(new LmdbPropalPVFinancialInput(100000.0, 'EUR', 3.0, 1000.0, 0.5, 0.005, 0.005, 0.0, 0.10, 0.02, 0.0));
if ($slow->paybackYears !== null) {
	throw new RuntimeException('Payback must be null when it is not reached within 20 years.');
}

$invalidRejected = false;
try {
	$calculator->calculate(new LmdbPropalPVFinancialInput(10000.0, 'EUR', 3.0, 3000.0, 1.01, 0.005, 0.005, 0.0, 0.20, 0.04, 80.0));
} catch (InvalidArgumentException $exception) {
	$invalidRejected = true;
}
if (!$invalidRejected) {
	throw new RuntimeException('An out-of-range self-consumption value must be rejected.');
}

$invalidInputs = array(
	new LmdbPropalPVFinancialInput(0.0, 'EUR', 3.0, 3000.0, 0.5, 0.005, 0.005, 0.0, 0.20, 0.04, 80.0),
	new LmdbPropalPVFinancialInput(10000.0, 'EUR', 0.0, 3000.0, 0.5, 0.005, 0.005, 0.0, 0.20, 0.04, 80.0),
	new LmdbPropalPVFinancialInput(10000.0, 'EUR', 3.0, 0.0, 0.5, 0.005, 0.005, 0.0, 0.20, 0.04, 80.0),
	new LmdbPropalPVFinancialInput(10000.0, 'EUR', 3.0, 3000.0, 0.5, 1.0, 0.005, 0.0, 0.20, 0.04, 80.0),
	new LmdbPropalPVFinancialInput(10000.0, 'EUR', 3.0, 3000.0, 0.5, 0.005, 1.0, 0.0, 0.20, 0.04, 80.0),
	new LmdbPropalPVFinancialInput(10000.0, 'EUR', 3.0, 3000.0, 0.5, 0.005, 0.005, -1.0, 0.20, 0.04, 80.0),
	new LmdbPropalPVFinancialInput(10000.0, 'EUR', 3.0, 3000.0, 0.5, 0.005, 0.005, 0.0, 0.20, -0.01, 80.0),
);
foreach ($invalidInputs as $index => $invalidInput) {
	$rejected = false;
	try {
		$calculator->calculate($invalidInput);
	} catch (InvalidArgumentException $exception) {
		$rejected = true;
	}
	if (!$rejected) {
		throw new RuntimeException('Invalid input case '.((string) $index).' must be rejected.');
	}
}

$rangeCases = array(
	array(3.0, 0.0, 3.0, true),
	array(3.0, 3.0, 9.0, false),
	array(3.000001, 3.0, 9.0, true),
	array(9.0, 3.0, 9.0, true),
	array(9.0, 9.0, 36.0, false),
	array(9.000001, 9.0, 36.0, true),
	array(100.0, 36.0, 100.0, true),
	array(100.000001, 36.0, 100.0, false),
);
foreach ($rangeCases as $case) {
	$matches = LmdbPropalPVTariffMatcher::powerRangeMatches($case[0], $case[1], $case[2]);
	if ($matches !== $case[3]) {
		throw new RuntimeException('Tariff range boundary assertion failed for '.((string) $case[0]).' kWp.');
	}
}

$weighted = LmdbPropalPVPanelDegradationResolver::aggregate(array(
	array('product_ref' => 'MODULE-A', 'weight_wp' => 2000.0, 'first_year_degradation_pct' => 1.0, 'annual_degradation_pct' => 0.4),
	array('product_ref' => 'MODULE-B', 'weight_wp' => 1000.0, 'first_year_degradation_pct' => 2.0, 'annual_degradation_pct' => 0.6),
), 0.45, 0.45);
assertNear($weighted['first_year_degradation_pct'], 4.0 / 3.0, 0.000000001, 'Weighted first-year degradation');
assertNear($weighted['annual_degradation_pct'], 7.0 / 15.0, 0.000000001, 'Weighted annual degradation');
if ($weighted['used_defaults']) {
	throw new RuntimeException('Complete panel characteristics must not trigger entity defaults.');
}

$zeroAndPartial = LmdbPropalPVPanelDegradationResolver::aggregate(array(
	array('product_ref' => 'MODULE-ZERO', 'weight_wp' => 1000.0, 'first_year_degradation_pct' => 0.0, 'annual_degradation_pct' => 0.0),
	array('product_ref' => 'MODULE-PARTIAL', 'weight_wp' => 1000.0, 'first_year_degradation_pct' => null, 'annual_degradation_pct' => 0.5),
), 0.45, 0.45);
assertNear($zeroAndPartial['first_year_degradation_pct'], 0.225, 0.000000001, 'Zero rate and first-year fallback');
assertNear($zeroAndPartial['annual_degradation_pct'], 0.25, 0.000000001, 'Zero rate and annual value');
if (!$zeroAndPartial['used_defaults'] || $zeroAndPartial['fallback_product_refs'] !== array('MODULE-PARTIAL')) {
	throw new RuntimeException('Only the product with missing data must be reported as a fallback.');
}

$invalidPanelData = LmdbPropalPVPanelDegradationResolver::aggregate(array(
	array('product_ref' => 'MODULE-INVALID', 'weight_wp' => 500.0, 'first_year_degradation_pct' => 100.0, 'annual_degradation_pct' => -0.1),
), 0.45, 0.45);
assertNear($invalidPanelData['first_year_degradation_pct'], 0.45, 0.000000001, 'Invalid first-year fallback');
assertNear($invalidPanelData['annual_degradation_pct'], 0.45, 0.000000001, 'Invalid annual fallback');
if ($invalidPanelData['fallback_product_refs'] !== array('MODULE-INVALID')) {
	throw new RuntimeException('Invalid degradation rates must report their product reference.');
}

$fallback = LmdbPropalPVPanelDegradationResolver::aggregate(array(), 0.45, 0.45);
assertNear($fallback['first_year_degradation_pct'], 0.45, 0.000000001, 'No-module first-year fallback');
assertNear($fallback['annual_degradation_pct'], 0.45, 0.000000001, 'No-module annual fallback');
if ($fallback['warning_keys'] !== array('LmdbPropalPVDegradationFallbackNoEligibleModule')) {
	throw new RuntimeException('No-module fallback warning is missing.');
}

print "All financial calculator tests passed.\n";
