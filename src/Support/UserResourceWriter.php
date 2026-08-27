<?php

namespace Arzcode\FilamentMagicLogin\Support;

/**
 * Adds the "send a login link" action to a Filament resource: once to the table's
 * record actions, and once to each record page's header actions.
 *
 * Holds itself to the same standard as PluginRegistrationWriter — a file whose shape
 * cannot be rewritten with certainty comes back as null, so the caller can print the
 * snippet and let a person place it by hand.
 */
final class UserResourceWriter extends SourceWriter
{
    public const ACTION_CLASS = 'Arzcode\\FilamentMagicLogin\\Actions\\SendMagicLinkAction';

    private const RECORD_ACTIONS = 'recordActions';

    private const HEADER_ACTIONS = 'getHeaderActions';

    /**
     * Wired if the file mentions the action at all, whichever way it was written.
     */
    public function isWired(string $code): bool
    {
        return str_contains($code, $this->shortName(self::ACTION_CLASS));
    }

    /**
     * The fully qualified name of the class a file declares, so the installer can map
     * an application's source without guessing at PSR-4 roots.
     */
    public function declaredClass(string $code): ?string
    {
        if (! $this->isParsable($code)) {
            return null;
        }

        $tokens = $this->scan($code);
        $namespace = null;
        $class = null;

        foreach ($tokens as $index => $token) {
            if ($token['id'] === T_NAMESPACE && $namespace === null) {
                $namespace = $this->readName($tokens, $index + 1);
            }

            // T_CLASS also fires for `Foo::class`, which is preceded by `::`.
            if ($token['id'] === T_CLASS && $class === null) {
                $previous = $this->nextMeaningfulBefore($tokens, $index - 1, -1);

                if ($previous !== null && $tokens[$previous]['id'] === T_DOUBLE_COLON) {
                    continue;
                }

                $next = $this->nextMeaningful($tokens, $index + 1);

                if ($next !== null && $tokens[$next]['id'] === T_STRING) {
                    $class = $tokens[$next]['text'];
                }
            }
        }

        if ($class === null) {
            return null;
        }

        return $namespace === null ? $class : $namespace.'\\'.$class;
    }

    /**
     * The classes a file imports, so the installer can follow a resource to the table
     * class it hands off to without guessing at PSR-4 roots.
     *
     * @return array<int, string>
     */
    public function importedClasses(string $code): array
    {
        return array_values($this->importedAliases($code));
    }

    /**
     * Whether this file is a Filament resource for the given model, read from its own
     * `$model` declaration and resolved through its imports.
     */
    public function isResourceFor(string $code, string $model): bool
    {
        if (! str_contains($code, 'extends Resource') && ! str_contains($code, 'extends \\Filament\\Resources\\Resource')) {
            return false;
        }

        $declared = $this->declaredModel($code);

        return $declared !== null && ltrim($declared, '\\') === ltrim($model, '\\');
    }

    /**
     * Whether this file is a View or Edit page belonging to the given resource.
     *
     * Matched structurally rather than by reading the resource's `getPages()`, which
     * can be written a dozen ways.
     */
    public function isRecordPageFor(string $code, string $resource): bool
    {
        $extendsRecordPage = str_contains($code, 'extends ViewRecord')
            || str_contains($code, 'extends EditRecord')
            || str_contains($code, 'extends \\Filament\\Resources\\Pages\\ViewRecord')
            || str_contains($code, 'extends \\Filament\\Resources\\Pages\\EditRecord');

        if (! $extendsRecordPage) {
            return false;
        }

        $declared = $this->declaredProperty($code, 'resource');

        return $declared !== null && ltrim($declared, '\\') === ltrim($resource, '\\');
    }

    /**
     * Whether the file holds a table this writer can append to. Used to tell "this is
     * the wrong file" apart from "this is the right file, written a way we cannot edit".
     */
    public function hasRecordActions(string $code): bool
    {
        return str_contains($code, self::RECORD_ACTIONS.'(');
    }

