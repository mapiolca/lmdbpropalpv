<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

/** Pure tariff interval rules shared by the resolver and unit tests. */
final class LmdbPropalPVTariffMatcher
{
	/**
	 * Test the official interval convention: open lower bound, closed upper bound.
	 */
	public static function powerRangeMatches(float $peakPowerKwp, ?float $minKwp, ?float $maxKwp): bool
	{
		return ($minKwp === null || $peakPowerKwp > $minKwp)
			&& ($maxKwp === null || $peakPowerKwp <= $maxKwp);
	}
}
