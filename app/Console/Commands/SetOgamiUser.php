<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SetOgamiUser extends Command
{
    protected $signature = 'ogami:user
        {username : Approved sign-in username}
        {--email= : Existing account email to preserve its workbook history}
        {--name= : Display name for this account}';

    protected $description = 'Create or update an approved username and password account';

    public function handle(): int
    {
        $username = Str::lower(Str::squish((string) $this->argument('username')));
        if (mb_strlen($username) < 3 || mb_strlen($username) > 80 || ! preg_match('/^[\pL\pN][\pL\pN ._-]*$/u', $username)) {
            $this->error('Use 3 to 80 letters, numbers, spaces, dots, underscores, or hyphens for the username.');
            return self::FAILURE;
        }

        $email = Str::lower(trim((string) $this->option('email')));
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('The email option must be a valid email address.');
            return self::FAILURE;
        }

        $byUsername = User::query()->where('username', $username)->first();
        $byEmail = $email === '' ? null : User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        if ($byUsername && $byEmail && $byUsername->isNot($byEmail)) {
            $this->error('That username and email already belong to different accounts.');
            return self::FAILURE;
        }

        $user = $byEmail ?? $byUsername;
        if (! $user && $email === '') {
            $legacyAccounts = User::query()->whereNotNull('google_id')->whereNull('username')->limit(2)->get();
            if ($legacyAccounts->count() > 1) {
                $this->error('Several existing Google accounts were found. Pass --email=... to preserve the correct account history.');
                return self::FAILURE;
            }
            $user = $legacyAccounts->first();
        }
        $user ??= new User;
        if ($email === '') {
            $email = $user->email ?: 'ogami+'.Str::slug($username).'@accounts.ogami.local';
        }
        $emailOwner = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        if ($emailOwner && $emailOwner->isNot($user)) {
            $this->error('That email is already assigned to another account.');
            return self::FAILURE;
        }

        $password = $this->secret('Password (12 characters minimum)');
        $confirmation = $this->secret('Confirm password');
        if (! is_string($password) || mb_strlen($password) < 12 || ! hash_equals($password, (string) $confirmation)) {
            $this->error('Passwords must match and contain at least 12 characters. No account was changed.');
            return self::FAILURE;
        }

        $user->username = $username;
        $user->email = $email;
        $user->name = trim((string) $this->option('name')) ?: ($user->name ?: $username);
        $user->email_verified_at ??= now();
        $user->google_id = null;
        $user->password = $password;
        $user->save();

        $this->info('Approved sign-in account saved. The password is stored as a secure hash.');
        return self::SUCCESS;
    }
}
