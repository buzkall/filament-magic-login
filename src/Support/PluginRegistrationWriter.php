<?php

namespace Arzcode\FilamentMagicLogin\Support;

/**
 * Registers the plugin on a Filament panel provider, by appending `->plugin(...)`
 * to the `$panel` chain the provider returns.
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
        return sprintf('->plugin(%s::make())', $this->shortName(self::PLUGIN_CLASS));
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
     * Appends the call to the chain that is returned, just before its semicolon.
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
