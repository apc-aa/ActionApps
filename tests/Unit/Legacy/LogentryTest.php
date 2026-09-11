<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA_Logentry;
use AA_Logentry_Csn;
use AA_Logentry_Mailid;
use AA_Logentry_Ms;
use AA_Logentry_X;
use AA_Logentry_Obj;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the two log entries added with MAIL_SEND: the AA_Logentry_Ms
 * params row, and the AA_Logentry_Obj selector shared with AA\\Later\\Toexecute.
 *
 * The `resp` property carries whatever the SMTP server replied, which is
 * unbounded, while `log.params` is `varbinary(128)` on a server running
 * STRICT_TRANS_TABLES -- an over-long value fails the INSERT rather than being
 * truncated, and that INSERT sits on the mail-sending path. AA_Logentry_Ms::make()
 * must therefore always hand AA_Log::write() a serialized form that fits.
 *
 * The classmap autoloads include/logs.php; the util.php helpers it needs come
 * from tests/Unit/fixtures/util_shims.php.
 */
final class LogentryTest extends TestCase
{
    /** mirrors the `log.params` column width in include/metabase_schema.php */
    private const PARAMS_MAX = 128;

    public static function setUpBeforeClass(): void
    {
        // util.php cannot load here (it pulls the whole locsess boot); the helpers
        // AA_Logentry::serializeToString() needs come from a global-namespace fixture -
        // a shim declared inside this namespaced class would land in Tests\Unit\Legacy
        // and never satisfy the global call in include/statestore.php
        require_once __DIR__ . '/../fixtures/util_shims.php';
        class_exists(AA_Logentry_Ms::class);
    }

    public function test_typical_smtp_reply_is_stored_whole(): void
    {
        $resp  = '250 2.0.0 Ok: queued as 4c1TxQ2mZ3z9QGxyz';
        $entry = AA_Logentry_Ms::make('smtp', 1, $resp);

        $this->assertSame($resp, $entry->resp, 'a normal reply must survive untrimmed');
        $this->assertSame('smtp', $entry->transport);
        $this->assertSame('1', $entry->sent, 'stored as string - it goes to a varbinary column');
        $this->assertLessThanOrEqual(self::PARAMS_MAX, strlen($entry->serializeToString()));
    }

    public function test_serialized_form_carries_the_class_flag_first(): void
    {
        $entry = AA_Logentry_Ms::make('mail', 1, 'accepted for delivery');

        $this->assertSame('Ms:mail:1:accepted for delivery::', $entry->serializeToString());
    }

    public function test_long_reply_is_trimmed_to_fit_the_params_column(): void
    {
        $entry = AA_Logentry_Ms::make('smtp', 0, str_repeat('x', 400));

        $this->assertLessThanOrEqual(self::PARAMS_MAX, strlen($entry->serializeToString()));
        $this->assertStringStartsWith('xxx', $entry->resp, 'the head of the reply is what we keep');
    }

    /** Every ':' costs two bytes once ParamImplode escapes it to '#:'. */
    public function test_colon_heavy_reply_is_trimmed_on_the_serialized_length(): void
    {
        $entry = AA_Logentry_Ms::make('smtp', 0, str_repeat(':', 400));

        $this->assertLessThanOrEqual(self::PARAMS_MAX, strlen($entry->serializeToString()));
    }

    public function test_empty_reply_terminates_the_trim_loop(): void
    {
        $entry = AA_Logentry_Ms::make('smtp', 0, '');

        $this->assertSame('Ms:smtp:0:::', $entry->serializeToString());
    }

    // --- AA_Logentry_Ms: what the mail actually was -------------------------

    public function test_recipients_and_subject_are_stored_after_the_reply(): void
    {
        $entry = AA_Logentry_Ms::make('mail', 3, 'accepted for delivery', 'adela@zdravamesta.cz+2', 'Nový příspěvek');

        $this->assertSame(
            'Ms:mail:3:accepted for delivery:adela@zdravamesta.cz+2:Nový příspěvek',
            $entry->serializeToString(),
        );
        $this->assertLessThanOrEqual(self::PARAMS_MAX, strlen($entry->serializeToString()));
    }

