<?php

declare(strict_types=1);

namespace AA\Import;

use AA\IO\SourceRefused;
use AA_Components;
use PhpToken;
use ReflectionClass;
use Throwable;

/**
 * Finds the import sources. Adding one means adding one file - there is no list to edit:
 * - core: src/Import/Source/<Name>Source.php, type "core:<name>";
 * - installation: <customDir>/<Name>Source.php, class <Name>Source in the global namespace
 *   (include/custom/ is not autoloaded), type "custom:<name>". It should extend AbstractSource;
 *   a base shared by several sources belongs in a file called Abstract..., loaded first, never offered.
 *
 * A syntax error, an exception, a second declaration or printed output in an installation file
 * is caught before or while loading it: the file is left out of sources() and listed by
 * rejected() for State Check. Still fatal: a class missing a method of the contract or with an
 * incompatible signature. Nor is it a sandbox - its top-level code (define(), class_alias(),
 * include) runs, and a file closing output buffers it did not open is beyond these guarantees.
 *
 * The installation directory is a parameter, so a page can list it without defining
 * AA_CUSTOM_DIR - that constant also pulls in custom/<dir>/stringexpand.php. It never comes from
 * the request, a definition or a run: its files run as code, trusted like include/ - hence it
 * must lie under include/custom/.
 */
final class Registry
{
    /** @var array<string, string> real path => why it did not load ('' = loaded), once per request */
    private static array $loaded = [];

    /** where installation directories must lie; null = include/custom */
    private static ?string $customRoot = null;

    /** the installation directory read when a caller gives none; null = from AA_CUSTOM_DIR */
    private static ?string $defaultDir = null;

    /**
     * @internal for tests only, which cannot write into include/custom; null = back to include/custom
     * @param ?string $defaultDir read by every sources(), rejected() and create() given no directory
     */
    public static function setCustomRootForTests(?string $root, ?string $defaultDir = null): void
    {
        self::$customRoot = $root;
        self::$defaultDir = ($root === null) ? null : $defaultDir;
    }

    /**
     * @param ?string $customDir null = include/custom/<AA_CUSTOM_DIR>/import, without that constant core only
     * @return array<string, class-string<SourceInterface>> 'core:<name>' | 'custom:<name>' => class
     */
    public static function sources(?string $customDir = null): array
    {
        return self::scan($customDir)['sources'];
    }

    /**
     * Keys and values come from the files, so they are untrusted: the caller escapes them (safe())
     * and shows them to a superadmin only.
     *
     * @return array<string, string> directory, file or class => why it was left out
     */
    public static function rejected(?string $customDir = null): array
    {
        return self::scan($customDir)['rejected'];
    }

    /** @param array<string, string> $settings */
    public static function create(string $type, array $settings, ?string $customDir = null): SourceInterface
    {
        // the type comes from a stored definition - not repeated in a message that may end up in a page
        $class = self::sources($customDir)[$type] ?? throw new SourceRefused('NO_SOURCE', 'No import source of this type');

        return $class::fromSettings($settings);
    }

    /**
     * Splits found classes into usable sources and the rest, apart from the directory read so it
     * can be tested without a broken class in the tree.
     *
     * @param  array<string, string> $classes type => class
     * @return array{sources: array<string, class-string<SourceInterface>>, rejected: array<string, string>}
     */
    public static function sift(array $classes): array
    {
        $sources = $rejected = [];
        foreach ($classes as $type => $class) {
            try {
                if (interface_exists($class) || trait_exists($class)) {
                    $rejected[$class] = 'an interface or a trait, not a source';
                } elseif (!class_exists($class)) {
                    $rejected[$class] = 'no such class - the file must declare a class of its own name';
                } elseif ((new ReflectionClass($class))->isAbstract()) {
                    $rejected[$class] = 'abstract - a base class belongs in a file called Abstract...';
                } elseif (!is_subclass_of($class, SourceInterface::class)) {
                    $rejected[$class] = 'does not implement ' . SourceInterface::class;
                } else {
                    $sources[$type] = $class;
                }
            } catch (Throwable $e) {
                // autoloading a broken core file
                $rejected[$class] = self::failure($e, '');
            }
        }
        return ['sources' => $sources, 'rejected' => $rejected];
    }

    /** @return array{sources: array<string, class-string<SourceInterface>>, rejected: array<string, string>} */
    private static function scan(?string $customDir): array
    {
        $classes = [];
        foreach (AA_Components::getClassNames('AA\\Import\\Source\\Source') as $type => $class) {
            if (str_ends_with($class, 'Source')) {   // not a README or a helper lying next to the sources
                $classes["core:$type"] = $class;
            }
        }

        $customDir ??= self::$defaultDir ?? (defined('AA_CUSTOM_DIR') ? constant('AA_BASE_PATH') . 'include/custom/' . constant('AA_CUSTOM_DIR') . '/import' : null);
        $failed = [];
        if ($customDir !== null) {
            [$custom, $failed] = self::loadDir($customDir);
            $classes += $custom;
        }

        $sifted = self::sift($classes);
        $sifted['rejected'] = $failed + $sifted['rejected'];
        return $sifted;
    }

