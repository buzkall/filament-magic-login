<?php

namespace Arzcode\FilamentMagicLogin\Support;

/**
 * Adds Laravel's pruner for our token model to an application's console routes.
 *
 * Like PackageReferenceRemover it works on the token stream and refuses to guess:
 * anything it cannot rewrite with certainty comes back as null, and the caller
 * prints the snippet for the developer to paste instead.
 */
final class ScheduleWriter extends SourceWriter
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
        $result = $this->withImports($code, [self::SCHEDULE_CLASS, self::MODEL_CLASS]);

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
}