    /** The serialization is positional and unnamed, so the two new properties
     *  had to go after `resp`: a row written before they existed must still
     *  decode its server reply as the server reply. */
    public function test_a_row_written_before_recipients_existed_still_decodes(): void
    {
        $back = AA_Logentry::factoryByString('Ms:smtp:1:250 2.0.0 67VCcxIC4029086 Message accepted');

        $this->assertInstanceOf(AA_Logentry_Ms::class, $back);
        $this->assertSame('smtp', $back->transport);
        $this->assertSame('250 2.0.0 67VCcxIC4029086 Message accepted', $back->resp);
        $this->assertSame('', $back->to);
        $this->assertSame('', $back->subj);
    }

    /** All three unbounded properties maxed out at once - the row must still fit,
     *  and the SMTP status code plus queue id (the head of `resp`) must survive. */
    public function test_everything_oversized_still_fits_and_keeps_the_queue_id(): void
    {
        $entry = AA_Logentry_Ms::make(
            'smtp',
            1,
            '250 2.0.0 67VCcxIC4029086 Message accepted for delivery, thank you',
            str_repeat('very.long.address@example.org,', 5),
            str_repeat('a very long subject line ', 10),
        );

        $this->assertLessThanOrEqual(self::PARAMS_MAX, strlen($entry->serializeToString()));
        $this->assertStringStartsWith('250 2.0.0 67VCcxIC4029086', $entry->resp);
        $this->assertStringStartsWith('very.long.address@example.org', $entry->to);
        $this->assertNotSame('', $entry->subj, 'the subject is shortened, not dropped');
    }

    /** The server's reply is the one value nothing else in the row substitutes
     *  for - the queue id in it is how the mail is found in the MTA log - so a
     *  long subject must never cost us any of it. */
    public function test_a_successful_reply_survives_whole_beside_recipient_and_subject(): void
    {
        $resp  = '250 2.0.0 67VCcxIC4029086 Message accepted for delivery';
        $entry = AA_Logentry_Ms::make('smtp', 1, $resp, 'adela.petrova@zdravamesta.cz', 'Nový příspěvek v sekci Aktuality');

        $this->assertSame($resp, $entry->resp);
        $this->assertSame('adela.petrova@zdravamesta.cz', $entry->to);
        $this->assertNotSame('', $entry->subj);
        $this->assertLessThanOrEqual(self::PARAMS_MAX, strlen($entry->serializeToString()));
    }

    public function test_subject_yields_before_the_server_reply(): void
    {
        $resp  = str_repeat('R', 64);
        $entry = AA_Logentry_Ms::make('smtp', 1, $resp, 'jan@ecn.cz', str_repeat('S', 44));

        $this->assertSame($resp, $entry->resp, 'trimmed last');
        $this->assertLessThan(44, strlen($entry->subj), 'trimmed first');
        $this->assertLessThanOrEqual(self::PARAMS_MAX, strlen($entry->serializeToString()));
    }

    /** A subject is the only content hint for the many mails built without a
     *  template - it must not be cut mid-character. */
    public function test_multibyte_subject_is_never_split_mid_character(): void
    {
        $entry = AA_Logentry_Ms::make('smtp', 1, str_repeat('x', 44), str_repeat('m', 36), str_repeat('ěščř', 30));

        $this->assertLessThanOrEqual(self::PARAMS_MAX, strlen($entry->serializeToString()));
        $this->assertSame($entry->subj, mb_convert_encoding($entry->subj, 'UTF-8', 'UTF-8'));
    }

    // --- AA_Logentry_Obj: the selector shared with AA\Later\Toexecute -------

    /** mirrors the `log.selector` column width in include/metabase_schema.php */
    private const SELECTOR_MAX = 255;

    public function test_obj_selector_keeps_the_class_as_prefix_and_the_flag_last(): void
    {
        $sel = AA_Logentry_Obj::make('AA_Mail', '29:4fd43c50917b81a8eb86abb22e9d8dde')->serializeToString(true);

        // `selector LIKE 'AA_Mail:%'` must keep working ...
        $this->assertStringStartsWith('AA_Mail:', $sel);
        // ... and factoryByStringReversed() reads the class from the LAST segment.
        $this->assertStringEndsWith(':Obj', $sel);
    }

