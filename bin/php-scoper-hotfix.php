<?php

/**
 * PHP-Scoper PHP 8.4/8.5 compatibility hotfix.
 *
 * Problem
 * -------
 * PHP-Scoper 0.18.x (at least up to 0.18.18) declares a class-level array with
 * null as a key in UseStmtCollection.php:
 *
 *     private array $nodes = [ null => [] ];
 *
 * PHP 8.4 introduced E_DEPRECATED for null array keys; PHP 8.5 formalises it.
 * PHP-Scoper's own error handler (bin/php-scoper line ~34) converts every
 * E_DEPRECATED into an ErrorException. This causes TraverserFactory::create()
 * to throw on every file, which is silently caught, and the original unmodified
 * content is written as fallback. Result: scoping appears to succeed (exit 0,
 * "[OK] Successfully prefixed N files") but no namespace is ever rewritten.
 *
 * Fix
 * ---
 * Replace null with '' (empty string). PHP has always coerced null array keys
 * to "" anyway, so the semantics are identical.
 *
 * When to remove this script
 * --------------------------
 * Once php-scoper is upgraded to a version that no longer has this bug
 * (check: does UseStmtCollection.php still contain "null => []"?),
 * remove this file and the post-install-cmd / post-update-cmd entries
 * from composer.json.
 *
 * Tracked upstream: https://github.com/humbug/php-scoper (no issue # yet —
 * verify before upgrading that the fix is included).
 */

declare(strict_types=1);

$target = __DIR__ . '/../vendor/humbug/php-scoper/src/PhpParser/NodeVisitor/UseStmt/UseStmtCollection.php';
$target = realpath($target);

if ($target === false || !file_exists($target)) {
    echo "[php-scoper-hotfix] File not found — skipping (vendor not installed yet?).\n";
    exit(0);
}

$original = file_get_contents($target);

// The exact string to patch (indented with 8 spaces as in the original file).
$broken  = "    private array \$nodes = [\n        null => [],\n    ];";
$fixed   = "    private array \$nodes = [\n        '' => [],\n    ];";

if (strpos($original, $fixed) !== false) {
    echo "[php-scoper-hotfix] Already applied — no changes made.\n";
    exit(0);
}

if (strpos($original, $broken) === false) {
    echo "[php-scoper-hotfix] Pattern not found — the installed version may have fixed this natively.\n";
    exit(0);
}

$patched = str_replace($broken, $fixed, $original);
file_put_contents($target, $patched);
echo "[php-scoper-hotfix] Applied: null => [] replaced with '' => [] in UseStmtCollection.php\n";
