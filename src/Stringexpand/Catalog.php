<?php

declare(strict_types=1);

namespace AA\Stringexpand;

use AA\Util\Txt;
use AA_Components;
use ReflectionClass;

/**
 * The {command:...} expressions, for the code editor's completion and parameter hints - read from
 * the AA_Stringexpand_* classes themselves, so a new command shows up without being listed anywhere.
 *
 * Parameter names come from expand(). A command taking any number of parameters names them in its
 * const PARAMS: ['etalon', ['option', 'text'], 'else_text'] - the inner list repeats.
 */
final class Catalog
{
    /** the files declaring the commands, relative to the AA directory */
    public const SOURCES = ['include/stringexpand.php', 'modules/polls/include/stringexpand.php', 'modules/site/router.class.php'];

    /** a tooltip line, not a manual */
    private const DESC_MAX = 160;

    /**
     * @return array<string, array{params: list<string>, repeat?: list<string>, tail?: list<string>, desc: string, deprecated?: true}>
     *         by command name; repeat - the group repeated after params, tail - what follows it
     */
    public static function commands(): array
    {
        $files = [];
        foreach (self::SOURCES as $source) {
            $file = dirname(__DIR__, 2) . '/' . $source;
            require_once $file;
            $files[] = realpath($file);
        }
        $ret = [];
        foreach (AA_Components::getClassNames('AA_Stringexpand_') as $command => $name) {
            $class = new ReflectionClass($name);
            // only the shipped ones - not a stand-in a test or a custom file declared; a class without
            // its own expand() is a base the commands extend (Nevercache), not a command
            if (in_array(realpath((string) $class->getFileName()), $files, true) && ($class->getMethod('expand')->class !== 'AA_Stringexpand')) {
                $ret[$command] = self::command($class);
            }
        }
        ksort($ret);
        return $ret;
    }

    /**
     * @param ReflectionClass<object> $class
     * @return array{params: list<string>, repeat?: list<string>, tail?: list<string>, desc: string, deprecated?: true}
     */
    private static function command(ReflectionClass $class): array
    {
        $ret = ['params' => []];
        $at  = 'params';
        foreach ($class->hasConstant('PARAMS') ? $class->getConstant('PARAMS') : self::expandParams($class) as $param) {
            if (is_array($param)) {
                $ret['repeat'] = $param;
                $at = 'tail';
            } else {
                $ret[$at][] = $param;
            }
        }
        $doc = (string) $class->getDocComment();
        $ret['desc'] = self::firstSentence($doc);
        if (str_contains($doc . $class->getMethod('expand')->getDocComment(), '@deprecated')) {
            $ret['deprecated'] = true;
        }
        return $ret;
    }

    /**
     * @param ReflectionClass<object> $class
     * @return list<string|list<string>> a variadic parameter repeats
     */
    private static function expandParams(ReflectionClass $class): array
    {
        $ret = [];
        foreach ($class->getMethod('expand')->getParameters() as $param) {
            $ret[] = $param->isVariadic() ? [$param->getName()] : $param->getName();
        }
        return $ret;
    }

    /**
     * The first sentence of the docs. The docblocks are free-form: examples ({command:...} lines),
     * labels ("Usage:") and "Class AA_..." are skipped, and what introduces examples (a line ending
     * with ":") is no description - better none than a fragment.
     */
    private static function firstSentence(string $doc): string
    {
        $text     = '';
        $examples = false;
        foreach (preg_split('/\R/', $doc) ?: [] as $line) {
            $line = trim((string) preg_replace(['~^\s*/?\*+/?~', '~\*/\s*$~', '~^\s*Class AA_\w+~', '~^\s*(usage( as)?|use as|example)\s*:~i'], '', $line));
            if (str_starts_with($line, '@')) {
                break;
            }
            if (str_starts_with($line, '{')) {
                if ($text !== '') {
                    break;
                }
                // only the first one describes the command, the next ones are examples
                $line     = $examples ? '' : self::afterSyntax($line);
                $examples = true;
            }
            if ($line === '') {
                if ($text !== '') {
                    break;
                }
                continue;
            }
            // a new line starting a new sentence - most docblocks do not end one with a period
            if (str_ends_with($line, ':') || (($text !== '') && preg_match('/^\p{Lu}/u', $line) && !str_ends_with($text, ','))) {
                break;
            }
            $text .= ' ' . $line;
        }
        $text = trim((string) preg_replace('/\s+/', ' ', $text));
        if (preg_match('/^.*?\.(?=\s|$)/u', $text, $m)) {
            $text = $m[0];
        }
        return Txt::shorten($text, self::DESC_MAX);
    }

    /** "{xseo:1} - what it does" gives "what it does"; an example without one gives '' */
    private static function afterSyntax(string $line): string
    {
        $depth = 0;
        for ($i = 0, $n = strlen($line); $i < $n; ++$i) {
            $depth += ['{' => 1, '}' => -1][$line[$i]] ?? 0;
            if ($depth === 0) {
                return preg_match('/^\s+-\s+(.+)/', substr($line, $i + 1), $m) ? $m[1] : '';
            }
        }
        return '';
    }
}
