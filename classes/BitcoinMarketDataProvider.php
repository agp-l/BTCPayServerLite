<?php

declare(strict_types=1);

namespace BtcPayLite;

interface BitcoinMarketDataProvider
{
    /**
     * @return array{economy:int,standard:int,priority:int}
     */
    public function getRecommendedFees(): array;

    public function getFiatPrice(string $currency): ?float;
}
