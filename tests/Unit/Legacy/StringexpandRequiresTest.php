<?php

declare(strict_types=1);

/**
 * AA_Requires::computeRequires() walks the definitions once, top to bottom, and marks the
 * dependencies of a used library only for the entries below it - a dependency defined above
 * its user is silently left out of the page.
 */

namespace Tests\Unit\Legacy;

use AA_Requires;
use PHPUnit\Framework\TestCase;

final class StringexpandRequiresTest extends TestCase
{
    /**
     * Definitions whose dependency sits above them. Each is pulled in anyway another way,
     * so the order does no harm today; a new entry belongs here only with such a reason.
     */
    private const KNOWN_EXCEPTIONS = [
        // an internal entry: only codemirror@5 above pulls it in, and that lists the same files itself
        '_codemirror_core@5' => ['_codemirror_htmlmixed@5', '_codemirror_xml@5', '_codemirror_css@5', '_codemirror_javascript@5', '_codemirror_matchbrackets@5', '_codemirror_matchtags@5', '_codemirror_xml-fold@5', '_codemirror_search@5', '_codemirror_searchcursor@5'],
    ];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
    }

    public function test_systemDefinitions_dependenciesDefinedBelow(): void
    {
        $position = array_flip(array_keys(AA_Requires::systemDefinitions()));
        $misplaced = [];
        foreach (AA_Requires::systemDefinitions() as $code_id => $req) {
            foreach ($req->getDependencies() as $dependency) {
                foreach (explode('|', $dependency) as $alternative) {
                    self::assertArrayHasKey($alternative, $position, "$code_id needs an undefined $alternative");
                    if ($position[$alternative] < $position[$code_id]) {
                        $misplaced[$code_id][] = $alternative;
                    }
                }
            }
        }
        self::assertSame(self::KNOWN_EXCEPTIONS, $misplaced);
    }

    public function test_getHead_isdsBringsRichtext(): void
    {
        $requires = new AA_Requires();
        $requires->addRequire('aa-isds@1');
        $head = @$requires->getHead();   // computeRequires() reads unset keys of its own arrays - a warning per definition
        self::assertStringContainsString('javascript/aa-isds.js', $head);
        self::assertStringContainsString('css/aa-isds.css', $head);
        self::assertStringContainsString('javascript/widgets/aa-richtext.js', $head);
        self::assertStringContainsString('css/aa-system.css', $head);
    }
}
