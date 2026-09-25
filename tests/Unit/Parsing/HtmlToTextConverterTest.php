<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Parsing;

use ADCT\ParishIntake\Core\Support\HtmlToTextConverter;
use PHPUnit\Framework\TestCase;

final class HtmlToTextConverterTest extends TestCase
{
    public function testPreservesBlocksListsAndTableRowsAndRemovesActiveContent(): void
    {
        $html = <<<'HTML'
<!doctype html>
<html>
<head><style>.hidden { display: none; }</style><script>secretScript()</script></head>
<body>
<h1>Notice &amp; update</h1>
<p>Join&nbsp;us for the community day.</p>
<ul><li>Morning prayer</li><li>Shared lunch</li></ul>
<table>
    <tr><th>Date</th><th>Activity</th></tr>
    <tr><td>17 October 2026</td><td>Community Day</td></tr>
</table>
</body>
</html>
HTML;

        $text = (new HtmlToTextConverter())->convert($html);

        self::assertStringContainsString('Notice & update', $text);
        self::assertStringContainsString('Join us for the community day.', $text);
        self::assertStringContainsString("Notice & update\nJoin us for the community day.", $text);
        self::assertStringContainsString('- Morning prayer', $text);
        self::assertStringContainsString('- Shared lunch', $text);
        self::assertStringContainsString("- Morning prayer\n- Shared lunch", $text);
        self::assertStringContainsString("Date | Activity\n17 October 2026 | Community Day", $text);
        self::assertStringNotContainsString('secretScript', $text);
        self::assertStringNotContainsString('display: none', $text);
    }
}