    public function test_obj_selector_round_trips_an_id_containing_colons(): void
    {
        $id  = '29:4fd43c50917b81a8eb86abb22e9d8dde';
        $sel = AA_Logentry_Obj::make('AA_Mail', $id)->serializeToString(true);

        $back = AA_Logentry::factoryByStringReversed($sel);

        $this->assertInstanceOf(AA_Logentry_Obj::class, $back);
        $this->assertSame('AA_Mail', $back->cls);
        $this->assertSame($id, $back->id, 'the inner colon survives ParamImplode escaping');
    }

    /** The no-object branch of Toexecute::executeTask() passes a whole serialized
     *  task as the id; log.selector is varbinary(255) under STRICT_TRANS_TABLES. */
    public function test_obj_selector_trims_an_oversized_id_but_keeps_the_flag(): void
    {
        $sel = AA_Logentry_Obj::make('no object', str_repeat('O:8:"stdClass"', 200))->serializeToString(true);

        $this->assertLessThanOrEqual(self::SELECTOR_MAX, strlen($sel));
        $this->assertStringEndsWith(':Obj', $sel, 'the class flag must survive the trim');
        $this->assertInstanceOf(AA_Logentry_Obj::class, AA_Logentry::factoryByStringReversed($sel));
    }

    // --- AA_Logentry_Mailid: AA_Mail's composite id, taken apart ------------

    /** `AA_Mail::getId()` is `{email.id}:{item id}`. Logged as one opaque value it
     *  needed ParamImplode to escape the inner colon; as two properties every
     *  value is its own segment and the selector stays escape-free. */
    public function test_mail_selector_keeps_template_and_reader_apart(): void
    {
        $sel = (new AA_Logentry_Mailid([
            'cls' => 'AA_Mail', 'template' => '2', 'reader' => 'c24ef6d6bb4b339d6dafb266c66e66e8',
        ]))->serializeToString(true);

        $this->assertSame('AA_Mail:2:c24ef6d6bb4b339d6dafb266c66e66e8::Mailid', $sel);
        $this->assertStringNotContainsString('#:', $sel, 'no escaping left to do');
    }

    /** The origin is `{script}>{file}:{line}`, so its colon is escaped - and the
     *  class flag has to stay the last segment for factoryByStringReversed(). */
    public function test_mail_selector_round_trips_the_origin(): void
    {
        $sel = (new AA_Logentry_Mailid([
            'cls' => 'AA_Mail', 'template' => '', 'reader' => '', 'origin' => 'index.php>notify.php:77',
        ]))->serializeToString(true);

        $this->assertStringStartsWith('AA_Mail:', $sel, "selector RLIKE 'AA_Mail:...' must keep working");
        $this->assertStringEndsWith(':Mailid', $sel);

        $back = AA_Logentry::factoryByStringReversed($sel);
        $this->assertInstanceOf(AA_Logentry_Mailid::class, $back);
        $this->assertSame('index.php>notify.php:77', $back->origin);
    }

    /** Selectors written before the origin existed. */
    public function test_mail_selector_without_origin_still_decodes(): void
    {
        $back = AA_Logentry::factoryByStringReversed('AA_Mail:31::Mailid');

        $this->assertInstanceOf(AA_Logentry_Mailid::class, $back);
        $this->assertSame('31', $back->template);
        $this->assertSame('', $back->origin);
    }

    public function test_mail_selector_round_trips_into_labelled_parts(): void
    {
        $sel  = (new AA_Logentry_Mailid(['cls' => 'AA_Mail', 'template' => '30', 'reader' => '', 'origin' => 'cron.php>alerts_sending.php:175']))->serializeToString(true);
        $back = AA_Logentry::factoryByStringReversed($sel);

        $this->assertInstanceOf(AA_Logentry_Mailid::class, $back);
        $this->assertSame('AA_Mail', $back->cls);
        $this->assertSame('30', $back->template);
        $this->assertSame('', $back->reader, 'a mail to a bare address has no reader item');
        $this->assertSame('cron.php>alerts_sending.php:175', $back->origin);
    }

    // --- AA_Logentry_Csn: the feed row's `params` -------------------------

