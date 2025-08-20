<?php

class Env
{
    /**
     * Load environment variables from a .env file if present.
     * Existing environment variables are not overridden.
     */
    public static function load(string $path): void
    {
        error_log("Env::load: attempting to load .env from {$path}");
        if (!is_file($path) || !is_readable($path)) {
            error_log("Env::load: .env not found or not readable: {$path}");
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            error_log("Env::load: failed to read file: {$path}");
            return;
        }

        $loadedCount = 0;
        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Skip comments and empty lines
            if ($trimmed === '' || $trimmed[0] === '#') {
                continue;
            }

            // Remove optional "export " prefix
            if (substr($trimmed, 0, 7) === 'export ') {
                $trimmed = ltrim(substr($trimmed, 7));
            }

            $eqPos = strpos($trimmed, '=');
            if ($eqPos === false) {
                continue;
            }

            $name = trim(substr($trimmed, 0, $eqPos));
            $value = trim(substr($trimmed, $eqPos + 1));

            if ($name === '') {
                continue;
            }

            $isQuoted = false;
            $len = strlen($value);
            if ($len >= 2) {
                $first = $value[0];
                $last = $value[$len - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $isQuoted = true;
                    $value = substr($value, 1, -1);
                }
            }

            // If not quoted, strip inline comments starting with space-#
            if (!$isQuoted) {
                $hashPos = strpos($value, ' #');
                if ($hashPos !== false) {
                    $value = rtrim(substr($value, 0, $hashPos));
                }
            } else {
                // Unescape common sequences
                $value = str_replace(['\\n', '\\r', '\\t', '\\"', "\\'"], ["\n", "\r", "\t", '"', "'"], $value);
            }

            // Do not override existing env
            if (getenv($name) !== false || array_key_exists($name, $_ENV) || array_key_exists($name, $_SERVER)) {
                continue;
            }

            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
            $loadedCount++;
        }

        error_log("Env::load: loaded {$loadedCount} variables from .env");
    }
}


