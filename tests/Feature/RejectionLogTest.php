<?php

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->logged = collect();

    Event::listen(MessageLogged::class, fn (MessageLogged $message) => $this->logged->push($message));
});

it('logs a request the login page stayed silent about', function (): void {
    requestLink('nobody@example.com');

    expect($this->logged)->toHaveCount(1)
        ->and($this->logged->first()->level)->toBe('info')
        ->and($this->logged->first()->context)->toMatchArray([
            'reason' => 'unknown_user',
            'email' => 'nobody@example.com',
            'panel' => 'admin',
        ]);
});

it('logs nothing when told not to', function (): void {
    config()->set('filament-magic-login.log_rejections.enabled', false);

    requestLink('nobody@example.com');

    expect($this->logged)->toBeEmpty();
});

it('logs nothing for a link that was sent', function (): void {
    requestLink(makeUser()->email);

    expect($this->logged)->toBeEmpty();
});
