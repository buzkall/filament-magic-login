<?php

namespace Arzcode\FilamentMagicLogin\Support;

use Arzcode\FilamentMagicLogin\Data\CleanedSource;
use ParseError;

/**
 * Strips this package's references out of a PHP source file, so that removing the
 * Composer package cannot leave the application pointing at classes that are gone.
 *
 * It works on the token stream rather than with regular expressions, and anything it
 * cannot rewrite with certainty is reported instead of guessed at.
 */
final class PackageReferenceRemover
{
    public const NAMESPACE = 'Arzcode\\FilamentMagicLogin';

    /**
     * The two classes an application registers by hand, and the only references this
     * class knows how to rewrite.
     */
    private const PLUGIN_CLASS = 'MagicLoginPlugin';

    private const TRAIT_CLASS = 'HasMagicLinkAction';

    public function remove(string $code): CleanedSource
    {
        if (! str_contains($code, self::NAMESPACE)) {
            return new CleanedSource($code, false, []);
        }

        $cleaned = $this->removeTraitUses($this->removePluginRegistrations($code));
        $cleaned = $this->removeUnusedImports($cleaned);

        if (! $this->isParsable($cleaned)) {
            // Never hand back something that would not compile.
            return new CleanedSource($code, false, $this->referenceLines($code));
        }

        return new CleanedSource(
            $cleaned,
            $cleaned !== $code,
            $this->referenceLines($cleaned),
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
     * Lines that still mention the package after the rewrite, for the caller to report.
     *
     * @return array<int, int>
     */
    public function referenceLines(string $code): array
    {
        $lines = [];

        foreach ($this->tokens($code) as $token) {
            if (
                str_contains($token['text'], self::NAMESPACE) ||
                str_contains($token['text'], self::PLUGIN_CLASS) ||
                str_contains($token['text'], self::TRAIT_CLASS)
            ) {
                $lines[] = $token['line'];
            }
        }

        return array_values(array_unique($lines));
    }

    /**
     * Removes `->plugin(MagicLoginPlugin::make()...)` calls, and our entry from any
     * `->plugins([...])` array (dropping the whole call when nothing else is left).
     */
    private function removePluginRegistrations(string $code): string
    {
        $aliases = $this->aliasesFor($code, self::PLUGIN_CLASS);

        do {
            $tokens = $this->tokens($code);
            $range = $this->findPluginCallRange($tokens, $aliases);

            if ($range === null) {
                return $code;
            }

            $code = $this->cut($tokens, $range[0], $range[1]);
        } while (true);
    }

    /**
     * @param  array<int, array{id: int|null, text: string, line: int}>  $tokens
     * @param  array<int, string>  $aliases
     * @return array{0: int, 1: int}|null
     */
    private function findPluginCallRange(array $tokens, array $aliases): ?array
    {
        foreach ($tokens as $index => $token) {
            if ($token['id'] !== T_OBJECT_OPERATOR) {
                continue;
            }

            $nameIndex = $this->nextMeaningful($tokens, $index + 1);

            if ($nameIndex === null) {
                continue;
            }

            $method = $tokens[$nameIndex]['text'];

            if (! in_array($method, ['plugin', 'plugins'], true)) {
                continue;
            }

            $openIndex = $this->nextMeaningful($tokens, $nameIndex + 1);

            if ($openIndex === null || $tokens[$openIndex]['text'] !== '(') {
                continue;
            }

            $closeIndex = $this->matchingBracket($tokens, $openIndex);

            if ($closeIndex === null) {
                continue;
            }

            if (! $this->rangeMentions($tokens, $openIndex, $closeIndex, $aliases)) {
                continue;
            }

            if ($method === 'plugin') {
                return [$this->withLeadingWhitespace($tokens, $index), $closeIndex];
            }

            $element = $this->findArrayElementRange($tokens, $openIndex, $closeIndex, $aliases);

            // Ours was the only entry, so the whole `->plugins([...])` call goes.
            if ($element === null) {
                return [$this->withLeadingWhitespace($tokens, $index), $closeIndex];
            }

            return $element;
        }

        return null;
    }

    /**
     * The range covering just our element of a `plugins([...])` array, or null when it
     * is the only element and the entire call should go instead.
     *
     * @param  array<int, array{id: int|null, text: string, line: int}>  $tokens
     * @param  array<int, string>  $aliases
     * @return array{0: int, 1: int}|null
     */
    private function findArrayElementRange(array $tokens, int $openIndex, int $closeIndex, array $aliases): ?array
    {
        $arrayOpen = $this->nextMeaningful($tokens, $openIndex + 1);

        if ($arrayOpen === null || ! in_array($tokens[$arrayOpen]['text'], ['[', 'array'], true)) {
            return null;
        }

        if ($tokens[$arrayOpen]['text'] === 'array') {
            $arrayOpen = $this->nextMeaningful($tokens, $arrayOpen + 1) ?? $arrayOpen;
        }

        $arrayClose = $this->matchingBracket($tokens, $arrayOpen);

        if ($arrayClose === null) {
            return null;
        }

        $elements = $this->splitElements($tokens, $arrayOpen, $arrayClose);

        $mine = array_values(array_filter(
            $elements,
            fn (array $element): bool => $this->rangeMentions($tokens, $element[0], $element[1], $aliases),
        ));

        if ($mine === [] || count($elements) === count($mine)) {
            return null;
        }

        [$start, $end] = $mine[0];

        // Swallow the separating comma so the array stays valid.
        $afterEnd = $this->nextMeaningful($tokens, $end + 1);

        if ($afterEnd !== null && $tokens[$afterEnd]['text'] === ',') {
            $end = $afterEnd;
        }

        return [$this->withLeadingWhitespace($tokens, $start), $end];
    }

    /**
     * Top-level element ranges of an array literal.
     *
     * @param  array<int, array{id: int|null, text: string, line: int}>  $tokens
     * @return array<int, array{0: int, 1: int}>
     */
    private function splitElements(array $tokens, int $arrayOpen, int $arrayClose): array
    {
        $elements = [];
        $depth = 0;
        $start = null;

        for ($index = $arrayOpen + 1; $index < $arrayClose; $index++) {
            $text = $tokens[$index]['text'];

            if (in_array($text, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                $depth--;
            }

            if ($depth === 0 && $text === ',') {
                if ($start !== null) {
                    $elements[] = [$start, $index - 1];
                    $start = null;
                }

                continue;
            }

            if ($tokens[$index]['id'] === T_WHITESPACE) {
                continue;
            }

            $start ??= $index;
        }

        if ($start !== null) {
            $elements[] = [$start, $arrayClose - 1];
        }

        return $elements;
    }

    /**
     * Removes `use HasMagicLinkAction;` statements from inside a class body.
     */
    private function removeTraitUses(string $code): string
    {
        $aliases = $this->aliasesFor($code, self::TRAIT_CLASS);

        do {
            $tokens = $this->tokens($code);
            $range = $this->findTraitUseRange($tokens, $aliases);

            if ($range === null) {
                return $code;
            }

            $code = $this->cutStatement($tokens, $range[0], $range[1]);
        } while (true);
    }

    /**
     * @param  array<int, array{id: int|null, text: string, line: int}>  $tokens
     * @param  array<int, string>  $aliases
     * @return array{0: int, 1: int}|null
     */
    private function findTraitUseRange(array $tokens, array $aliases): ?array
    {
        $depth = 0;

        foreach ($tokens as $index => $token) {
            if ($token['text'] === '{') {
                $depth++;
            } elseif ($token['text'] === '}') {
                $depth--;
            }

            // Depth 0 is an import; a closure's `use` is followed by a parenthesis.
            if ($token['id'] !== T_USE || $depth === 0) {
                continue;
            }

            $end = $this->statementEnd($tokens, $index);

            if ($end === null) {
                continue;
            }

            if (! $this->rangeMentions($tokens, $index, $end, $aliases)) {
                continue;
            }

            // Only a lone `use Trait;` is safe to delete wholesale.
            if (! $this->isSoleTraitUse($tokens, $index, $end, $aliases)) {
                continue;
            }

            return [$index, $end];
        }

        return null;
    }

    /**
     * @param  array<int, array{id: int|null, text: string, line: int}>  $tokens
     * @param  array<int, string>  $aliases
     */
    private function isSoleTraitUse(array $tokens, int $start, int $end, array $aliases): bool
    {
        $names = 0;

        for ($index = $start + 1; $index <= $end; $index++) {
            $token = $tokens[$index];

            if ($token['text'] === ',' || $token['text'] === '{') {
                return false;
            }

            if (in_array($token['id'], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $names++;

                if (! $this->mentions($token['text'], $aliases)) {
                    return false;
                }
            }
        }

        return $names === 1;
    }

    /**
     * Drops `use Arzcode\FilamentMagicLogin\...;` imports whose short name is no longer
     * referenced anywhere in the file.
     */
    private function removeUnusedImports(string $code): string
    {
        do {
            $tokens = $this->tokens($code);
            $range = $this->findUnusedImportRange($tokens);

            if ($range === null) {
                return $code;
            }

            $code = $this->cutStatement($tokens, $range[0], $range[1]);
        } while (true);
    }

    /**
     * @param  array<int, array{id: int|null, text: string, line: int}>  $tokens
     * @return array{0: int, 1: int}|null
     */
    private function findUnusedImportRange(array $tokens): ?array
    {
        $depth = 0;

        foreach ($tokens as $index => $token) {
            if ($token['text'] === '{') {
                $depth++;
            } elseif ($token['text'] === '}') {
                $depth--;
            }

            if ($token['id'] !== T_USE || $depth !== 0) {
                continue;
            }

            $end = $this->statementEnd($tokens, $index);

            if ($end === null) {
                continue;
            }

            $name = null;

            for ($cursor = $index + 1; $cursor <= $end; $cursor++) {
                if (in_array($tokens[$cursor]['id'], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    $name = $tokens[$cursor]['text'];
                }
            }

            if ($name === null || ! str_contains($name, self::NAMESPACE)) {
                continue;
            }

            // A grouped or aliased import may cover more than we can reason about.
            if ($this->rangeContains($tokens, $index, $end, ['{', ','])) {
                continue;
            }

            $short = $this->shortNameOf($tokens, $index, $end, $name);

            if ($this->isNameUsedOutside($tokens, $index, $end, $short)) {
                continue;
            }

            return [$index, $end];
        }

        return null;
    }

    /**
     * @param  array<int, array{id: int|null, text: string, line: int}>  $tokens
     */
    private function shortNameOf(array $tokens, int $start, int $end, string $name): string
    {
        for ($index = $start + 1; $index < $end; $index++) {
            if ($tokens[$index]['id'] !== T_AS) {
                continue;
            }

            $aliasIndex = $this->nextMeaningful($tokens, $index + 1);

            if ($aliasIndex !== null) {
                return $tokens[$aliasIndex]['text'];
            }
        }

        $segments = explode('\\', $name);

        return end($segments) ?: $name;
    }

    /**
     * @param  array<int, array{id: int|null, text: string, line: int}>  $tokens
     */
    private function isNameUsedOutside(array $tokens, int $start, int $end, string $short): bool
    {
        foreach ($tokens as $index => $token) {
            if ($index >= $start && $index <= $end) {
                continue;
            }

            if (! in_array($token['id'], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            if ($token['text'] === $short || str_starts_with($token['text'], $short.'\\')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Import aliases for one of our classes, so aliased usages are still recognised.
     *
     * @return array<int, string>
     */
    private function aliasesFor(string $code, string $class): array
    {
        $aliases = [$class];

        foreach ($this->tokens($code) as $index => $token) {
            if (! str_contains($token['text'], self::NAMESPACE.'\\')) {
                continue;
            }

            if (! str_ends_with($token['text'], '\\'.$class)) {
                continue;
            }

            $tokens = $this->tokens($code);
            $end = $this->statementEnd($tokens, $index) ?? $index;

            $aliases[] = $this->shortNameOf($tokens, $index - 1, $end, $token['text']);
        }

        return array_values(array_unique($aliases));
    }

    /**
     * @param  array<int, array{id: int|null, text: string, line: int}>  $tokens
     * @param  array<int, string>  $aliases
     */
    private function rangeMentions(array $tokens, int $start, int $end, array $aliases): bool
    {
        for ($index = $start; $index <= $end; $index++) {
            if ($this->mentions($tokens[$index]['text'], $aliases)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $aliases
     */
    private function mentions(string $text, array $aliases): bool
    {
        foreach ($aliases as $alias) {
            if ($text === $alias || str_ends_with($text, '\\'.$alias)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array{id: int|null, text: string, line: int}>  $tokens
     * @param  array<int, string>  $needles
     */
    private function rangeContains(array $tokens, int $start, int $end, array $needles): bool
    {
        for ($index = $start; $index <= $end; $index++) {
            if (in_array($tokens[$index]['text'], $needles, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array{id: int|null, text: string, line: int}>  $tokens
     */
    private function statementEnd(array $tokens, int $start): ?int
    {
        for ($index = $start; $index < count($tokens); $index++) {
            if ($tokens[$index]['text'] === ';') {
                return $index;
            }
        }

        return null;
    }

    /**
     * Extends a range backwards over the whitespace that indented it, so removing a
     * chained call does not leave a blank line behind.
     *
     * @param  array<int, array{id: int|null, text: string, line: int}>  $tokens
     */
    private function withLeadingWhitespace(array $tokens, int $index): int
    {
        $previous = $index - 1;

        if ($previous < 0 || $tokens[$previous]['id'] !== T_WHITESPACE) {
            return $index;
        }

        return str_contains($tokens[$previous]['text'], "\n") ? $previous : $index;
    }

    /**
     * @param  array<int, array{id: int|null, text: string, line: int}>  $tokens
     */
    private function matchingBracket(array $tokens, int $openIndex): ?int
    {
        $pairs = ['(' => ')', '[' => ']', '{' => '}'];
        $open = $tokens[$openIndex]['text'];

        if (! array_key_exists($open, $pairs)) {
            return null;
        }

        $depth = 0;

        for ($index = $openIndex; $index < count($tokens); $index++) {
            $text = $tokens[$index]['text'];

            if ($text === $open) {
                $depth++;
            } elseif ($text === $pairs[$open]) {
                $depth--;

                if ($depth === 0) {
                    return $index;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, array{id: int|null, text: string, line: int}>  $tokens
     */
    private function nextMeaningful(array $tokens, int $index): ?int
    {
        for ($cursor = $index; $cursor < count($tokens); $cursor++) {
            if (! in_array($tokens[$cursor]['id'], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $cursor;
            }
        }

        return null;
    }

    /**
     * Deletes a whole statement plus exactly the line it occupied, so a blank line
     * that separated it from what came before survives.
     *
     * @param  array<int, array{id: int|null, text: string, line: int}>  $tokens
     */
    private function cutStatement(array $tokens, int $start, int $end): string
    {
        $code = '';

        foreach ($tokens as $index => $token) {
            if ($index >= $start && $index <= $end) {
                continue;
            }

            if ($index === $start - 1 && $token['id'] === T_WHITESPACE) {
                $position = strrpos($token['text'], "\n");

                $code .= $position === false ? $token['text'] : substr($token['text'], 0, $position + 1);

                continue;
            }

            if ($index === $end + 1 && $token['id'] === T_WHITESPACE) {
                $code .= preg_replace('/\n/', '', $token['text'], 1) ?? $token['text'];

                continue;
            }

            $code .= $token['text'];
        }

        return $this->tidyWhitespace($code);
    }

    /**
     * Collapses the blank line a removed statement can leave right after an opening
     * brace. Only whitespace tokens are touched, so strings are never affected.
     */
    private function tidyWhitespace(string $code): string
    {
        $tokens = $this->tokens($code);
        $result = '';

        foreach ($tokens as $index => $token) {
            if (
                $token['id'] === T_WHITESPACE &&
                $index > 0 &&
                $tokens[$index - 1]['text'] === '{' &&
                substr_count($token['text'], "\n") > 1
            ) {
                $position = strrpos($token['text'], "\n");

                $result .= "\n".($position === false ? '' : substr($token['text'], $position + 1));

                continue;
            }

            $result .= $token['text'];
        }

        return $result;
    }

    /**
     * @param  array<int, array{id: int|null, text: string, line: int}>  $tokens
     */
    private function cut(array $tokens, int $start, int $end): string
    {
        $code = '';

        foreach ($tokens as $index => $token) {
            if ($index >= $start && $index <= $end) {
                continue;
            }

            $code .= $token['text'];
        }

        return $code;
    }

    /**
     * @return array<int, array{id: int|null, text: string, line: int}>
     */
    private function tokens(string $code): array
    {
        $tokens = [];
        $line = 1;

        foreach (token_get_all($code) as $token) {
            if (is_array($token)) {
                $tokens[] = ['id' => $token[0], 'text' => $token[1], 'line' => $token[2]];
                $line = $token[2] + substr_count($token[1], "\n");

                continue;
            }

            $tokens[] = ['id' => null, 'text' => $token, 'line' => $line];
        }

        return $tokens;
    }
}
