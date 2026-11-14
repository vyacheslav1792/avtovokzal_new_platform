<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\Role;
use App\Rules\StrongPassword;
use App\Services\EmployeePresenter;
use App\Support\AisLogin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Первый администратор АИС и сброс его пароля.
 *
 * На пустой системе войти ещё некому, поэтому самого первого администратора
 * заводит эта команда; дальше администраторов создаёт и сбрасывает любой
 * администратор прямо в АИС. Ей же выручают систему, когда пароль
 * единственного администратора потерян.
 */
class AisAdminCommand extends Command
{
    protected $signature = 'ais:admin
        {login : Логин (регистр и пробелы по краям не важны, русские буквы можно)}
        {--password= : Пароль; без него будет сгенерирован и показан}
        {--name= : ФИО}
        {--email= : E-mail}
        {--code= : Код подтверждения опасных действий (3–20 знаков)}
        {--token : Выдать токен быстрого входа и показать его}
        {--no-admin-role : Не назначать роль администратора (только создать или сбросить пароль)}';

    protected $description = 'Создать администратора АИС или сбросить ему пароль';

    public function handle(EmployeePresenter $presenter): int
    {
        $login = (string) $this->argument('login');
        $normalized = AisLogin::normalize($login);

        if ($normalized === '') {
            $this->error('Логин пустой.');

            return self::FAILURE;
        }

        $employee = Employee::where('login_normalized', $normalized)->first();
        $existed = $employee !== null;

        // Пароль без --password придумываем сами: это и надёжнее, и быстрее,
        // чем подбирать проходящий проверку вручную.
        $password = (string) ($this->option('password') ?? '');
        $generated = $password === '';
        if ($generated) {
            $password = $this->generatePassword();
        }

        $validator = Validator::make(['password' => $password], [
            'password' => [new StrongPassword([$login, (string) $this->option('name')])],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $employee ??= new Employee;
        $employee->setLogin($login);
        $employee->password = trim($password);
        $employee->is_active = true;

        if ($this->option('name') !== null) {
            $employee->full_name = trim((string) $this->option('name'));
        }

        if ($this->option('email') !== null) {
            $employee->email = trim((string) $this->option('email')) ?: null;
        }

        $employee->full_name = $employee->full_name ?: 'Администратор АИС';
        $employee->save();

        $code = (string) ($this->option('code') ?? '');
        if ($code !== '') {
            $employee->confirm_code = $code;
            $employee->save();
        }

        if (! $this->option('no-admin-role')) {
            $role = Role::firstOrCreate(
                ['is_system' => true],
                ['name' => 'Администратор', 'description' => 'Полный доступ ко всем модулям АИС', 'permissions' => []],
            );
            $employee->roles()->syncWithoutDetaching([$role->id]);
        }

        $token = $this->option('token') ? $presenter->setFastToken($employee, null) : null;

        // Пароль меняют, когда прежний мог утечь: старые сессии гасим.
        $employee->tokens()->delete();

        $this->newLine();
        $this->info($existed ? 'Пароль администратора сброшен.' : 'Администратор создан.');
        $this->newLine();
        $this->line('  Логин:  '.$employee->login);
        $this->line('  Пароль: '.trim($password).($generated ? '  (сгенерирован)' : ''));

        if ($code !== '') {
            $this->line('  Код подтверждения: '.$code);
        } elseif ($employee->confirm_code === null) {
            $this->newLine();
            $this->warn('Код подтверждения опасных действий не задан — без него не пройдут возврат билета и удаления.');
            $this->warn('Задайте его в профиле АИС или запустите команду с --code=…');
        }

        if ($token !== null) {
            $this->newLine();
            $this->line('  Токен быстрого входа: '.$token);
            $this->line('  Ссылка входа: /?token='.$token.'  (действует только из рабочей сети)');
        }

        $this->newLine();
        $this->warn('Смените пароль после первого входа: сейчас он есть в истории команд и в этом выводе.');

        return self::SUCCESS;
    }

    /**
     * Парольная фраза из несвязанных слов: её проверка стойкости принимает,
     * а человек в состоянии прочитать её вслух по телефону.
     */
    private function generatePassword(): string
    {
        $words = [
            'вокзал', 'перрон', 'ромашка', 'кедр', 'янтарь', 'корица', 'лимон',
            'север', 'компас', 'парус', 'снегирь', 'фонарь', 'ветер', 'терем',
            'камыш', 'бубен', 'сокол', 'шершень', 'оникс', 'барсук', 'тополь',
        ];

        do {
            $password = implode('-', [
                $words[random_int(0, count($words) - 1)],
                $words[random_int(0, count($words) - 1)],
                $words[random_int(0, count($words) - 1)],
                (string) random_int(10, 99),
            ]);

            $valid = Validator::make(['password' => $password], [
                'password' => [new StrongPassword],
            ])->passes();
        } while (! $valid);

        return $password;
    }
}
