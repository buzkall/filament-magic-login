<?php

namespace Arzcode\FilamentMagicLogin\Enums;

enum MagicLinkPosition
{
    /**
     * Rendered as a link underneath the "Sign in" button.
     */
    case BelowForm;

    /**
     * Rendered as an icon button on the email field's hint.
     */
    case EmailFieldHint;
}
