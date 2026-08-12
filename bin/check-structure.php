<?php

/**
 * Structural guardrails for the Amendor procedural codebase.
 *
 * Usage: php bin/check-structure.php [plugin-dir]
 *
 * Checks:
 *  1. Every function name is defined exactly once (no duplicates).
 *  2. Every direct call to an `amendor_*` function resolves to a definition.
 *  3. Every module under includes/ is required exactly once by the bootstrap.
 *  4. `current_user_can()` may only be called inside
 *     `amendor_current_user_can_manage()` (the central capability helper).
 *
 * Exits 1 when problems are found; intended for local dev and CI.
 */

error_reporting(E_ALL & ~E_DEPRECATED);

$root = isset($argv[1]) ? rtrim($argv[1], '/') : rtrim(__DIR__ . '/..', '/');
$root = realpath($root) ?: $root;

$errors = [];

/** Normalize a token to its display value (single-char tokens are plain strings). */
function amendor_tok_val($t)
{
    return is_array($t) ? $t[1] : $t;
}

// --- Collect PHP files under includes/ --------------------------------------
$files = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/includes', FilesystemIterator::SKIP_DOTS)
);
foreach ($it as $f) {
    if ($f->isFile() && $f->getExtension() === 'php') {
        $files[] = $f->getPathname();
    }
}
sort($files);

if (!$files) {
    fwrite(STDERR, "check-structure: no PHP files found under {$root}/includes\n");
    exit(2);
}

// --- 1. Function definitions: unique names ----------------------------------
$defs = []; // name => "file:line"

foreach ($files as $file) {
    $tokens = token_get_all(file_get_contents($file));
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (!is_array($t) || $t[0] !== T_FUNCTION) {
            continue;
        }
        // Find the function name; anonymous closures have none.
        for ($j = $i + 1; $j < $n; $j++) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT], true)) {
                continue;
            }
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                $name = $tokens[$j][1];
                $rel = str_replace($root . '/', '', $file);
                if (isset($defs[$name])) {
                    $errors[] = "DUPLICATE function {$name}: {$rel}:{$tokens[$j][2]} (already in {$defs[$name]})";
                } else {
                    $defs[$name] = "{$rel}:{$tokens[$j][2]}";
                }
            }
            break;
        }
    }
}

// --- 2. Calls to amendor_* resolve to a definition --------------------------
foreach ($files as $file) {
    $tokens = token_get_all(file_get_contents($file));
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (!is_array($t) || $t[0] !== T_STRING || strpos($t[1], 'amendor_') !== 0) {
            continue;
        }
        $name = $t[1];
        $j = $i + 1;
        while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if ($j >= $n || amendor_tok_val($tokens[$j]) !== '(') {
            continue; // not a direct call (constant, string callback, etc.)
        }
        if (!isset($defs[$name])) {
            $rel = str_replace($root . '/', '', $file);
            $errors[] = "CALL to undefined amendor_* function {$name}: {$rel}:{$t[2]}";
        }
    }
}

// --- 3. Bootstrap requires every module exactly once ------------------------
$required = [];
$bootTokens = token_get_all(file_get_contents($root . '/amendor.php'));
foreach ($bootTokens as $t) {
    if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
        $raw = $t[1];
        if (strpos($raw, 'includes/') !== false && strpos($raw, '.php') !== false) {
            $required[] = basename(trim($raw, "'\""));
        }
    }
}

$actual = array_map('basename', $files);
$counts = array_count_values($required);
foreach ($counts as $mod => $c) {
    if ($c > 1) {
        $errors[] = "Bootstrap requires {$mod} {$c} times (expected once)";
    }
    if (!in_array($mod, $actual, true)) {
        $errors[] = "Bootstrap requires missing module: {$mod}";
    }
}
foreach ($actual as $a) {
    if (!in_array($a, $required, true)) {
        $errors[] = "Module {$a} is never required by the bootstrap";
    }
}

// --- 4. current_user_can() only inside the capability helper ----------------
$helperRanges = []; // [startLine, endLine]
foreach ($files as $file) {
    $tokens = token_get_all(file_get_contents($file));
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (!is_array($t) || $t[0] !== T_STRING || $t[1] !== 'amendor_current_user_can_manage') {
            continue;
        }
        // Only the definition token (preceded by `function`), not call sites.
        $k = $i - 1;
        while ($k >= 0 && is_array($tokens[$k]) && in_array($tokens[$k][0], [T_WHITESPACE, T_COMMENT], true)) {
            $k--;
        }
        if ($k < 0 || !is_array($tokens[$k]) || $tokens[$k][0] !== T_FUNCTION) {
            continue;
        }
        $startLine = $t[2];
        $depth = 0;
        $endLine = 0;
        $curLine = $startLine;
        for ($j = $i; $j < $n; $j++) {
            // Single-char tokens ({, }, ;, ...) carry no line number; track the
            // line from the most recent array token instead.
            if (is_array($tokens[$j])) {
                $curLine = $tokens[$j][2];
            }
            $v = amendor_tok_val($tokens[$j]);
            if ($v === '{') {
                $depth++;
            } elseif ($v === '}') {
                $depth--;
                if ($depth === 0) {
                    $endLine = $curLine;
                    break;
                }
            }
        }
        if ($endLine > 0) {
            $helperRanges[] = [$startLine, $endLine];
        }
    }
}

foreach ($files as $file) {
    $tokens = token_get_all(file_get_contents($file));
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (!is_array($t) || $t[0] !== T_STRING || $t[1] !== 'current_user_can') {
            continue;
        }
        $line = $t[2];
        $inside = false;
        foreach ($helperRanges as $r) {
            if ($line >= $r[0] && $line <= $r[1]) {
                $inside = true;
                break;
            }
        }
        if (!$inside) {
            $rel = str_replace($root . '/', '', $file);
            $errors[] = "DIRECT current_user_can() at {$rel}:{$line} - use amendor_current_user_can_manage()";
        }
    }
}

// --- Report ----------------------------------------------------------------
if ($errors) {
    fwrite(STDERR, 'check-structure: ' . count($errors) . " problem(s) found\n\n");
    foreach ($errors as $e) {
        fwrite(STDERR, "  - {$e}\n");
    }
    exit(1);
}

printf(
    "check-structure OK: %d modules required, %d unique functions, capability helper enforced\n",
    count($actual),
    count($defs)
);
exit(0);
