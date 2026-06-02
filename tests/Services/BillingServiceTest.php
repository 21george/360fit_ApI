<?php
declare(strict_types=1);

namespace Tests\Services;

use App\Services\BillingService;
use Tests\TestCase;

class BillingServiceTest extends TestCase
{
    public function testDetectCountryFromAddress(): void
    {
        $service = new BillingService();
        $this->assertSame('DE', $service->detectCountry(['country' => 'DE']));
        $this->assertSame('US', $service->detectCountry(['country' => 'US']));
        $this->assertSame('AU', $service->detectCountry(['country' => 'Australia']));
    }

    public function testDetectCountryDefaultsToGermany(): void
    {
        $service = new BillingService();
        $this->assertSame('DE', $service->detectCountry(null));
        $this->assertSame('DE', $service->detectCountry([]));
        $this->assertSame('DE', $service->detectCountry(['country' => '']));
    }

    public function testCalculatePriceForGermany(): void
    {
        $service = new BillingService();
        $price = $service->calculatePrice('DE');

        $this->assertSame(55.00, $price['base']);
        $this->assertSame(0.19, $price['vat_rate']);
        $this->assertSame(10.45, $price['vat_amount']);
        $this->assertSame(65.45, $price['total']);
        $this->assertSame('EUR', $price['currency']);
        $this->assertSame('DE', $price['country']);
    }

    public function testCalculatePriceForUnitedStates(): void
    {
        $service = new BillingService();
        $price = $service->calculatePrice('US');

        $this->assertSame(0.00, $price['vat_rate']);
        $this->assertSame(0.00, $price['vat_amount']);
        $this->assertSame(55.00, $price['total']);
    }

    public function testCalculatePriceForUnknownCountry(): void
    {
        $service = new BillingService();
        $price = $service->calculatePrice('XX');

        $this->assertSame(0.19, $price['vat_rate']);
        $this->assertSame('XX', $price['country']);
    }
}