    /** `detail` is whatever the remote end sent instead of XML. Stored raw it
     *  blew log.params, and under STRICT_TRANS_TABLES the feed run then died on
     *  the log write instead of recording the feed error. */
    public function test_a_whole_error_page_cannot_overflow_the_column(): void
    {
        $entry = AA_Logentry_Csn::make(
            '12 (https://example.org/rss/very/long/feed/url.xml) -> Zprávy z regionu',
            'Bad data (not XML)',
            "<!DOCTYPE html>\n<html>\n  <head><title>502 Bad Gateway</title></head>\n" . str_repeat('x', 4000),
        );

        $this->assertLessThanOrEqual(self::PARAMS_MAX, strlen($entry->serializeToString()));
        $this->assertSame('Bad data (not XML)', $entry->status, 'the status is never trimmed');
        $this->assertStringStartsWith('12 (https://example.org', $entry->feed);
        $this->assertStringStartsWith('<!DOCTYPE html> <html> <head>', $entry->detail, 'whitespace collapsed to one line');
    }

    public function test_a_short_feed_error_is_stored_whole(): void
    {
        $entry = AA_Logentry_Csn::make('7 (https://example.org/rss) -> News', 'No data returned');

        $this->assertSame('Csn:7 (https#://example.org/rss) -> News:No data returned:', $entry->serializeToString());
    }

    /** The longest shape the real CSN log actually holds (121 of 128 bytes on
     *  geoinformace, 4894 rows) - it fits, so the trim must leave it alone. */
    public function test_the_longest_real_world_row_is_untouched(): void
    {
        $feed  = 'Feed #113 (RSS): katedra-aplikovane-geoinformatiky-a-kartografie  -> geoinformace.cz - články';
        $entry = AA_Logentry_Csn::make($feed, 'No data returned');

        $this->assertSame($feed, $entry->feed, 'a row that fits today must not be shortened');
        $this->assertGreaterThan(110, strlen($entry->serializeToString()), 'and it really is close to the limit');
        $this->assertLessThanOrEqual(self::PARAMS_MAX, strlen($entry->serializeToString()));
    }

    // --- AA_Logentry_X: the queue row's `params` --------------------------

    /** The shape AA_Mail::sendLater() queues: one recipient, wrapped twice
     *  (later() takes all call arguments in one array, send() takes an array of
     *  recipients). Logged raw that read `a:1:{i:0;a:1:{i:0;s:28:"...";}}`. */
    public function test_queue_params_show_the_bare_address(): void
    {
        $entry = AA_Logentry_X::make('2.07', '0.548', '1.03', '1', [['adela.petrova@zdravamesta.cz']]);

        $this->assertSame('adela.petrova@zdravamesta.cz', $entry->params);
        $this->assertLessThanOrEqual(self::PARAMS_MAX, strlen($entry->serializeToString()));
    }

    public function test_queue_params_label_string_keys_and_name_objects(): void
    {
        $entry = AA_Logentry_X::make('0.1', '1', '1', 'ok', ['slice_id' => 42, 'item' => new \stdClass()]);

        $this->assertSame('slice_id=42,item={stdClass}', $entry->params);
    }

    /** Arbitrary task arguments must not be able to fail the INSERT that sits
     *  inside the queue run - the bug this replaced. */
    public function test_oversized_queue_params_cannot_overflow_the_column(): void
    {
        $entry = AA_Logentry_X::make('12.3', '9.99', '12.5', 'done', [range(1, 500), str_repeat('x', 4000)]);

        $this->assertLessThanOrEqual(self::PARAMS_MAX, strlen($entry->serializeToString()));
        $this->assertStringContainsString(',...', $entry->params, 'a long list is elided, not spelled out');
    }

    /** Three array levels are spelled out - the queue's own wrapping is two
     *  (later() args -> send() recipients) - anything deeper is elided. */
    public function test_queue_params_stop_at_the_depth_limit(): void
    {
        $this->assertSame('too,deep', AA_Logentry_X::make('0.1', '1', '1', 'ok', [[['too', 'deep']]])->params);
        $this->assertSame('[...]', AA_Logentry_X::make('0.1', '1', '1', 'ok', [[[['too', 'deep']]]])->params);
    }

    public function test_obj_selector_reads_getid_off_an_object(): void
    {
        $obj = new class {
            public function getId(): string
            {
                return '29:abc';
            }
        };

        $entry = AA_Logentry_Obj::make($obj);

        $this->assertSame('29:abc', $entry->id);
        $this->assertNotSame('', $entry->cls);
    }
}
