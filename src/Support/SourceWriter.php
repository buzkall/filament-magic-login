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
    protected function importedAliases(string $code): array
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

    /**
     * The bracket indices of the single array literal passed to `->{$method}([ ... ])`.
     *
     * Null unless the file contains exactly one such call and its first argument is
     * written out as an array: `->recordActions($actions)` is a shape we cannot append
     * to without guessing where $actions came from.
     *
     * @param  array<int, array{id: int|null, text: string, offset: int}>  $tokens
     * @return array{0: int, 1: int}|null
     */
    protected function findMethodCallArrayLiteral(array $tokens, string $method): ?array
    {
        $found = [];

        foreach ($tokens as $index => $token) {
            if ($token['id'] !== T_OBJECT_OPERATOR) {
                continue;
            }

            $name = $this->nextMeaningful($tokens, $index + 1);

            if ($name === null || $tokens[$name]['id'] !== T_STRING || $tokens[$name]['text'] !== $method) {
                continue;
            }

            $open = $this->nextMeaningful($tokens, $name + 1);

            if ($open === null || $tokens[$open]['text'] !== '(') {
                continue;
            }

            $bracket = $this->nextMeaningful($tokens, $open + 1);

            // A call we found but cannot append to still counts: two `recordActions`
            // calls, or one taking a variable, both mean "leave this file alone".
            if ($bracket === null || $tokens[$bracket]['text'] !== '[') {
                return null;
            }

            $close = $this->matchingBracket($tokens, $bracket);

            if ($close === null) {
                return null;
            }

            $found[] = [$bracket, $close];
        }

        return count($found) === 1 ? $found[0] : null;
    }

    /**
     * The bracket indices of the single array literal `{$method}()` returns.
     *
     * Null when the method is absent, or returns anything other than exactly one
     * literal array — `return $actions;` and two `return` statements alike.
     *
     * @param  array<int, array{id: int|null, text: string, offset: int}>  $tokens
     * @return array{0: int, 1: int}|null
     */
    protected function findReturnedArrayLiteral(array $tokens, string $method): ?array
    {
        $body = $this->methodBody($tokens, $method);

        if ($body === null) {
            return null;
        }

        [$start, $end] = $body;
        $found = [];

        for ($index = $start; $index < $end; $index++) {
            if ($tokens[$index]['id'] !== T_RETURN) {
                continue;
            }

            $bracket = $this->nextMeaningful($tokens, $index + 1);

            if ($bracket === null || $tokens[$bracket]['text'] !== '[') {
                return null;
            }

            $close = $this->matchingBracket($tokens, $bracket);

            if ($close === null) {
                return null;
            }

            $found[] = [$bracket, $close];
        }

        return count($found) === 1 ? $found[0] : null;
    }

    /**
     * Splices an element into an array literal, after the last one already in it.
     *
     * @param  array<int, array{id: int|null, text: string, offset: int}>  $tokens
     */
    protected function appendArrayElement(string $code, array $tokens, int $open, int $close, string $element): string
    {
        $last = $this->nextMeaningfulBefore($tokens, $close - 1, $open);
        $indentation = $this->elementIndentation($tokens, $open, $close);

        // An empty array, or one written on a single line: stay on that line rather
        // than reformatting somebody's file around our own addition.
        if ($last === null || $indentation === null) {
            $offset = $tokens[$close]['offset'];
            $separator = ($last === null || $tokens[$last]['text'] === ',') ? '' : ', ';

            return substr($code, 0, $offset).$separator.$element.substr($code, $offset);
        }

        // Written after the last element and its comma, so the closing bracket and the
        // whitespace in front of it stay exactly where they were.
        $insertAt = $tokens[$last]['offset'] + strlen($tokens[$last]['text']);
        $comma = $tokens[$last]['text'] === ',' ? '' : ',';

        return substr($code, 0, $insertAt).$comma."\n".$indentation.$element.','.substr($code, $insertAt);
    }

    /**
     * Index of the `]` or `)` closing the bracket at $index.
     *
     * @param  array<int, array{id: int|null, text: string, offset: int}>  $tokens
     */
    protected function matchingBracket(array $tokens, int $index): ?int
    {
        $depth = 0;

        for ($current = $index; $current < count($tokens); $current++) {
            $text = $tokens[$current]['text'];

            if (in_array($text, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                $depth--;

                if ($depth === 0) {
                    return $current;
                }
            }
        }

        return null;
    }

    /**
     * Index of the previous token that is neither whitespace nor a comment, stopping
     * at $floor.
     *
     * @param  array<int, array{id: int|null, text: string, offset: int}>  $tokens
     */
    protected function nextMeaningfulBefore(array $tokens, int $index, int $floor): ?int
    {
        for ($current = $index; $current > $floor; $current--) {
            if (in_array($tokens[$current]['id'], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $current;
        }

        return null;
    }

    /**
     * The `{` and `}` indices of a method's body.
     *
     * @param  array<int, array{id: int|null, text: string, offset: int}>  $tokens
     * @return array{0: int, 1: int}|null
     */
    private function methodBody(array $tokens, string $method): ?array
    {
        foreach ($tokens as $index => $token) {
            if ($token['id'] !== T_FUNCTION) {
                continue;
            }

            $name = $this->nextMeaningful($tokens, $index + 1);

            if ($name === null || $tokens[$name]['id'] !== T_STRING || $tokens[$name]['text'] !== $method) {
                continue;
            }

            for ($current = $name; $current < count($tokens); $current++) {
                if ($tokens[$current]['text'] === '{') {
                    $close = $this->matchingBracket($tokens, $current);

                    return $close === null ? null : [$current, $close];
                }

                // An abstract or interface method has no body to search.
                if ($tokens[$current]['text'] === ';') {
                    return null;
                }
            }
        }

        return null;
    }

    /**
     * The whitespace this array's own elements are indented by, taken from the last of
     * them so both `[\n    a,\n]` and `[a,\n    b,\n]` are read correctly. Null when
     * the array is written on a single line or holds nothing yet.
     *
     * @param  array<int, array{id: int|null, text: string, offset: int}>  $tokens
     */
    private function elementIndentation(array $tokens, int $open, int $close): ?string
    {
        $indentation = null;
        $depth = 0;

        for ($index = $open; $index < $close; $index++) {
            $text = $tokens[$index]['text'];

            if (in_array($text, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                $depth--;
            }

            // The opening bracket, or a comma separating this array's own elements —
            // anything deeper belongs to a nested array or call.
            if (($index !== $open) && ! ($depth === 1 && $text === ',')) {
                continue;
            }

            $whitespace = $tokens[$index + 1] ?? null;

            if ($whitespace === null || $whitespace['id'] !== T_WHITESPACE || ! str_contains($whitespace['text'], "\n")) {
                continue;
            }

            // Whitespace that only leads to the closing bracket describes the bracket,
            // not an element.
            if ($this->nextMeaningful($tokens, $index + 1) === $close) {
                continue;
            }

            $indentation = substr($whitespace['text'], strrpos($whitespace['text'], "\n") + 1);
        }

        return $indentation;
    }
}
