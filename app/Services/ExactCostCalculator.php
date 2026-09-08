<?php

namespace App\Services;

use App\DTO\PricingDecision;
use OverflowException;

final class ExactCostCalculator
{
    public function reservationMicrounits(
        PricingDecision $pricing,
        int $inputTokens,
        int $outputTokens,
    ): int {
        $inputRate = $pricing->cachedInputCostPerMillionTokens !== null
            && bccomp($pricing->cachedInputCostPerMillionTokens, $pricing->inputCostPerMillionTokens, 8) === 1
                ? $pricing->cachedInputCostPerMillionTokens
                : $pricing->inputCostPerMillionTokens;

        return $this->ceilMicrounits(bcadd(
            $this->rateValue($inputRate, $inputTokens),
            $this->rateValue($pricing->outputCostPerMillionTokens, $outputTokens),
            8,
        ));
    }

    public function actualMicrounits(
        PricingDecision $pricing,
        int $inputTokens,
        int $outputTokens,
        int $cachedInputTokens,
    ): int {
        $cached = min(max(0, $cachedInputTokens), max(0, $inputTokens));
        $uncached = max(0, $inputTokens) - $cached;

        $value = bcadd(
            $this->rateValue($pricing->inputCostPerMillionTokens, $uncached),
            $this->rateValue(
                $pricing->cachedInputCostPerMillionTokens ?? $pricing->inputCostPerMillionTokens,
                $cached,
            ),
            8,
        );
        $value = bcadd(
            $value,
            $this->rateValue($pricing->outputCostPerMillionTokens, max(0, $outputTokens)),
            8,
        );

        return $this->ceilMicrounits($value);
    }

    public function dollarsToMicrounits(string $amount): int
    {
        return $this->boundedInteger(bcmul($amount, '1000000', 6));
    }

    private function rateValue(string $ratePerMillion, int $tokens): string
    {
        if ($tokens <= 0) {
            return '0';
        }

        return bcmul($ratePerMillion, (string) $tokens, 8);
    }

    private function ceilMicrounits(string $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $rounded = $whole;
        if (trim($fraction, '0') !== '') {
            $rounded = bcadd($whole, '1', 0);
        }

        return $this->boundedInteger($rounded);
    }

    private function boundedInteger(string $value): int
    {
        if (bccomp($value, (string) PHP_INT_MAX, 0) === 1
            || bccomp($value, (string) PHP_INT_MIN, 0) === -1) {
            throw new OverflowException('The exact quota amount exceeds supported bounds.');
        }

        return (int) $value;
    }
}
