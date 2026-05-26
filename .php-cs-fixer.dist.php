<?php

/**
 * PHP-CS-Fixer config for ActionApps — PER Coding Style 3.0 on src/ + tests/.
 *
 * Risky rules are OFF: only whitespace/structure changes, never semantic ones
 * (e.g. no declare(strict_types) injection, no behaviour-changing fixers).
 * Legacy include/ is intentionally out of scope (separate modernization track).
 *
 * Run:  composer fix    (apply)   /   composer cs   (check, dry-run)
 */

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PER-CS3.0' => true,
    ])
    ->setFinder($finder);
