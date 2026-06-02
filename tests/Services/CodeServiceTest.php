<?php
declare(strict_types=1);

namespace Tests\Services;

use App\Services\CodeService;
use Tests\TestCase;

class CodeServiceTest extends TestCase
{
    public function testGenerateReturnsCorrectFormat(): void
    {
        $code = CodeService::generate();
        $this->assertMatchesRegularExpression('/^FIT-[A-Z0-9]{4}-[A-Z0-9]{2}$/', $code);
    }

    public function testGenerateProducesUniqueCodes(): void
    {
        $codes = [];
        for ($i = 0; $i < 100; $i++) {
            $codes[] = CodeService::generate();
        }
        $this->assertCount(100, array_unique($codes));
    }

    public function testHashAndVerify(): void
    {
        $code = CodeService::generate();
        $hash = CodeService::hash($code);
        $this->assertTrue(CodeService::verify($code, $hash));
        $this->assertFalse(CodeService::verify('WRONG-CODE', $hash));
    }

    public function testLookupHashIsDeterministic(): void
    {
        $this->assertSame(
            CodeService::lookupHash('FIT-ABCD-12'),
            CodeService::lookupHash('fit-abcd-12')
        );
    }

    public function testLookupHashIsSha256(): void
    {
        $code = 'FIT-TEST-01';
        $this->assertSame(hash('sha256', strtoupper($code)), CodeService::lookupHash($code));
    }
}
