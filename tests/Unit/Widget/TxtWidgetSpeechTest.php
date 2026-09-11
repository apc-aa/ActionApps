<?php

declare(strict_types=1);

namespace Tests\Unit\Widget;

use AA\Widget\CodWidget;
use AA\Widget\EdtWidget;
use AA\Widget\TxtWidget;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that the speech-dictation property only takes effect on the plain
 * TxtWidget. Subclasses EdtWidget (CKEditor) and CodWidget (CodeMirror)
 * override the textarea surface, so their integration is explicitly deferred.
 */
final class TxtWidgetSpeechTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Widget autoload cascades into include/constants.php which consumes
        // AA_INSTAL_URL at load time. The minimal test bootstrap does not load
        // config.php, so stub it here. (FLAG_HTML ships inside constants.php
        // itself — we must not pre-define it or we get a "redefined" warning.)
        if (!defined('AA_INSTAL_URL')) {
            define('AA_INSTAL_URL', 'http://localhost/');
        }
    }

    public function test_TxtWidget_exposes_audio_properties(): void
    {
        $props = TxtWidget::getClassProperties();
        $this->assertArrayHasKey('audio_enable', $props);
        $this->assertArrayHasKey('audio_max_seconds', $props);
    }

    public function test_EdtWidget_does_not_expose_audio_properties(): void
    {
        $props = EdtWidget::getClassProperties();
        $this->assertArrayNotHasKey('audio_enable', $props);
        $this->assertArrayNotHasKey('audio_max_seconds', $props);
    }

    public function test_CodWidget_does_not_expose_audio_properties(): void
    {
        $props = CodWidget::getClassProperties();
        $this->assertArrayNotHasKey('audio_enable', $props);
        $this->assertArrayNotHasKey('audio_max_seconds', $props);
    }

    public function test_TxtWidget_speechEnabled_respects_audio_enable_property(): void
    {
        $enabled  = new TxtWidget(['audio_enable' => '1']);
        $disabled = new TxtWidget(['audio_enable' => '0']);
        $default  = new TxtWidget();

        $this->assertTrue($this->invokeSpeechEnabled($enabled));
        $this->assertFalse($this->invokeSpeechEnabled($disabled));
        $this->assertFalse($this->invokeSpeechEnabled($default));
    }

    /**
     * Critical guard: even if someone wires audio_enable=1 onto an EdtWidget
     * or CodWidget instance, speechEnabled() MUST return false so no mic
     * markup leaks onto CKEditor/CodeMirror surfaces. This is the belt that
     * complements the braces of not declaring the property on subclasses.
     */
    public function test_EdtWidget_suppresses_speech_even_when_property_set(): void
    {
        $widget = new EdtWidget(['audio_enable' => '1']);
        $this->assertFalse($this->invokeSpeechEnabled($widget));
    }

    public function test_CodWidget_suppresses_speech_even_when_property_set(): void
    {
        $widget = new CodWidget(['audio_enable' => '1']);
        $this->assertFalse($this->invokeSpeechEnabled($widget));
    }

    private function invokeSpeechEnabled(TxtWidget $widget): bool
    {
        $ref = new \ReflectionMethod(TxtWidget::class, 'speechEnabled');
        $ref->setAccessible(true);
        return (bool) $ref->invoke($widget);
    }
}
