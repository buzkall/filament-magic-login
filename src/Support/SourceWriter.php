<?php

namespace Arzcode\FilamentMagicLogin\Support;

use ParseError;

/**
 * Shared machinery for the writers that edit an application's own PHP files.
 *
 * Everything here works on the token stream rather than with regular expressions,
 * and refuses to guess: a file that cannot be rewritten with certainty comes back
 * as null so the caller can print the snippet instead.
 */
abstract class SourceWriter
{
    public function isParsable(string $code): bool
    {
        try {
            return token_get_all($code, TOKEN_PARSE) !== [];
        } catch (ParseError) {
            return false;
        }
    }

    /**
     * Adds whichever of the given classes the file does not import yet.
     *
     * @param  array<int, string>  $classes
     * @return string|null The rewritten file, or null when an unrelated class already
     *                     owns one of the short names and importing ours would be a
     *                     fatal "name already in use".
     */
    protected function withImports(string $code, array $classes): ?string
    {
        $aliases = $this->importedAliases($code);

        foreach ($classes as $class) {
            $alias = $this->shortName($class);

            if (array_key_exists($alias, $aliases) && $aliases[$alias] !== $class) {
                return null;
            }
        }

        $missing = array_values(array_filter(
            $classes,
            fn (string $class): bool => ! in_array($class, $aliases, true),
        ));

        return $missing === [] ? $code : $this->insertImports($code, $missing);
    }

    protected function shortName(string $class): string
    {
        return substr(strrchr('\\'.$class, '\\') ?: '', 1);
    }

    /**
     * The token stream with each token's byte offset, so a rewrite can splice the
     * original source instead of reassembling it.
     *
     * @return array<int, array{id: int|null, text: string, offset: int}>
     */
    protected function scan(string $code): array
    {
        $tokens = [];
        $cursor = 0;

        foreach (token_get_all($code) as $token) {
            $text = is_array($token) ? $token[1] : $token;

            $tokens[] = [
                'id' => is_array($token) ? $token[0] : null,
                'text' => $text,
                'offset' => $cursor,
            ];

            $cursor += strlen($text);
        }

        return $tokens;
    }

    /**
     * Index of the next token that is neither whitespace nor a comment.
     *
     * @param  array<int, array{id: int|null, text: string, offset: int}>  $tokens
     */
    protected function nextMeaningful(array $tokens, int $index): ?int
    {
        for ($current = $index; $current < count($tokens); $current++) {
            if (in_array($tokens[$current]['id'], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $current;
        }

        return null;
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
            $alias = $matches[2] ?? $this->shortName($class);

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
