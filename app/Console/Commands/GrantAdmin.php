<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:grant-admin {email : The email address of an existing user}')]
#[Description('Let a user see the admin report')]
class GrantAdmin extends Command
{
    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error('No user has that email address.');

            return self::FAILURE;
        }

        $user->forceFill(['is_admin' => true])->save();

        $this->info("{$user->email} can now see the admin report.");

        return self::SUCCESS;
    }
}
