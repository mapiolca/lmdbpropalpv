<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

require_once dirname(__DIR__).'/class/lmdbpropalpvfinancialcalculator.class.php';
require_once dirname(__DIR__).'/class/lmdbpropalpvtariffmatcher.class.php';

/** @throws RuntimeException */
function assertNear(float $actual, float $expected, float $tolerance, string $label): void
{
	if (abs($actual - $expected) > $tolerance) {
		throw new RuntimeException($label.' expected '.$expected.', got '.$actual);
	}
}

$calculator = new LmdbPropalPVFinancialCalculator();
$input = new LmdbPropalPVFinancialInput(1884.7575, 'EUR', 3.0, 3456.0, 0.68, 0.0045, 0.03, 0.2146, 0.04, 80.0);
$result = $calculator->calculate($input);

if (count($result->years) !== 20) {
	throw new RuntimeException('The projection must contain exactly 20 years.');
}
assertNear($result->initialCashflow, -1884.7575, 0.000001, 'Year 0 cash flow');
assertNear($result->years[0]->productionKwh, 3456.0, 0.000001, 'Year 1 production');
assertNear($result->years[0]->premium, 240.0, 0.000001, 'Year 1 premium');
assertNear($result->years[0]->cumulativeCashflow, -1096.193532, 0.000001, 'Year 1 cumulative cash flow');
assertNear($result->years[19]->premium, 0.0, 0.000001, 'Year 20 premium');
assertNear($result->totalProductionKwh, 66243.397661, 0.000001, 'Total production');
assertNear($result->totalElectricitySavings, 12930.303398, 0.000001, 'Total savings');
assertNear($result->totalSurplusSale, 847.915490, 0.000001, 'Total feed-in sales');
assertNear($result->totalGrossGain, 14018.218888, 0.000001, 'Gross gain');
assertNear($result->netGain, 12133.461388, 0.000001, 'Net gain');
assertNear($result->roiRate, 6.437677732, 0.000000001, '20-year ROI');
assertNear($result->averageAnnualReturnRate, 0.371883887, 0.000000001, 'Average annual return');
assertNear((float) $result->paybackYears, 2.931996, 0.000001, 'Interpolated payback');
assertNear($result->simplifiedProductionCostPerKwh, 0.024829003, 0.000000001, 'Simplified production cost');

$slow = $calculator->calculate(new LmdbPropalPVFinancialInput(100000.0, 'EUR', 3.0, 1000.0, 0.5, 0.005, 0.0, 0.10, 0.02, 0.0));
if ($slow->paybackYears !== null) {
	throw new RuntimeException('Payback must be null when it is not reached within 20 years.');
}

$invalidRejected = false;
try {
	$calculator->calculate(new LmdbPropalPVFinancialInput(10000.0, 'EUR', 3.0, 3000.0, 1.01, 0.005, 0.0, 0.20, 0.04, 80.0));
} catch (InvalidArgumentException $exception) {
	$invalidRejected = true;
}
if (!$invalidRejected) {
	throw new RuntimeException('An out-of-range self-consumption value must be rejected.');
}

$invalidInputs = array(
	new LmdbPropalPVFinancialInput(0.0, 'EUR', 3.0, 3000.0, 0.5, 0.005, 0.0, 0.20, 0.04, 80.0),
	new LmdbPropalPVFinancialInput(10000.0, 'EUR', 0.0, 3000.0, 0.5, 0.005, 0.0, 0.20, 0.04, 80.0),
	new LmdbPropalPVFinancialInput(10000.0, 'EUR', 3.0, 0.0, 0.5, 0.005, 0.0, 0.20, 0.04, 80.0),
	new LmdbPropalPVFinancialInput(10000.0, 'EUR', 3.0, 3000.0, 0.5, 1.0, 0.0, 0.20, 0.04, 80.0),
	new LmdbPropalPVFinancialInput(10000.0, 'EUR', 3.0, 3000.0, 0.5, 0.005, -1.0, 0.20, 0.04, 80.0),
	new LmdbPropalPVFinancialInput(10000.0, 'EUR', 3.0, 3000.0, 0.5, 0.005, 0.0, 0.20, -0.01, 80.0),
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

print "All financial calculator tests passed.\n";
