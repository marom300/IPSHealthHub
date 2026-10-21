<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PlausibilityTest extends TestCase
{
    public function testActivationZerosAreInvalid(): void
    {
        foreach (['Waist', 'Weight', 'BodyFat', 'LeanMass', 'BoneMass', 'RestingHR', 'KcalIn', 'Steps', 'SleepMinutes', 'ActiveKcal'] as $ident) {
            $this->assertFalse(HHPlausibility::isValid($ident, 0), $ident . ' = 0 is impossible');
            $this->assertFalse(HHPlausibility::isValid($ident, 0.0), $ident . ' = 0.0 is impossible');
        }
    }

    public function testMacrosMayBeZero(): void
    {
        $this->assertTrue(HHPlausibility::isValid('Fiber', 0));
        $this->assertTrue(HHPlausibility::isValid('Sugar', 0.0));
    }

    public function testRealValues(): void
    {
        $this->assertTrue(HHPlausibility::isValid('Waist', 88.5));
        $this->assertTrue(HHPlausibility::isValid('Weight', 70.9));
        $this->assertTrue(HHPlausibility::isValid('BodyFat', 18.7));
        $this->assertTrue(HHPlausibility::isValid('RestingHR', 54));
        $this->assertTrue(HHPlausibility::isValid('KcalIn', 528));
        $this->assertFalse(HHPlausibility::isValid('Waist', 25));
        $this->assertFalse(HHPlausibility::isValid('Weight', 700));
        $this->assertFalse(HHPlausibility::isValid('Weight', null));
        $this->assertFalse(HHPlausibility::isValid('Weight', 'abc'));
        $this->assertTrue(HHPlausibility::isValid('UnknownIdent', 0), 'no rule = valid');
    }

    public function testFilterDays(): void
    {
        $this->assertSame(
            ['2026-10-17' => null, '2026-10-18' => 88.5, '2026-10-19' => null],
            HHPlausibility::filterDays('Waist', ['2026-10-17' => 0.0, '2026-10-18' => 88.5, '2026-10-19' => null])
        );
    }
}
