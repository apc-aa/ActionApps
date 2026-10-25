<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA\Isds\Actions;
use AA\Isds\Advice;
use AA\Isds\IsdsException;
use AA\Isds\Outcome;
use AA\Isds\SendResult;
use AA\Isds\SendRun;
use AA\Isds\SendStart;
use AA\Isds\Snapshot;
use AA\Isds\TestResult;
use AA\Later\BatchRun;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Advice: what a person on the page of a mass sending reads of a code */
final class AdviceTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
    }

    /** @return array<string, array{int, string}> post_max_size in bytes, as the text names it */
    public static function limits(): array
    {
        return [
            'megabytes'     => [8 * 1024 * 1024, '8 MB'],
            'a part of one' => [1536 * 1024, '1.5 MB'],
            'under one'     => [512 * 1024, '512 kB'],
            'a few bytes'   => [100, '1 kB'],
        ];
    }

    #[DataProvider('limits')]
    public function test_text_tooLargeNamesTheLimit(int $bytes, string $limit): void
    {
        self::assertStringContainsString("than the server takes ($limit).", Advice::text(Actions::TOO_LARGE, [$bytes]));
    }

    /** @return array<string, array{string}> what Continue, Stop, Abandon and Verify may answer */
    public static function runCodes(): array
    {
        // literals: a data provider runs before Actions, which reads SendRun, can be loaded
        $codes = ['RESUMED', 'STOPPING', 'ALL_SENT', 'CONFIRM_ABANDON', 'ABANDONED', 'VERIFIED', 'NOT_UNCERTAIN', 'UNKNOWN_MESSAGE', 'LEASE', 'WRONG_STATE', 'BUSY', 'FORBIDDEN', 'UNKNOWN'];

        return array_combine($codes, array_map(static fn(string $code): array => [$code], $codes));
    }

    #[DataProvider('runCodes')]
    public function test_text_everyCodeOfRunActionsHasText(string $code): void
    {
        self::assertNotSame('', Advice::text($code));
        self::assertContains($code, (new \ReflectionClass(Actions::class))->getConstants(), 'a code of Actions');
    }

    /** @return array<string, array{string}> what Copy may answer of its own */
    public static function cloneCodes(): array
    {
        $codes = ['CLONED', 'NONE_STOPPED', 'NOT_RESENDABLE', 'UNKNOWN_MESSAGE', 'WRONG_STATE', 'UNKNOWN', 'INPUT', 'EXPIRED', 'BUSY'];

        return array_combine($codes, array_map(static fn(string $code): array => [$code], $codes));
    }

    #[DataProvider('cloneCodes')]
    public function test_text_everyCodeOfCloneHasText(string $code): void
    {
        self::assertNotSame('', Advice::text($code));
        self::assertContains($code, (new \ReflectionClass(Actions::class))->getConstants(), 'a code of Actions');
    }

    // a message of unknown fate may be sent again only once verified - and verify waits for the lookup
    public function test_text_notResendableTellsToVerifyFirst(): void
    {
        self::assertStringContainsString('verify it once the page offers it', Advice::text(Actions::NOT_RESENDABLE));
    }

    /** @return array<string, array{string}> what Fix box may answer */
    public static function fixCodes(): array
    {
        $codes = ['FIXED', 'NOT_OFFERED', 'NO_REGISTER', 'UNKNOWN_RECIPIENT', 'TEST_ENV', 'FORBIDDEN', 'BUSY'];

        return array_combine($codes, array_map(static fn(string $code): array => [$code], $codes));
    }

    #[DataProvider('fixCodes')]
    public function test_text_everyCodeOfFixBoxHasText(string $code): void
    {
        self::assertNotSame('', Advice::text($code));
        self::assertContains($code, (new \ReflectionClass(Actions::class))->getConstants(), 'a code of Actions');
    }

    public function test_text_unknownIsEmpty(): void
    {
        self::assertSame('', Advice::text('NO_SUCH_CODE'));
    }

    /** @return array<string, array{list<int|string>, string}> the field INPUT names, what its text says */
    public static function inputs(): array
    {
        return [
            'subject'    => [['subject'], 'The subject cannot be saved: it may have at most 255 characters'],
            'to hands'   => [['to_hands'], '"To hands" cannot be saved: it may have at most 30 characters'],
            'body'       => [['body'], 'it is larger than 8 MB'],
            'recipients' => [['recipients'], 'this page does not offer it. Nothing of the draft was saved'],
            'too many'   => [['too_many'], 'at most 5000 of them may be chosen'],
            'too many files' => [['too_many_files'], 'at most 99 attachments beside the letter'],
            'file type'  => [['file_type', 'logo.svg'], 'The file logo.svg cannot be attached: this page takes only files of the types jpg, jpeg, png, gif, pdf, doc, '],
            'file content' => [['file_content', 'plan.png'], 'The file plan.png cannot be attached: its content is not of the type'],
            'file size'  => [['file_size', 'mapa.pdf'], 'The file mapa.pdf is larger than one file may be here ('],
            'all files'  => [['file_size', 'mapa.pdf'], 'All the attachments and the letter together may have at most 19.1 MB'],
            'file empty' => [['file_empty', 'a.pdf'], 'The file a.pdf is empty.'],
            'file lost'  => [['file_lost', 'a.pdf'], 'The file a.pdf did not arrive whole.'],
            'files'      => [['files'], 'A value of the form cannot be taken.'],
            'none'       => [[], 'A value of the form cannot be taken.'],
            'unknown'    => [['isds_token'], 'A value of the form cannot be taken.'],
        ];
    }

    /** @param list<int|string> $params */
    #[DataProvider('inputs')]
    public function test_text_inputNamesTheField(array $params, string $says): void
    {
        self::assertStringContainsString($says, Advice::text(Actions::INPUT, $params));
    }

    /** @return array<string, array{string, ?bool, string}> how the test ended, whether it costs money if it goes, the code of what it cost */
    public static function charges(): array
    {
        return [
            'paid, sent'               => ['sent', true, 'CHARGED'],
            'price unknown, sent'      => ['sent', null, 'MAY_BE_CHARGED'],
            'paid, uncertain'          => ['uncertain', true, 'MAY_BE_CHARGED'],
            'price unknown, uncertain' => ['uncertain', null, 'MAY_BE_CHARGED'],
            'free'                     => ['sent', false, ''],
            'paid, refused by ISDS'    => ['failed', true, ''],
            'unknown, refused by ISDS' => ['failed', null, ''],
        ];
    }

    // a test that cost money says so by a code, which goes across the redirect; one that cost nothing says nothing
    #[DataProvider('charges')]
    public function test_text_whatTheTestCost(string $how, ?bool $charges, string $code): void
    {
        $outcome = Outcome::of(match ($how) {
            'sent'      => new SendResult('1835603', '0000', 'OK'),
            'uncertain' => new \RuntimeException('the answer got lost'),
            default     => new IsdsException('refused', '1214'),
        });

        $charge = TestResult::of($outcome, 'msg', 'dj7j6mk', '4711-T2', $charges, $charges)->charge();

        self::assertSame($code, $charge);
        if ($code !== '') {
            self::assertStringContainsString('paid postal data message', Advice::text($charge));
        }
    }

    /**
     * Every code a page of a mass sending may be told: of the actions, of queueing (SendStart), of a
     * test (TestResult) and of ISDS asked (Outcome) - read from the classes, so a code added there
     * fails here until it has a text
     * @return array<string, string>
     */
    private static function everyCode(): array
    {
        $constants = static fn(string $class): array => (new \ReflectionClass($class))->getConstants(\ReflectionClassConstant::IS_PUBLIC);
        $codes     = [
            ...array_diff_key($constants(Actions::class), ['LOG_EVENT' => 1]),
            ...array_filter($constants(SendStart::class), static fn(string $name): bool => !str_starts_with($name, 'STATUS_'), ARRAY_FILTER_USE_KEY),
            ...array_filter($constants(TestResult::class), static fn(string $name): bool => !str_starts_with($name, 'STATUS_'), ARRAY_FILTER_USE_KEY),
            // the codes of the run, not what it does next
            ...array_diff_key($constants(Outcome::class), array_flip(['NEXT', 'PAUSE', 'FAIL', 'RETHROW'])),
            'REFUSED' => SendRun::REFUSED,
        ];

        return array_combine(array_values($codes), array_values($codes));
    }

    public function test_text_everyCodeOfSendStartTestResultOutcomeHasText(): void
    {
        $codes = self::everyCode();
        self::assertContains('ISDS_LOGIN', $codes);
        self::assertContains('NO_TRANSACTION', $codes);
        self::assertContains('SAME_LETTER', $codes);

        self::assertSame([], array_keys(array_filter($codes, static fn(string $code): bool => Advice::text($code) === '')), 'codes without a text');
    }

    /** @return array<string, array{string, string, string}> the status in the detail of the core, what the text says, what it must not */
    public static function refusedStatuses(): array
    {
        return [
            'known'   => ['1201', 'made inaccessible', 'status 1201'],
            'unknown' => ['1214', 'ISDS answered with status 1214.', 'inaccessible'],
            'none'    => ['', 'whose the login of the account is, nothing was sent.', 'status'],
        ];
    }

    #[DataProvider('refusedStatuses')]
    public function test_text_isdsRefusedMapsStatus(string $status, string $says, string $not): void
    {
        $text = Advice::text(SendRun::REFUSED, [$status]);

        self::assertStringStartsWith('ISDS refused to tell whose the login', $text);
        self::assertStringContainsString($says, $text);
        self::assertStringNotContainsString($not, $text);
    }

    /**
     * Every code a sending may fail or wait with (BatchRun::lastFailure()): the reasons and retries
     * SendRun and Snapshot name, those of ISDS asked (Outcome), and the ones BatchRun and SendRun give
     * by a literal - read from the classes and their sources, so a code added there fails here until
     * it has a text
     * @return array<string, string>
     */
    private static function everyCodeOfARun(): array
    {
        $codes = [];
        foreach ([SendRun::class, Snapshot::class] as $class) {
            foreach ((new \ReflectionClass($class))->getReflectionConstants(\ReflectionClassConstant::IS_PUBLIC) as $constant) {
                // BatchRun's own reasons (FORBIDDEN) refuse a person, not a run
                if (($constant->getDeclaringClass()->getName() === $class) && (preg_match('~^/\*\*\s*(BatchFailed reason|BatchStepResult::\$retry)~', (string) $constant->getDocComment()) === 1)) {
                    $codes[] = (string) $constant->getValue();
                }
            }
        }
        $outcome = (new \ReflectionClass(Outcome::class))->getConstants(\ReflectionClassConstant::IS_PUBLIC);
        $codes   = [...$codes, ...array_values(array_diff_key($outcome, array_flip(['NEXT', 'PAUSE', 'FAIL', 'RETHROW']))), BatchRun::NO_TRANSACTION];
        foreach (['src/Later/BatchRun.php', 'src/Isds/SendRun.php'] as $file) {
            $source = (string) file_get_contents(dirname(__DIR__, 3) . '/' . $file);
            preg_match_all("~retry: '([A-Z][A-Z0-9_]*)'|->retryOrError\([^;\n]*'([A-Z][A-Z0-9_]*)'|\['([A-Z][A-Z0-9_]*)', get_debug_type~", $source, $m);
            $codes = [...$codes, ...array_filter([...$m[1], ...$m[2], ...$m[3]])];
        }

        return array_combine($codes, $codes);
    }

    // a run that waits or stopped on it: what was sent is told by the figures, not by the text
    public function test_ofRun_everyCodeOfARunHasText(): void
    {
        $codes = self::everyCodeOfARun();
        foreach (['ISDS_REFUSED', 'ISDS_WATCH', 'ISDS_LOGIN', 'CONTENT_CHANGED', 'NO_TRANSACTION', 'NO_PROGRESS', 'STEP_DIED', 'FINAL_CHECK', 'INTERNAL'] as $code) {
            self::assertContains($code, $codes, 'read from the classes');
        }
        self::assertNotContains(SendRun::UNCHECKED, $codes, 'a warning, no failure');

        foreach ($codes as $code) {
            $text = Advice::ofRun($code);
            self::assertNotSame('', $text, $code);
            self::assertTrue(Advice::knowsRun($code), $code);
            self::assertStringNotContainsString('nothing was sent', $text, $code);
            self::assertDoesNotMatchRegularExpression('~%\d|/\w|\\\\\w|\.php|datovka_conf|config|password:|heslo|log of~i', $text, $code);
        }
    }

    public function test_ofRun_knowsRunOnlyWhatHasText(): void
    {
        foreach ([...self::everyCode(), ...self::everyCodeOfARun(), 'NO_SUCH_CODE', ''] as $code) {
            self::assertSame(Advice::ofRun($code) !== '', Advice::knowsRun($code), $code);
        }
        self::assertSame(['', ''], [Advice::ofRun('NO_SUCH_CODE'), Advice::ofRun(Actions::SAVED)]);
    }

    // the same code comes of a refused series of messages and of a refused question of the sending
    public function test_ofRun_refusedFitsBothOrigins(): void
    {
        $text = Advice::ofRun(SendRun::REFUSED);

        self::assertStringContainsString('several messages in a row with the same status, or refused a question of the sending', $text);
        self::assertStringContainsString('After refused messages Continue sends one more', $text);
        self::assertStringContainsString('after a refused question Continue asks again', $text);
    }

    // following delivery waits for ISDS without end: it never stops on it
    public function test_ofRun_unavailableFitsAWaitToo(): void
    {
        self::assertStringContainsString('AA tries again by itself; if the sending stopped on it, choose Continue', Advice::ofRun(Outcome::UNAVAILABLE));
        self::assertStringContainsString('choose Continue tomorrow', Advice::ofRun(Outcome::BLOCKED));
    }

    public function test_alone_textThatNeedsNothingMore(): void
    {
        self::assertSame(Advice::text(Actions::SAVED), Advice::alone(Actions::SAVED));
        self::assertStringStartsWith('ISDS refused to tell whose the login', Advice::alone(SendRun::REFUSED));
        self::assertSame('', Advice::alone('NO_SUCH_CODE'));
        self::assertSame('The form sent is larger than the server takes. Nothing was saved - make the attachments smaller.', Advice::alone(Actions::TOO_LARGE));
    }

    /** @return array<string, array{string}> */
    public static function namingAValue(): array
    {
        // literals: a data provider runs before Actions can be loaded
        $codes = ['QUEUED', 'TEST_SENT', 'TEST_UNCERTAIN', 'SAME_LETTER', 'COUNT_CHANGED', 'TOO_MANY', 'CONTENT'];

        return array_combine($codes, array_map(static fn(string $code): array => [$code], $codes));
    }

    #[DataProvider('namingAValue')]
    public function test_alone_noTextThatNamesAValue(string $code): void
    {
        self::assertNotSame('', Advice::text($code, ['41', '4711-T2']));
        self::assertSame('', Advice::alone($code));
    }

    // a code named alone gives its text whole, or nothing - no blank, made-up number or hanging colon
    public function test_alone_neverABlank(): void
    {
        foreach (self::everyCode() as $code) {
            self::assertDoesNotMatchRegularExpression('~%\d|\d+ kB|1970|\(\s*\)|\( |\s[.,)]|:\z~', Advice::alone($code), $code);
        }
    }

    // a test is better made to an authority; a sending to such a box only fails
    public function test_text_1233TestAddsHint(): void
    {
        $hint = 'Test with the data box of an authority.';

        self::assertStringEndsWith($hint, Advice::text(Actions::TEST_FAILED, ['1233']));
        self::assertStringContainsString('only paid postal data messages', Advice::isds('1233'));
        self::assertStringNotContainsString($hint, Advice::isds('1233'));
        self::assertSame('', Advice::isds('9999'));
    }

    public function test_text_testNamesItsIds(): void
    {
        self::assertSame('The test message has gone (ID 1835603 in ISDS, reference number 4711-T2).', Advice::text(Actions::TEST_SENT, ['1835603', '4711-T2']));
        self::assertStringContainsString('reference number 4711-T2', Advice::text(Actions::TEST_UNCERTAIN, ['4711-T2']));
        self::assertStringContainsString('on ' . date('Y-m-d H:i', 1_800_000_000) . ' (reference number 4711-T1)', Advice::text(TestResult::SAME_LETTER, ['4711-T1', 1_800_000_000]));
        self::assertStringNotContainsString(' on ', Advice::text(TestResult::SAME_LETTER, ['4711-T1', 0]), 'when unknown');
        self::assertStringContainsString('until ' . date('Y-m-d H:i', 1_800_003_600), Advice::text(TestResult::MAINTENANCE, [1_800_003_600]));
        self::assertStringContainsString('(300 MB needed)', Advice::text(SendStart::MEMORY, [300]));
        self::assertStringContainsString('you confirmed 41', Advice::text(SendStart::COUNT_CHANGED, [41]));
    }

    // an editor reads it: never where AA keeps the account, nor a password - the params are ids at most
    public function test_text_noPathOrPasswordInAnyText(): void
    {
        foreach (self::everyCode() as $code) {
            foreach ([[], ['1233', 1_800_000_000], [8 * 1024 * 1024, '4711-T2']] as $params) {
                $text = Advice::text($code, $params);
                self::assertDoesNotMatchRegularExpression('~/\w|\\\\\w|\.php|datovka_conf|config|password:|heslo~i', $text, $code);
            }
        }
    }
}
