<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

class CreateUser extends Command
{
    protected $signature = 'app:create-user';

    protected $description = 'Создать пользователя';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Запустите команду интерактивно для безопасного ввода пароля.');

            return self::FAILURE;
        }

        $name = $this->ask('Имя');
        $email = $this->ask('Email');
        $password = $this->secret('Пароль (минимум 12 символов)');
        $confirmation = $this->secret('Повторите пароль');

        $data = [
            'name' => is_string($name) ? trim($name) : $name,
            'email' => is_string($email) ? mb_strtolower(trim($email)) : $email,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ];
        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = new User;
        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->password = $data['password']; // User's hashed cast handles hashing.
        $user->email_verified_at = Carbon::now();
        $user->save();

        $this->info("Пользователь {$user->email} создан.");

        return self::SUCCESS;
    }
}
