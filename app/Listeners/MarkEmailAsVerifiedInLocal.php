<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\App;

/**
 * DT-09: in the local environment there is no real mail delivery (MAIL_MAILER=log),
 * so new users are marked as verified on registration. Any other environment keeps
 * the standard email verification flow untouched.
 */
class MarkEmailAsVerifiedInLocal
{
    public function handle(Registered $event): void
    {
        if (! App::isLocal()) {
            return;
        }

        $user = $event->user;

        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }
    }
}