    /**
     * Loads the *Source.php files lying directly in the directory.
     *
     * @return array{0: array<string, string>, 1: array<string, string>} type => class, file => why it did not load
     */
    private static function loadDir(string $dir): array
    {
        $classes = $failed = [];
        $real = realpath($dir);
        if ($real === false || !is_dir($real)) {
            return [$classes, $failed];
        }
        $root = realpath(self::$customRoot ?? constant('AA_BASE_PATH') . 'include/custom');
        if ($root === false || !str_starts_with($real, $root . '/')) {
            return [$classes, [$dir => 'directory outside include/custom']];
        }

        $files = array_filter(scandir($real) ?: [], static fn(string $f): bool => str_ends_with($f, 'Source.php'));
        // bases first, so a source extending one finds it declared
        usort($files, static fn(string $a, string $b): int => [!str_starts_with($a, 'Abstract'), $a] <=> [!str_starts_with($b, 'Abstract'), $b]);

        $typeOf = [];   // type => file that took it
        foreach ($files as $file) {
            $class = substr($file, 0, -4);
            $type  = 'custom:' . strtolower(substr($class, 0, -6));
            $base  = str_starts_with($class, 'Abstract');
            if (!$base && isset($typeOf[$type])) {
                $failed[$file] = 'same type as ' . $typeOf[$type];
            } elseif (($why = self::load($real, $file, $class)) !== '') {
                $failed[$file] = $why;
            } elseif (!$base) {
                $classes[$type] = $class;
                $typeOf[$type]  = $file;
            }
        }
        return [$classes, $failed];
    }

    /** @return string why the file was not loaded, '' when it was */
    private static function load(string $dir, string $file, string $class): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*Source$/D', $class)) {
            return 'not a class name - the file must be called <Name>Source.php';
        }
        $path = realpath("$dir/$file");
        if ($path === false || dirname($path) !== $dir || basename($path) !== $file) {
            return 'a link pointing outside the directory or to another file';
        }
        if (!is_file($path)) {
            return 'not a file';
        }
        if (isset(self::$loaded[$path])) {
            return self::$loaded[$path];
        }
        return self::$loaded[$path] = self::requireSource($path, $file, $class);
    }

    private static function requireSource(string $path, string $file, string $class): string
    {
        try {
            $why = self::declaresOnly(PhpToken::tokenize((string) file_get_contents($path), TOKEN_PARSE), $class);
        } catch (Throwable $e) {
            return self::failure($e, $file);   // a syntax error, found without running the file
        }
        if ($why !== '') {
            return $why;
        }
        // declaring a class twice is a fatal error no try can catch
        if (class_exists($class) || interface_exists($class) || trait_exists($class)) {
            return "the name $class is already taken";
        }

        $level = ob_get_level();
        ob_start();
        try {
            require_once $path;
        } catch (Throwable $e) {
            return self::failure($e, $file);
        } finally {
            $output = '';
            while (ob_get_level() > $level) {
                $output .= ob_get_clean();
            }
        }
        if ($output !== '') {
            return 'prints output (a BOM or text outside <?php)';   // it would break headers and JSON answers
        }
        return class_exists($class, false) ? '' : "does not declare the class $class";
    }

    /**
     * The file may hold exactly one top-level declaration: class $class in the global namespace.
     * Anything else declared would either clash with an existing name (an uncatchable fatal
     * error) or slip in under a name nobody checked - including a core class via namespace.
     *
     * @param list<PhpToken> $tokens
     * @return string why not, '' when it holds
     */
    private static function declaresOnly(array $tokens, string $class): string
    {
        $tokens   = array_values(array_filter($tokens, static fn(PhpToken $t): bool => !$t->isIgnorable()));
        $scopes   = [];      // open braces: true = a class body
        $pending  = [];      // paren depths of class headers whose body has not opened yet
        $parens   = 0;
        $import   = false;   // inside a top-level use statement
        $declared = 0;
        foreach ($tokens as $i => $t) {
            $next = $tokens[$i + 1] ?? null;
            if ($t->is(T_NAMESPACE)) {
                return 'declares a namespace - installation sources live in the global one';
            }
            if ($t->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM])) {   // with TOKEN_PARSE, ::class is a T_STRING
                // the body is the first brace at this paren depth - new class(...) arguments may hold braces too
                $pending[] = $parens;
                if ($next?->is(T_STRING)) {   // a named declaration, not new class
                    if ($scopes !== [] || !$t->is(T_CLASS) || $next->text !== $class || ++$declared > 1) {
                        return "declares {$next->text} besides the class $class";
                    }
                }
            } elseif ($t->is(T_USE) && $scopes === []) {
                $import = true;   // use function x; imports, declares nothing - a closure's use ends at its '{'
            } elseif ($t->text === ';' || $t->is(T_CLOSE_TAG)) {   // a closing tag ends a statement too
                $import = false;
            } elseif ($t->is(T_FUNCTION) && !$import) {
                $name = ($next?->text === '&') ? ($tokens[$i + 2] ?? null) : $next;
                if ($name?->is(T_STRING) && end($scopes) !== true) {   // a named function outside a class body
                    return "declares the function {$name->text}";
                }
            } elseif ($t->id === ord('(')) {
                ++$parens;
            } elseif ($t->id === ord(')')) {
                --$parens;
            } elseif ($t->id === ord('{')) {
                $import   = false;
                $body     = $pending !== [] && end($pending) === $parens;
                $scopes[] = $body;
                if ($body) {
                    array_pop($pending);
                }
            } elseif ($t->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $scopes[] = false;   // "{$x}" in a string
            } elseif ($t->id === ord('}')) {
                array_pop($scopes);
            }
        }
        return $declared === 1 ? '' : "does not declare the class $class";
    }

    /** the exception's message may quote the file or a key, so only its kind and place */
    private static function failure(Throwable $e, string $file): string
    {
        return 'does not load: ' . get_class($e) . ' at ' . (basename($e->getFile()) ?: $file) . ':' . $e->getLine();
    }
}