    public function addRecordAction(string $code): ?string
    {
        return $this->add($code, fn (array $tokens): ?array => $this->findMethodCallArrayLiteral($tokens, self::RECORD_ACTIONS));
    }

    public function addHeaderAction(string $code): ?string
    {
        return $this->add($code, fn (array $tokens): ?array => $this->findReturnedArrayLiteral($tokens, self::HEADER_ACTIONS));
    }

    public function block(): string
    {
        return sprintf('%s::make()', $this->shortName(self::ACTION_CLASS));
    }

    /**
     * The action with its import, for printing when no file can be edited.
     */
    public function snippet(): string
    {
        return sprintf(
            "use %s;\n\n// In the resource's table:\n->%s([\n    %s,\n])\n\n"
            ."// And in getHeaderActions() on the View and Edit pages:\nreturn [\n    %s,\n];",
            self::ACTION_CLASS,
            self::RECORD_ACTIONS,
            $this->block(),
            $this->block(),
        );
    }

    /**
     * @param  callable(array<int, array{id: int|null, text: string, offset: int}>): (array{0: int, 1: int}|null)  $locate
     */
    private function add(string $code, callable $locate): ?string
    {
        if (! $this->isParsable($code) || $this->isWired($code)) {
            return null;
        }

        $result = $this->withImports($code, [self::ACTION_CLASS]);

        if ($result === null) {
            return null;
        }

        $bounds = $locate($this->scan($result));

        if ($bounds === null) {
            return null;
        }

        [$open, $close] = $bounds;

        $result = $this->appendArrayElement($result, $this->scan($result), $open, $close, $this->block());

        return $this->isParsable($result) ? $result : null;
    }

    /**
     * The class a `static ?string $model = X::class;` declaration points at, resolved
     * through the file's own imports.
     */
    private function declaredModel(string $code): ?string
    {
        return $this->declaredProperty($code, 'model');
    }

    /**
     * The class named by `static ... $name = X::class;`, resolved through imports.
     */
    private function declaredProperty(string $code, string $property): ?string
    {
        if (! $this->isParsable($code)) {
            return null;
        }

        $tokens = $this->scan($code);
        $aliases = $this->importedAliases($code);

        foreach ($tokens as $index => $token) {
            if ($token['id'] !== T_VARIABLE || $token['text'] !== '$'.$property) {
                continue;
            }

            $equals = $this->nextMeaningful($tokens, $index + 1);

            if ($equals === null || $tokens[$equals]['text'] !== '=') {
                continue;
            }

            $name = $this->readName($tokens, $equals + 1);

            if ($name === null) {
                continue;
            }

            $next = $this->nextMeaningful($tokens, $this->indexAfterName($tokens, $equals + 1));

            // Only the `X::class` form is understood; a string literal or a call is not.
            if ($next === null || $tokens[$next]['id'] !== T_DOUBLE_COLON) {
                continue;
            }

            return $aliases[$name] ?? $name;
        }

        return null;
    }

    /**
     * The dotted-free class name starting at $index, joining the parts PHP splits a
     * qualified name into.
     *
     * @param  array<int, array{id: int|null, text: string, offset: int}>  $tokens
     */
    private function readName(array $tokens, int $index): ?string
    {
        $start = $this->nextMeaningful($tokens, $index);

        if ($start === null) {
            return null;
        }

        $ids = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR];
        $name = '';

        for ($current = $start; $current < count($tokens); $current++) {
            if (! in_array($tokens[$current]['id'], $ids, true)) {
                break;
            }

            $name .= $tokens[$current]['text'];
        }

        return $name === '' ? null : ltrim($name, '\\');
    }

    /**
     * @param  array<int, array{id: int|null, text: string, offset: int}>  $tokens
     */
    private function indexAfterName(array $tokens, int $index): int
    {
        $start = $this->nextMeaningful($tokens, $index) ?? $index;
        $ids = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR];

        for ($current = $start; $current < count($tokens); $current++) {
            if (! in_array($tokens[$current]['id'], $ids, true)) {
                return $current;
            }
        }

        return count($tokens);
    }
}
