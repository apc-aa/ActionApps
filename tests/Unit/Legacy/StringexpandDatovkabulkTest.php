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

    /** {datovkabulkecho:...} - the arguments as AA splits them */
    function stringexpand_datovkabulkecho(string ...$args): string
    {
        return implode('|', $args);
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
            self::assertSame('', AA::Un('{datovkabulk:env}{datovkabulk:sender}{datovkabulk:testboxes}{datovkabulk:campaign}{datovkabulk:result}{datovkabulk:fields:send}{datovkabulk:recipients:ca00000000000000000000000000000b:count}{datovkabulk:progress:ca00000000000000000000000000000b:state}{datovkabulk:advice:ISDS_REFUSED:run}{datovkabulk:advice:isds:1233}{datovkabulk:view:isds_history}{datovkabulk:view:isds_messages:ca00000000000000000000000000000b}{datovkabulk:campaigns}{datovkabulk:campaigns:count}{datovkabulk:messages:problem}{datovkabulk:messages:test:count}{datovkabulk:param:page}{datovkabulk:param:tab}{datovkabulk:filter::types}{datovkabulk:filter:ca00000000000000000000000000000b:include}{datovkabulk:source:a0000000000000000000000000000001:name}{datovkabulk:search}{datovkabulk:message}{datovkabulk:message:resend}{datovkabulk:events:be00000000000000000000000000000d:{(_#KIND)}}{datovkabulk:files:ca00000000000000000000000000000b}{datovkabulk:files:ca00000000000000000000000000000b:{(_#NAME)}}{datovkabulk:types::{(_#TYPE)}}{datovkabulk:letterhead}{datovkabulk:members}{datovkabulk:url:isds_tab=history}{datovkabulk:param:q}{datovkabulk:delete}{datovkabulk}'));
        }

        // the name, the ids and the settings as written; no ids reach the gate as none
        public function test_view_nameIdsAndSettingsToTheGate(): void
        {
            $command = new class ([]) extends AA_Stringexpand_Datovkabulk {
                protected function gate(): Gate
                {
                    return new class extends Gate {
                        public function view(string $name, string $ids = '', string $settings = ''): string
                        {
                            return "$name|$ids|$settings";
                        }
                    };
                }
            };

            self::assertSame(['isds_messages|ca00000000000000000000000000000b|listlen-50', 'isds_history||'], [$command->expand('view', 'isds_messages', 'ca00000000000000000000000000000b', 'listlen-50'), $command->expand('view', 'isds_history')]);
        }

        public function test_url_pairsToTheGate(): void
        {
            $command = new class ([]) extends AA_Stringexpand_Datovkabulk {
                protected function gate(): Gate
                {
                    return new class extends Gate {
                        public function url(string $pairs): string
                        {
                            return "url|$pairs";
                        }
                    };
                }
            };

            self::assertSame('url|isds_campaign=x&isds_show=ok#isds-msgs', $command->expand('url', 'isds_campaign=x&isds_show=ok#isds-msgs'));
        }

        // the pairs, the fragment and a computed value reach the command as its one argument
        public function test_url_pairsAreOneArgument(): void
        {
            self::assertSame('isds_campaign=x&isds_page=2#isds-msgs', AA::Un('{datovkabulkecho:isds_campaign=x&isds_page={math:1+1}#isds-msgs}'));
        }

        public function test_lists_questionsToTheGate(): void
        {
            $command = new class ([]) extends AA_Stringexpand_Datovkabulk {
                protected function gate(): Gate
                {
                    return new class extends Gate {
                        public function campaigns(string $what = ''): string
                        {
                            return "campaigns|$what";
                        }

                        public function messages(string $group, string $what = ''): string
                        {
                            return "messages|$group|$what";
                        }

                        public function param(string $name): string
                        {
                            return "param|$name";
                        }
                    };
                }
            };

            self::assertSame(
                ['campaigns|', 'campaigns|count', 'messages|wait|', 'messages|ok|count', 'param|show'],
                [$command->expand('campaigns'), $command->expand('campaigns', 'count'), $command->expand('messages', 'wait'), $command->expand('messages', 'ok', 'count'), $command->expand('param', 'show')],
            );
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

        public function test_campaign_fromTheGate(): void
        {
            $command = new class ([]) extends AA_Stringexpand_Datovkabulk {
                protected function gate(): Gate
                {
                    return new class extends Gate {
                        public function campaign(): string
                        {
                            return 'ca00000000000000000000000000000b';
                        }
                    };
                }
            };

            self::assertSame('ca00000000000000000000000000000b', $command->expand('campaign'));
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

        public function test_shows_questionsToTheGate(): void
        {
            $command = new class ([]) extends AA_Stringexpand_Datovkabulk {
                protected function gate(): Gate
                {
                    return new class extends Gate {
                        public function filter(string $campaign, string $what): string
                        {
                            return "filter|$campaign|$what";
                        }

                        public function source(string $item, string $what): string
                        {
                            return "source|$item|$what";
                        }

                        public function search(): string
                        {
                            return 'search';
                        }

                        public function message(string $what = ''): string
                        {
                            return "message|$what";
                        }

                        public function files(string $campaign, string $row, callable $expand): string
                        {
                            return "files|$campaign|" . $expand(str_replace('_#NAME', 'a.pdf', $row));
                        }

                        public function types(string $campaign, string $row, callable $expand): string
                        {
                            return "types|$campaign|" . $expand(str_replace('_#TYPE', 'mesto', $row));
                        }

                        public function letterhead(): string
                        {
                            return 'letterhead';
                        }

                        public function members(): string
                        {
                            return 'members';
                        }

                        public function events(string $message, string $row, callable $expand): string
                        {
                            return "$message|" . $expand(str_replace('_#AT', '5', $row));
                        }
                    };
                }
            };

            self::assertSame(
                ['filter||types', 'source|a1|reason', 'search', 'message|', 'message|resend', 'letterhead', 'members'],
                [$command->expand('filter', '', 'types'), $command->expand('source', 'a1', 'reason'), $command->expand('search'), $command->expand('message'), $command->expand('message', 'resend'), $command->expand('letterhead'), $command->expand('members')],
            );
            // the row reaches the gate as written and expands in the template after its values are in
            self::assertSame('m1|<i>5probed</i>', $command->expand('events', 'm1', '<i>_#AT{datovkabulkprobe}</i>'));
            self::assertSame('files|c1|<i>a.pdfprobed</i>', $command->expand('files', 'c1', '<i>_#NAME{datovkabulkprobe}</i>'));
            self::assertSame('types||<i>mestoprobed</i>', $command->expand('types', '', '<i>_#TYPE{datovkabulkprobe}</i>'));
            self::assertSame(3, self::$probed);
        }

        // isds:<status> is split by the colon as any parameter: the status comes as the second one
        public function test_advice_codeAndWhenToTheGate(): void
        {
            $command = new class ([]) extends AA_Stringexpand_Datovkabulk {
                protected function gate(): Gate
                {
                    return new class extends Gate {
                        public function advice(string $code, string $when = ''): string
                        {
                            return "$code|$when";
                        }
                    };
                }
            };

            self::assertSame(['ISDS_REFUSED|run', 'isds|1233', 'BUSY|'], [$command->expand('advice', 'ISDS_REFUSED', 'run'), $command->expand('advice', 'isds', '1233'), $command->expand('advice', 'BUSY')]);
        }
    }
}
