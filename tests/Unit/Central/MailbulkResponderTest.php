<?php

declare(strict_types=1);

namespace Tests\Unit\Central;

use AA\Mailbulk\Gate;
use AA_Response;
use PHPUnit\Framework\TestCase;

/**
 * AA_Responder_Mailbulk (central/responder.php): the params come from POST only, the right is the Gate's,
 * and an error of the Gate reaches the browser escaped once. responder.php runs a request when loaded,
 * so the class is extracted from the real file and evaluated without its parent.
 */
final class MailbulkResponderTest extends TestCase
{
    private const CLS = 'AA_Test_Responder_Mailbulk';
    private const LIST = 'abcde';
    private const ITEM = 'cd00000000000000000000000000000e';

    /** @var array<string, mixed> */
    private array $post = [];
    private mixed $type = null;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../../include/request.class.php';
        if (class_exists(self::CLS, false)) {
            return;
        }
        $source = (string) file_get_contents(__DIR__ . '/../../../central/responder.php');
        eval(strtr(self::extract($source, 'AA_Responder_Mailbulk'), [
            'class AA_Responder_Mailbulk extends AA_Responder' => 'class ' . self::CLS,
            'InputVars::'                                      => '\AA\Util\InputVars::',
            '?Gate '                                           => '?\AA\Mailbulk\Gate ',
            '): Gate'                                          => '): \AA\Mailbulk\Gate',
            'new Gate()'                                       => 'new \AA\Mailbulk\Gate()',
        ]));
        // the Gate the test gives
        eval('class ' . self::CLS . '_Faked extends ' . self::CLS . ' {
            public static ?\AA\Mailbulk\Gate $fake = null;
            protected function gate(): \AA\Mailbulk\Gate { return self::$fake; }
        }');
    }

    protected function setUp(): void
    {
        $this->post = $_POST;
        $this->type = AA_Response::$Response_type;
    }

    protected function tearDown(): void
    {
        $_POST                      = $this->post;
        AA_Response::$Response_type = $this->type;
    }

    public function test_params_comeFromPostOnly_notFromACookie(): void
    {
        $_POST = ['mb_action' => 'test', 'mb_list' => self::LIST, 'mb_item' => self::ITEM];
        $gate  = new MailbulkFakeGate();

        // $_REQUEST, where a cookie of the site beats POST
        $responder = self::responder(['mb_action' => 'send', 'mb_list' => 'zzzzz', 'mb_item' => 'ff00000000000000000000000000000f'], $gate);
        $responder->run();

        self::assertSame([['act', 'test', self::LIST, self::ITEM]], $gate->calls);
    }

    public function test_params_notStrings_areEmpty(): void
    {
        $_POST = ['mb_action' => ['send'], 'mb_list' => self::LIST];
        $gate  = new MailbulkFakeGate();

        self::responder([], $gate)->run();

        self::assertSame([['act', '', self::LIST, '']], $gate->calls);
    }

    public function test_isPerm_isTheGatesWeakerRight(): void
    {
        $_POST = ['mb_action' => 'stop', 'mb_list' => self::LIST, 'mb_item' => self::ITEM];
        $gate  = new MailbulkFakeGate();

        self::assertTrue(self::responder([], $gate)->isPerm());
        $gate->allowed = false;
        self::assertFalse(self::responder([], $gate)->isPerm());
        self::assertSame([['allowed', self::LIST, self::ITEM, false], ['allowed', self::LIST, self::ITEM, false]], $gate->calls);
    }

    public function test_run_errorText_isEscapedOnceByTheResponse(): void
    {
        $_POST          = ['mb_action' => 'send', 'mb_list' => self::LIST, 'mb_item' => self::ITEM];
        $gate           = new MailbulkFakeGate();
        $gate->answer   = ["The list isn't <b>yours</b>", Gate::ERR_DENIED];
        AA_Response::$Response_type = 'html';

        $this->expectOutputString('Error 9: The list isn&apos;t &lt;b&gt;yours&lt;/b&gt;');
        self::responder([], $gate)->run()->respond();
    }

    /** @param array<string, mixed> $request */
    private static function responder(array $request, Gate $gate): object
    {
        $class        = self::CLS . '_Faked';
        $class::$fake = $gate;
        return new $class($request);
    }

    private static function extract(string $source, string $name): string
    {
        $tokens = token_get_all($source);
        $start  = null;
        $depth  = 0;
        $out    = '';
        foreach ($tokens as $i => $token) {
            if ($start === null) {
                if (is_array($token) && ($token[0] === T_CLASS) && (($tokens[$i + 2][1] ?? '') === $name)) {
                    $start = $i;
                } else {
                    continue;
                }
            }
            $text = is_array($token) ? $token[1] : $token;
            $out .= $text;
            if ($text === '{') {
                $depth++;
            } elseif (($text === '}') && (--$depth === 0)) {
                return $out;
            }
        }
        self::fail("$name not found in central/responder.php");
    }
}

/** A Gate that records what the responder asks it. */
final class MailbulkFakeGate extends Gate
{
    /** @var list<list<string|bool>> */
    public array $calls = [];
    public bool $allowed = true;
    /** @var array{string, int} */
    public array $answer = ['<span data-aa-mailbulk></span>', 0];

    public function __construct() {}

    public function allowed(string $list, string $itemId, bool $toSend = true): bool
    {
        $this->calls[] = ['allowed', $list, $itemId, $toSend];
        return $this->allowed;
    }

    public function act(string $action, string $list, string $itemId): array
    {
        $this->calls[] = ['act', $action, $list, $itemId];
        return $this->answer;
    }
}
