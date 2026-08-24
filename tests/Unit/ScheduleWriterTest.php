<?php

use Arzcode\FilamentMagicLogin\Support\ScheduleWriter;

beforeEach(function (): void {
    $this->writer = new ScheduleWriter;
});

it('appends the pruner below the existing imports', function (): void {
    $code = <<<'PHP'
    <?php

    use Illuminate\Foundation\Inspiring;
    use Illuminate\Support\Facades\Artisan;

    Artisan::command('inspire', function () {
        $this->comment(Inspiring::quote());
    })->purpose('Display an inspiring quote');
    PHP;

    $result = $this->writer->add($code);

    expect($result)->not->toBeNull()
        ->and($this->writer->isParsable($result))->toBeTrue()
        // The new imports land with the others, not at the end of the file.
        ->and(strpos($result, 'use Illuminate\Support\Facades\Schedule;'))
        ->toBeLessThan(strpos($result, "Artisan::command('inspire'"))
        ->and($result)->toContain("Schedule::command('model:prune'")
        ->and($result)->toEndWith("])->daily();\n");
});

it('handles a file with no imports at all', function (): void {
    $result = $this->writer->add("<?php\n");

    expect($result)->not->toBeNull()
        ->and($this->writer->isParsable($result))->toBeTrue()
        ->and($result)->toContain('use Illuminate\Support\Facades\Schedule;')
        ->and($result)->toContain("Schedule::command('model:prune'");
});

it('reuses an import the file already has', function (): void {
    $code = "<?php\n\nuse Illuminate\\Support\\Facades\\Schedule;\n";

    $result = $this->writer->add($code);

    expect(substr_count((string) $result, 'use Illuminate\Support\Facades\Schedule;'))->toBe(1)
        ->and($this->writer->isParsable($result))->toBeTrue();
});

it('is not fooled by a closure\'s use clause', function (): void {
    $code = <<<'PHP'
    <?php

    use Illuminate\Support\Facades\Artisan;

    $name = 'inspire';

    Artisan::command($name, function () use ($name) {
        //
    });
    PHP;

    $result = $this->writer->add($code);

    // The import goes with the real import, not after the closure's `use ($name)`.
    expect($result)->not->toBeNull()
        ->and($this->writer->isParsable($result))->toBeTrue()
        ->and(strpos($result, 'use Arzcode\FilamentMagicLogin\Models\MagicLoginToken;'))
        ->toBeLessThan(strpos($result, '$name = '));
});

it('refuses to write when another class already owns the short name', function (): void {
    // Importing ours as well would be a fatal "name already in use".
    $code = "<?php\n\nuse Illuminate\\Console\\Scheduling\\Schedule;\n";

    expect($this->writer->add($code))->toBeNull();
});

it('ignores function and const imports when reading names', function (): void {
    $code = "<?php\n\nuse function Laravel\\Prompts\\confirm;\nuse const PHP_EOL;\n";

    $result = $this->writer->add($code);

    expect($result)->not->toBeNull()
        ->and($this->writer->isParsable($result))->toBeTrue()
        ->and($result)->toContain('use Illuminate\Support\Facades\Schedule;');
});

it('refuses to write to a file that does not parse', function (): void {
    expect($this->writer->add('<?php class {'))->toBeNull();
});

it('detects an existing pruner whatever shape it was written in', function (): void {
    expect($this->writer->isScheduled("Schedule::command('model:prune', ['--model' => [MagicLoginToken::class]]);"))
        ->toBeTrue()
        ->and($this->writer->isScheduled('$schedule->command("model:prune --model=\\\\Arzcode\\\\FilamentMagicLogin\\\\Models\\\\MagicLoginToken");'))
        ->toBeTrue()
        ->and($this->writer->isScheduled("Schedule::command('model:prune', ['--model' => [Order::class]]);"))
        ->toBeFalse()
        ->and($this->writer->isScheduled("<?php\n"))->toBeFalse();
});
