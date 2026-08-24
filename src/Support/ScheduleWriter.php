<?php

namespace Arzcode\FilamentMagicLogin\Support;

use ParseError;

/**
 * Adds Laravel's pruner for our token model to an application's console routes.
 *
 * Like PackageReferenceRemover it works on the token stream and refuses to guess:
 * anything it cannot rewrite with certainty comes back as null, and the caller
 * prints the snippet for the developer to paste instead.
 */
final class ScheduleWriter
{
    public const SCHEDULE_CLASS = 'Illuminate\\Support\\Facades\\Schedule';

    public const MODEL_CLASS = 'Arzcode\\FilamentMagicLogin\\Models\\MagicLoginToken';

    /**
     * The pruner is already scheduled if any line asks `model:prune` for our model,
     * whatever shape the developer wrote it in.
     */
    public function isScheduled(string $code): bool
    {
        return str_contains($code, 'model:prune')
            && str_contains($code, 'MagicLoginToken');
    }

    /**
     * @return string|null The rewritten file, or null when it cannot be written safely.
     */
    public function add(string $code): ?string
    {
        $aliases = $this->importedAliases($code);

        // An unrelated class already owning either short name would collide with the
        // import we are about to add, so leave the file alone.
        foreach (['Schedule' => self::SCHEDULE_CLASS, 'MagicLoginToken' => self::MODEL_CLASS] as $alias => $class) {
            if (array_key_exists($alias, $aliases) && $aliases[$alias] !== $class) {
                return null;
            }
        }

        $missing = array_values(array_filter(
            [self::SCHEDULE_CLASS, self::MODEL_CLASS],
            fn (string $class): bool => ! in_array($class, $aliases, true),
        ));

        $result = $missing === [] ? $code : $this->insertImports($code, $missing);

        if ($result === null) {
            return null;
        }

        $result = rtrim($result, "\n")."\n\n".$this->block()."\n";

        return $this->isParsable($result) ? $result : null;
    }

    public function block(): string
    {
        return <<<'PHP'
        Schedule::command('model:prune', [
            '--model' => [MagicLoginToken::class],
        ])->daily();
        PHP;
    }

    /**
     * The same call with nothing imported, for printing when the file cannot be edited.
     */
    public function snippet(): string
    {
        return sprintf(
            "use %s;\nuse %s;\n\n%s",
            self::SCHEDULE_CLASS,
            self::MODEL_CLASS,
            $this->block(),
        );
    }

    public function isParsable(string $code): bool
    {
        try {
            return token_get_all($code, TOKEN_PARSE) !== [];
        } catch (ParseError) {
            return false;
        }
    }

    /**
     * Puts the imports after the last existing one, or straight after the opening tag
     * when the file has none.
     *
     * @param  array<int, string>  $classes
     */
    private function insertImports(string $code, array $classes): ?string
    {
        $offset = $this->importInsertionOffset($code);

        if ($offset === null) {
            return null;
        }

        $imports = implode('', array_map(
            fn (string $class): string => "use {$class};\n",
            $classes,
        ));

        return substr($code, 0, $offset).$imports.substr($code, $offset);
    }

    /**
     * The byte offset a new `use` line can be written at.
     */
    private function importInsertionOffset(string $code): ?int
    {
        $tokens = token_get_all($code);
        $offset = null;
        $cursor = 0;
        $depth = 0;

        foreach ($tokens as $index => $token) {
            $text = is_array($token) ? $token[1] : $token;

            if ($text === '{') {
                $depth++;
            } elseif ($text === '}') {
                $depth--;
            }

            if ($offset === null && is_array($token) && $token[0] === T_OPEN_TAG) {
                // Nothing imported yet: land right under `<?php`.
                $offset = $cursor + strlen($text);
            }

            if ($depth === 0 && $this->isImport($tokens, $index)) {
                $offset = $this->endOfStatement($tokens, $index, $cursor);
            }

            $cursor += strlen($text);
        }

        return $offset;
    }

    /**
     * A top-level `use Foo\Bar;`, as opposed to a closure's `use ($var)` or a trait's.
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private function isImport(array $tokens, int $index): bool
    {
        $token = $tokens[$index];

        if (! is_array($token) || $token[0] !== T_USE) {
            return false;
        }

        for ($previous = $index - 1; $previous >= 0; $previous--) {
            $text = is_array($tokens[$previous]) ? $tokens[$previous][1] : $tokens[$previous];

            if (trim($text) === '') {
                continue;
            }

            // `function () use ($x)` is the only `use` that follows a closing paren.
            return $text !== ')';
        }

        return true;
    }

    /**
     * Offset just past the `;` closing the statement that starts at $index.
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private function endOfStatement(array $tokens, int $index, int $cursor): int
    {
        $offset = $cursor;

        for ($current = $index; $current < count($tokens); $current++) {
            $text = is_array($tokens[$current]) ? $tokens[$current][1] : $tokens[$current];
            $offset += strlen($text);

            if ($text === ';') {
                break;
            }
        }

        // Swallow the newline so the next import starts on its own line.
        return $offset + 1;
    }

    /**
     * Top-level imports, keyed by the short name they bind.
     *
     * @return array<string, string>
     */
    private function importedAliases(string $code): array
    {
        $tokens = token_get_all($code);
        $aliases = [];
        $depth = 0;

        foreach ($tokens as $index => $token) {
            $text = is_array($token) ? $token[1] : $token;

            if ($text === '{') {
                $depth++;
            } elseif ($text === '}') {
                $depth--;
            }

            if ($depth !== 0 || ! $this->isImport($tokens, $index)) {
                continue;
            }

            $statement = $this->statementText($tokens, $index);

            // `use function foo;` and `use const BAR;` bind neither a class nor a name
            // we could collide with.
            if (preg_match('/^use\s+(function|const)\s/', $statement) === 1) {
                continue;
            }

            if (preg_match('/^use\s+([^\s;]+?)(?:\s+as\s+([^\s;]+))?\s*;/i', $statement, $matches) !== 1) {
                continue;
            }

            $class = ltrim($matches[1], '\\');
            $alias = $matches[2] ?? substr(strrchr('\\'.$class, '\\') ?: '', 1);

            $aliases[$alias] = $class;
        }

        return $aliases;
    }

    /**
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private function statementText(array $tokens, int $index): string
    {
        $statement = '';

        for ($current = $index; $current < count($tokens); $current++) {
            $text = is_array($tokens[$current]) ? $tokens[$current][1] : $tokens[$current];
            $statement .= $text;

            if ($text === ';') {
                break;
            }
        }

        return $statement;
    }
}
