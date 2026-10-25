<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA\Isds\Actions;
use AA\Isds\Advice;
use AA\Isds\IsdsException;
use AA\Isds\Outcome;
use AA\Isds\SendResult;
use AA\Isds\TestResult;
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

    /** @return array<string, array{string}> what Continue, Stop and Abandon may answer */
    public static function runCodes(): array
    {
        // literals: a data provider runs before Actions, which reads SendRun, can be loaded
        $codes = ['RESUMED', 'STOPPING', 'ALL_SENT', 'CONFIRM_ABANDON', 'ABANDONED', 'LEASE', 'WRONG_STATE', 'BUSY', 'FORBIDDEN', 'UNKNOWN'];

        return array_combine($codes, array_map(static fn(string $code): array => [$code], $codes));
    }

    #[DataProvider('runCodes')]
    public function test_text_everyCodeOfRunActionsHasText(string $code): void
    {
        self::assertNotSame('', Advice::text($code));
        self::assertContains($code, (new \ReflectionClass(Actions::class))->getConstants(), 'a code of Actions');
    }

    public function test_text_unknownIsEmpty(): void
    {
        self::assertSame('', Advice::text('NO_SUCH_CODE'));
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
}
