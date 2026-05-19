<?php
declare(strict_types=1);

namespace App\Services;

class BillingService
{
    private const PRICES = [
        'default' => ['base' => 55.00, 'currency' => 'EUR'],
    ];

    private const VAT_RATES = [
        'DE' => 0.19,
        'AT' => 0.20,
        'FR' => 0.20,
        'IT' => 0.22,
        'ES' => 0.21,
        'NL' => 0.21,
        'BE' => 0.21,
        'PL' => 0.23,
        'GB' => 0.20,
        'US' => 0.00,
        'AU' => 0.10,
        'default' => 0.19,
    ];

    public function detectCountry(?array $billingAddress): string
    {
        if (!empty($billingAddress['country'])) {
            return strtoupper(substr((string)$billingAddress['country'], 0, 2));
        }
        return 'DE';
    }

    public function calculatePrice(string $country): array
    {
        $price   = self::PRICES['default'];
        $vatRate = self::VAT_RATES[$country] ?? self::VAT_RATES['default'];
        $base    = $price['base'];
        $vat     = round($base * $vatRate, 2);
        $total   = round($base + $vat, 2);

        return [
            'base'      => $base,
            'vat_rate'  => $vatRate,
            'vat_amount'=> $vat,
            'total'     => $total,
            'currency'  => $price['currency'],
            'country'   => $country,
        ];
    }
}
