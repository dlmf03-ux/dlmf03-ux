<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/db.php';

final class AnalyticsHelpersTest extends TestCase
{
    public function testFormattingHelpersHandleEmptyValues(): void
    {
        self::assertSame('0%', pct(4, 0));
        self::assertSame('', mb_fmt(0));
        self::assertSame('1 234', int_fmt(1234));
    }
}
