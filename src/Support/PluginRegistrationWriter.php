<?php

namespace Arzcode\FilamentMagicLogin\Support;

/**
 * Registers the plugin on a Filament panel provider: as a new entry in the
 * `->plugins([...])` array the `$panel` chain already has, or else by appending
 * `->plugin(...)` to the chain the provider returns.
 *
 * The exact inverse of what PackageReferenceRemover strips out on uninstall, and it
 * holds itself to the same standard: a provider whose chain cannot be found — or
 * whose rewrite would not parse — comes back as null rather than half-edited.
 */
final class PluginRegistrationWriter extends SourceWriter
{
    public const PLUGIN_CLASS = 'Arzcode\\FilamentMagicLogin\\MagicLoginPlugin';

    /**
     * The plugin is registered if the file mentions it at all, whichever way it was
     * written: `->plugin(MagicLoginPlugin::make())`, a `->plugins([...])` array, or
     * the fully qualified class name.
     */
    public function isRegistered(string $code): bool
    {
        return str_contains($code, $this->shortName(self::PLUGIN_CLASS));
    }

    /**
     * A panel provider is the only file we know how to edit: it extends Filament's
     * PanelProvider and configures a panel in a `panel()` method.
     */
    public function isPanelProvider(string $code): bool
    {
        return str_contains($code, 'PanelProvider')
            && preg_match('/function\s+panel\s*\(/', $code) === 1;
    }

    /**
     * @return string|null The rewritten file, or null when it cannot be written safely.
     */
    public function add(string $code): ?string
    {
        if (! $this->isParsable($code)) {
            return null;
        }

        $result = $this->withImports($code, [self::PLUGIN_CLASS]);

        if ($result === null) {
            return null;
        }

        $result = $this->insertCall($result);

        if ($result === null) {
            return null;
        }

        return $this->isParsable($result) ? $result : null;
    }

    public function block(): string
    {
        return sprintf('->plugin(%s)', $this->element());
    }

    private function element(): string
    {
        return sprintf('%s::make()', $this->shortName(self::PLUGIN_CLASS));
    }

    /**
     * The registration with its import, for printing when no file can be edited.
     */
    public function snippet(): string
    {
        return sprintf(
            "use %s;\n\n\$panel\n    ->login()\n    %s;",
            self::PLUGIN_CLASS,
            $this->block(),
        );
    }

    /**
     * Joins the chain's `->plugins([...])` array when it has one, and otherwise appends
     * the call to the chain that is returned, just before its semicolon.
     */
    private function insertCall(string $code): ?string
    {
        $tokens = $this->scan($code);
        $start = $this->returnedPanelIndex($tokens);

        if ($start === null) {
            return null;
        }

        $end = $this->endOfChain($tokens, $start);

        if ($end === null) {
            return null;
        }

        $array = $this->pluginsArray($tokens, $start, $end);

        if ($array !== null) {
            return $this->appendArrayElement($code, $tokens, $array[0], $array[1], $this->element());
        }

        $call = $this->block();
        $indentation = $this->chainIndentation($tokens, $start, $end);

        if ($indentation !== null) {
            $call = "\n".$indentation.$call;
        }

        $offset = $tokens[$end]['offset'];

        return substr($code, 0, $offset).$call.substr($code, $offset);
    }

    /**
     * Index of the `$panel` in the provider's `return $panel...` statement.
     *
     * A file with no such statement, or with more than one, is a shape we have no
     * business guessing at.
     *
     * @param  array<int, array{id: int|null, text: string, offset: int}>  $tokens
     */
    private function returnedPanelIndex(array $tokens): ?int
    {
        $found = [];

        foreach ($tokens as $index => $token) {
            if ($token['id'] !== T_RETURN) {
                continue;
            }

            $next = $this->nextMeaningful($tokens, $index + 1);

            if ($next === null) {
                continue;
            }

            if ($tokens[$next]['id'] === T_VARIABLE && $tokens[$next]['text'] === '$panel') {
                $found[] = $next;
            }
        }

        return count($found) === 1 ? $found[0] : null;
    }

    /**
     * The bracket indices of the array literal passed to the chain's own
     * `->plugins([...])` call.
     *
     * Null when the chain has no such call, has more than one, or passes it anything
     * but a literal array — `->plugins($plugins)` is left alone and the call appended.
     *
     * @param  array<int, array{id: int|null, text: string, offset: int}>  $tokens
     * @return array{0: int, 1: int}|null
     */
    private function pluginsArray(array $tokens, int $start, int $end): ?array
    {
        $found = [];
        $depth = 0;

        for ($index = $start; $index < $end; $index++) {
            $text = $tokens[$index]['text'];

            if (in_array($text, ['(', '[', '{'], true)) {
                $depth++;

                continue;
            }

            if (in_array($text, [')', ']', '}'], true)) {
                $depth--;

                continue;
            }

            // Only the chain's own calls count; a plugin configured inside the array
            // could have a `plugins` method of its own.
            if ($depth !== 0 || $tokens[$index]['id'] !== T_OBJECT_OPERATOR) {
                continue;
            }

            $name = $this->nextMeaningful($tokens, $index + 1);

            if ($name === null || $tokens[$name]['id'] !== T_STRING || $tokens[$name]['text'] !== 'plugins') {
                continue;
            }

            $open = $this->nextMeaningful($tokens, $name + 1);
            $bracket = $open === null ? null : $this->nextMeaningful($tokens, $open + 1);

            if ($bracket === null || $tokens[$open]['text'] !== '(' || $tokens[$bracket]['text'] !== '[') {
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
     * Index of the `;` closing the returned chain.
     *
     * @param  array<int, array{id: int|null, text: string, offset: int}>  $tokens
     */
    private function endOfChain(array $tokens, int $start): ?int
    {
        $depth = 0;

        for ($index = $start; $index < count($tokens); $index++) {
            $text = $tokens[$index]['text'];

            if (in_array($text, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                $depth--;
            } elseif ($text === ';' && $depth === 0) {
                return $index;
            }

            if ($depth < 0) {
                return null;
            }
        }

        return null;
    }

    /**
     * The whitespace the chain's own calls are indented by, so the new call lines up
     * with them. Null when the chain is written on a single line, where the call is
     * appended inline instead.
     *
     * @param  array<int, array{id: int|null, text: string, offset: int}>  $tokens
     */
    private function chainIndentation(array $tokens, int $start, int $end): ?string
    {
        $indentation = null;
        $depth = 0;

        for ($index = $start; $index < $end; $index++) {
            $text = $tokens[$index]['text'];

            if (in_array($text, ['(', '[', '{'], true)) {
                $depth++;

                continue;
            }

            if (in_array($text, [')', ']', '}'], true)) {
                $depth--;

                continue;
            }

            // Only the chain's own calls are a guide; arguments nested inside them
            // are indented one level deeper.
            if ($depth !== 0 || $tokens[$index]['id'] !== T_OBJECT_OPERATOR) {
                continue;
            }

            $previous = $tokens[$index - 1] ?? null;

            if ($previous === null || $previous['id'] !== T_WHITESPACE || ! str_contains($previous['text'], "\n")) {
                continue;
            }

            $indentation = substr($previous['text'], strrpos($previous['text'], "\n") + 1);
        }

        return $indentation;
    }
}
