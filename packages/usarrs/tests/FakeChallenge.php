<?php

declare(strict_types=1);

namespace Marque\Usarrs\Tests;

use Illuminate\Http\Request;
use Marque\Usarrs\Contracts\Challenge;

/** A Challenge that passes only 'right-answer', and records every verify() call. */
class FakeChallenge implements Challenge
{
    /** @var list<array{answer: string, form: string}> */
    public array $verified = [];

    /** @param list<string> $forms */
    public function __construct(public array $forms = [self::REGISTER, self::LOGIN, self::PASSWORD_RESET]) {}

    public function protects(string $form): bool
    {
        return in_array($form, $this->forms, true);
    }

    public function widget(string $form): string
    {
        return '<div class="fake-challenge" data-form="'.$form.'"></div>';
    }

    public function verify(string $answer, string $form, Request $request): bool
    {
        $this->verified[] = ['answer' => $answer, 'form' => $form];

        return $answer === 'right-answer';
    }
}
