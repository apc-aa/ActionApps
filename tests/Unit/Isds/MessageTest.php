<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA\Isds\File;
use AA\Isds\Message;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MessageTest extends TestCase
{
    private static function main(string $content = 'x'): File
    {
        return new File('Dopis.html', 'text/html', File::MAIN, $content);
    }

    private static function enclosure(string $content = 'x'): File
    {
        return new File('Příloha.pdf', 'application/pdf', File::ENCLOSURE, $content);
    }

    public function test_construct_normalises(): void
    {
        $message = new Message(" KV62bqf \n", '  Pozvánka  ', [self::main()], ' a1-b2 ', ' starosta ');

        self::assertSame(['kv62bqf', 'Pozvánka', 'a1-b2', 'starosta'], [$message->recipient, $message->annotation, $message->senderRef, $message->toHands]);
        self::assertFalse($message->personalDelivery);
        self::assertNull($message->allowSubstDelivery);
    }

    #[DataProvider('notBoxes')]
    public function test_construct_refusesRecipient(string $box): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Message($box, 'Pozvánka', [self::main()]);
    }

    public static function notBoxes(): array
    {
        return [
            'empty'       => [''],
            'six'         => ['kv62bq'],
            'eight'       => ['kv62bqff'],
            'dash'        => ['kv6-bqf'],
            'inner space' => ['kv6 bqf'],
            'accent'      => ['kv62bqž'],
        ];
    }

    #[DataProvider('badTexts')]
    public function test_construct_refusesText(string $annotation, string $senderRef, string $toHands): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Message('kv62bqf', $annotation, [self::main()], $senderRef, $toHands);
    }

    public static function badTexts(): array
    {
        return [
            'no subject'          => ['', '', ''],
            'blank subject'       => [" \t ", '', ''],
            'long subject'        => [str_repeat('ř', Message::MAX_ANNOTATION + 1), '', ''],
            'subject not utf-8'   => ["Pozv\xE1nka", '', ''],
            'control character'   => ["Pozvánka\x07", '', ''],
            'subject with C1'     => ["Pozv\u{85}nka", '', ''],
            'subject with FFFE'   => ["Pozvánka\u{FFFE}", '', ''],
            'long reference'      => ['Pozvánka', str_repeat('1', Message::MAX_REF + 1), ''],
            'reference not ascii' => ['Pozvánka', 'č.j. 1', ''],
            'reference with tab'  => ['Pozvánka', "a\tb", ''],
            'long to hands'       => ['Pozvánka', '', str_repeat('a', Message::MAX_TO_HANDS + 1)],
            'to hands not utf-8'  => ['Pozvánka', '', "starost\xE1"],
            'to hands with nul'   => ['Pozvánka', '', "staro\0sta"],
        ];
    }

    public function test_construct_acceptsLongestTexts(): void
    {
        $message = new Message('kv62bqf', str_repeat('ř', Message::MAX_ANNOTATION), [self::main()], str_repeat('1', Message::MAX_REF), str_repeat('ž', Message::MAX_TO_HANDS));

        self::assertSame(Message::MAX_ANNOTATION, mb_strlen($message->annotation));
    }

    /** @param \Closure(): list<File> $files */
    #[DataProvider('badFiles')]
    public function test_construct_refusesFiles(\Closure $files): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Message('kv62bqf', 'Pozvánka', $files());
    }

    public static function badFiles(): array
    {
        return [
            'none'              => [static fn(): array => []],
            'enclosure first'   => [static fn(): array => [self::enclosure(), self::main()]],
            'only enclosures'   => [static fn(): array => [self::enclosure()]],
            'two main'          => [static fn(): array => [self::main(), self::main()]],
            'over 100 files'    => [static fn(): array => [self::main(), ...array_fill(0, Message::MAX_FILES, self::enclosure())]],
            'over 20 MB'        => [static fn(): array => [self::main(str_repeat('a', Message::MAX_BYTES - 10)), self::enclosure(str_repeat('b', 11))]],
            'over 10 zip'       => [static fn(): array => [self::main(), ...array_fill(0, Message::MAX_CONTAINERS + 1, new File('a.bin', 'application/zip', File::ENCLOSURE, 'x'))]],
            'over 10 asic'      => [static fn(): array => [self::main(), ...array_fill(0, Message::MAX_CONTAINERS + 1, new File('a.asice', 'application/octet-stream', File::ENCLOSURE, 'x'))]],
        ];
    }

    public function test_construct_acceptsLimits(): void
    {
        $files   = [self::main(str_repeat('a', Message::MAX_BYTES - 99)), ...array_fill(0, Message::MAX_FILES - 1, self::enclosure())];
        $message = new Message('kv62bqf', 'Pozvánka', $files);

        self::assertCount(Message::MAX_FILES, $message->files);
        self::assertSame(Message::MAX_BYTES, $message->size());
        self::assertSame(20000000, Message::MAX_BYTES);
    }

    public function test_construct_acceptsTenContainers(): void
    {
        $files = [self::main(), ...array_fill(0, Message::MAX_CONTAINERS, new File('a.zip', 'application/zip', File::ENCLOSURE, 'x'))];

        self::assertCount(Message::MAX_CONTAINERS + 1, (new Message('kv62bqf', 'Pozvánka', $files))->files);
    }

    // the first file is checked as the main one by its position
    public function test_construct_filesAsList(): void
    {
        $message = new Message('kv62bqf', 'Pozvánka', ['dopis' => self::main(), 'priloha' => self::enclosure()]);

        self::assertSame([0, 1], array_keys($message->files));
    }
}
