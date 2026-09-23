<?php

declare(strict_types=1);

namespace Tests\Unit\Central;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Central queues AA_Task_Sync / AA_Task_Import_Module_Chunk into toexecute as serialized objects,
 * and the queue runs from cron (misc/toexecute.php), which never requires
 * central/include/actionapps.class.php. The classes must therefore come from the composer
 * classmap - otherwise they unserialize as __PHP_Incomplete_Class and the task is dropped.
 */
final class AutoloadTest extends TestCase
{
    private const CENTRAL_FILE = 'central/include/actionapps.class.php';

    /** @return array<string, array{string}> */
    public static function centralClasses(): array
    {
        return [
            'AA_Task_Sync'                => ['AA_Task_Sync'],
            'AA_Task_Import_Module_Chunk' => ['AA_Task_Import_Module_Chunk'],
            'AA_Definition_Module_Chunk'  => ['AA_Definition_Module_Chunk'],
            'AA_Definition_Module_Slice'  => ['AA_Definition_Module_Slice'],
            'AA_Sync_Action'              => ['AA_Sync_Action'],
            'AA_Identifier'               => ['AA_Identifier'],
            'AA_Actionapps'               => ['AA_Actionapps'],
        ];
    }

    #[DataProvider('centralClasses')]
    public function test_composer_classmap_maps_central_class(string $class): void
    {
        $root     = dirname(__DIR__, 3);
        $classmap = require $root . '/vendor/composer/autoload_classmap.php';

        $this->assertArrayHasKey($class, $classmap, "$class is not in the composer classmap");
        $this->assertSame(realpath($root . '/' . self::CENTRAL_FILE), realpath($classmap[$class]));
    }

    /** What Toexecute stores: a queued import task, built by hand so that nothing loads the class first. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_queued_import_task_unserializes_to_real_class_without_require(): void
    {
        $this->assertFalse(class_exists('AA_Task_Import_Module_Chunk', false), 'the class must not be loaded yet');

        $chunk = self::object('AA_Definition_Module_Chunk', [
            self::prop('module_id') . self::str('0123456789abcdef0123456789abcdef'),
            self::prop('data') . 'a:0:{}',
        ]);
        $task  = self::object('AA_Task_Import_Module_Chunk', [
            self::prop('module_definition_chunk') . $chunk,
            self::prop('actionapps') . self::object('AA_Actionapps', []),
        ]);

        $object = unserialize($task);

        $this->assertNotInstanceOf(\__PHP_Incomplete_Class::class, $object);
        $this->assertInstanceOf(\AA_Task_Import_Module_Chunk::class, $object);
        $this->assertInstanceOf(\AA\Later\LaterInterface::class, $object);
    }

    private static function str(string $s): string
    {
        return 's:' . strlen($s) . ':"' . $s . '";';
    }

    /** serialized name of a protected property */
    private static function prop(string $name): string
    {
        return self::str("\0*\0" . $name);
    }

    /** @param string[] $props serialized name+value pairs */
    private static function object(string $class, array $props): string
    {
        return 'O:' . strlen($class) . ':"' . $class . '":' . count($props) . ':{' . implode('', $props) . '}';
    }
}
