<?php

use Illuminate\Support\Arr;

$locales = ['en', 'es', 'ca'];

function localeKeys(string $locale): array
{
    $path = __DIR__."/../../resources/lang/{$locale}/filament-magic-login.php";

    expect($path)->toBeFile();

    return array_keys(Arr::dot(require $path));
}

it('ships identical translation keys in every locale', function () use ($locales): void {
    $reference = localeKeys('en');

    sort($reference);

    foreach ($locales as $locale) {
        $keys = localeKeys($locale);
        sort($keys);

        expect($keys)->toBe($reference, "Locale [{$locale}] has a different key set to [en].");
    }
});

it('translates every key to a non-empty string', function (string $locale): void {
    $path = __DIR__."/../../resources/lang/{$locale}/filament-magic-login.php";

    foreach (Arr::dot(require $path) as $key => $value) {
        expect($value)->toBeString()->not->toBe('', "Key [{$key}] is empty in [{$locale}].");
    }
})->with($locales);

it('resolves the shipped keys through the translator', function (string $locale): void {
    app()->setLocale($locale);

    expect(__('filament-magic-login::filament-magic-login.actions.magic_link'))
        ->not->toContain('filament-magic-login::')
        ->and(__('filament-magic-login::filament-magic-login.mail.intro', ['minutes' => 15]))
        ->toContain('15');
})->with($locales);

// Guard, not a parser: user-facing setters must never receive a bare literal.
it('has no untranslated literal strings in src', function (): void {
    $offenders = [];

    $methods = [
        'label', 'title', 'body', 'subject', 'line', 'action', 'tooltip', 'placeholder', 'helperText',
        // Console output is user-facing too.
        'info', 'warn', 'error', 'comment', 'confirm', 'ask', 'setDescription',
    ];
    $pattern = '/->('.implode('|', $methods).')\(\s*[\'"]/';

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../src')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        foreach (file($file->getPathname()) as $number => $line) {
            if (preg_match($pattern, $line)) {
                $offenders[] = $file->getFilename().':'.($number + 1).' — '.trim($line);
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('has no untranslated exception messages in src', function (): void {
    $offenders = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../src')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        foreach (file($file->getPathname()) as $number => $line) {
            if (preg_match('/throw new LogicException\(\s*[\'"]/', $line)) {
                $offenders[] = $file->getFilename().':'.($number + 1).' — '.trim($line);
            }
        }
    }

    expect($offenders)->toBe([]);
});
