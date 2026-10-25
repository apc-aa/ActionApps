<?php

declare(strict_types=1);

/**
 * {datovkabulk} in a template: the gate gets the UI unexpanded only when the template defers it with
 * {(...)} - an inner {...} expands before the command that holds it, {if:...} included.
 */

namespace {
    foreach ([
        'AA_INSTAL_URL'   => 'http://localhost/aa/',
        'AA_INSTAL_PATH'  => '/aa/',
        'IMG_UPLOAD_PATH' => sys_get_temp_dir() . '/',
        'AA_BASE_PATH'    => dirname(__DIR__, 3) . '/',
        'PERM_LIB'        => 'sql',
        'DB_HOST'         => 'localhost',
        'DB_NAME'         => 'aa_test',
        'DB_USER'         => 'aa',
        'DB_PASSWORD'     => '',
    ] as $k => $v) {
        if (!defined($k)) {
            define($k, $v);
        }
    }

    /** {datovkabulkprobe} - counts its expansions, as a {view} of the slices would read them */
    function stringexpand_datovkabulkprobe(): string
    {
        Tests\Unit\Legacy\StringexpandDatovkabulkTest::$probed++;
        return 'probed';
    }
}

namespace Tests\Unit\Legacy {

    use AA;
    use AA\Isds\Gate;
    use AA_Stringexpand_Datovkabulk;
    use PHPUnit\Framework\TestCase;

    final class StringexpandDatovkabulkTest extends TestCase
    {
        public static int $probed = 0;

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../fixtures/util_shims.php';
            require_once __DIR__ . '/fixtures/store_fixtures.php';
        }

        protected function setUp(): void
        {
            self::$probed = 0;
        }

        // no site renders its page here - as in view.php, slice.php or cron
        public function test_page_outsideASitePage_refusesAndNeverExpandsTheDeferredUi(): void
        {
            self::assertSame('no right', AA::Un('{datovkabulk:page:{"campaign_slice":"x"}:{(<b>{datovkabulkprobe}</b>)}:no right}'));
            self::assertSame(0, self::$probed);
        }

        // why the UI must be deferred: the gate cannot stop what expanded before it ran
        public function test_page_undeferredUiExpandsFirst(): void
        {
            self::assertSame('no right', AA::Un('{datovkabulk:page:{"campaign_slice":"x"}:<b>{datovkabulkprobe}</b>:no right}'));
            self::assertSame(1, self::$probed);
        }

        public function test_page_openGateExpandsTheUiInTheTemplate(): void
        {
            $command = new class ([]) extends AA_Stringexpand_Datovkabulk {
                protected function gate(): Gate
                {
                    return new class extends Gate {
                        public function page(string $settings, string $ui, string $refusal, callable $expand): string
                        {
                            return $expand($ui);
                        }
                    };
                }
            };

            self::assertSame('<b>probed</b>', $command->expand('page', '{}', '<b>{datovkabulkprobe}</b>', 'no right'));
            self::assertSame(1, self::$probed);
        }

        public function test_subcommands_outsideTheUiGiveNothing(): void
        {
            self::assertSame('', AA::Un('{datovkabulk:env}{datovkabulk:sender}{datovkabulk:testboxes}{datovkabulk:result}{datovkabulk:fields:send}{datovkabulk:recipients:ca00000000000000000000000000000b:count}{datovkabulk:progress:ca00000000000000000000000000000b:state}{datovkabulk:delete}{datovkabulk}'));
        }

        public function test_recipients_campaignAndQuestionToTheGate(): void
        {
            $command = new class ([]) extends AA_Stringexpand_Datovkabulk {
                protected function gate(): Gate
                {
                    return new class extends Gate {
                        public function recipients(string $campaign, string $what): string
                        {
                            return "$campaign|$what";
                        }
                    };
                }
            };

            self::assertSame('ca00000000000000000000000000000b|ids', $command->expand('recipients', 'ca00000000000000000000000000000b', 'ids'));
        }

        public function test_result_questionToTheGate(): void
        {
            $command = new class ([]) extends AA_Stringexpand_Datovkabulk {
                protected function gate(): Gate
                {
                    return new class extends Gate {
                        public function result(string $what = 'text'): string
                        {
                            return "result|$what";
                        }
                    };
                }
            };

            self::assertSame(['result|code', 'result|'], [$command->expand('result', 'code'), $command->expand('result')]);
        }

        public function test_testboxes_fromTheGate(): void
        {
            $command = new class ([]) extends AA_Stringexpand_Datovkabulk {
                protected function gate(): Gate
                {
                    return new class extends Gate {
                        public function testBoxes(): string
                        {
                            return 'dj7j6mk-ab12cde';
                        }
                    };
                }
            };

            self::assertSame('dj7j6mk-ab12cde', $command->expand('testboxes'));
        }

        public function test_progress_campaignAndQuestionToTheGate(): void
        {
            $command = new class ([]) extends AA_Stringexpand_Datovkabulk {
                protected function gate(): Gate
                {
                    return new class extends Gate {
                        public function progress(string $campaign, string $what): string
                        {
                            return "$campaign|$what";
                        }
                    };
                }
            };

            self::assertSame('ca00000000000000000000000000000b|queued', $command->expand('progress', 'ca00000000000000000000000000000b', 'queued'));
        }
    }
}
