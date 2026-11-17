<?php

namespace Database\Seeders;

use App\Models\Calendar;
use App\Models\Contract;
use App\Models\Project;
use App\Models\Sbi;
use App\Models\SbiReply;
use App\Models\SbiRevision;
use App\Models\User;
use App\Notifications\InAppNotification;
use App\Support\CalendarLeave;
use App\Support\CalendarNotificationRecipients;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ExpandedDemoSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $this->users();
            $this->call(ContractWorkingDaySeeder::class);
            $this->projects();
            $this->balancesAndRequests();
            $this->feedback();
        });
    }

    private function users(): void
    {
        $people = [
            ['Elena', 'Ferrari', 'female', 'HR Manager', 'Project Manager', 40],
            ['Matteo', 'Ricci', 'male', 'Project Manager', 'Project Manager', 40],
            ['Sara', 'Conti', 'female', 'Team Leader', 'Team Leader', 40],
            ['Davide', 'Romano', 'male', 'User', 'Sviluppatore Back-end', 40],
            ['Chiara', 'Gallo', 'female', 'User', 'Sviluppatore Front-end', 40],
            ['Alessandro', 'Greco', 'male', 'User', 'Sviluppatore Full-stack', 40],
            ['Francesca', 'Marino', 'female', 'User', 'UX-UI Designer', 20],
            ['Simone', 'Bruno', 'male', 'User', 'Sviluppatore Back-end', 40],
            ['Martina', 'Rizzo', 'female', 'User', 'Sviluppatore Front-end', 24],
            ['Federico', 'Lombardi', 'male', 'User', 'Scrum Master', 40],
            ['Alice', 'Moretti', 'female', 'User', 'UX-UI Designer', 40],
            ['Gabriele', 'Di Dio', 'male', 'User', 'Sviluppatore Full-stack', 40],
            ['Sofia', 'Esposito', 'female', 'User', 'Sviluppatore Back-end', 24],
            ['Marco', 'Fontana', 'male', 'User', 'Sviluppatore Front-end', 40],
            ['Valentina', 'Serra', 'female', 'User', 'UX-UI Designer', 20],
        ];
        $avatar = DB::table('filestorage')->where('name', 'default-avatar')->value('id');
        $password = Hash::make('Password123!');
        foreach ($people as $index => [$first, $last, $gender, $role, $assignment, $hours]) {
            $email = strtolower($first.'.'.str_replace(' ', '', $last)).'@example.com';
            $disabled = $index >= 13;
            $intern = in_array($index, [8, 12], true);
            $hired = $intern ? '2026-09-01' : '2023-03-06';
            $user = User::withTrashed()->updateOrCreate(['email' => $email], [
                'first_name' => $first, 'last_name' => $last, 'gender' => $gender,
                'azure_email' => $index % 3 === 0 ? null : str_replace('@example.com', '@azure.example.com', $email),
                'company_email' => $index % 4 === 0 ? null : str_replace('@example.com', '@company.example.com', $email),
                'password' => $password, 'remember_me' => false, 'birth_date' => (1988 + $index).'-04-12',
                'hiring_date' => $hired, 'resignation_date' => $disabled ? '2026-10-30' : null,
                'is_intern' => $intern, 'isAllocation_visible' => ! $disabled,
                'filestorage_id' => $avatar,
            ]);
            DB::table('users')->where('id', $user->id)->update(['deleted_at' => $disabled ? '2026-10-30 18:00:00' : null]);
            $user->roles()->sync([DB::table('roles')->where('alias', $role)->value('id')]);
            $user->assignmentRoles()->sync([DB::table('assignmentroles')->where('alias', $assignment)->value('id') => [
                'expertise' => $intern ? 'junior' : ($index % 3 === 0 ? 'senior' : 'junior'),
            ]]);
            $contract = Contract::where('user_id', $user->id)->whereDate('contract_start_date', $hired)->first()
                ?? new Contract(['user_id' => $user->id, 'contract_start_date' => $hired]);
            $contract->fill([
                'contract_end_date' => $disabled ? '2026-10-30' : null, 'weekly_hours' => $hours,
                'contract_type' => $intern ? 'tirocinio' : 'indeterminato', 'has_rol' => ! $intern,
            ])->save();
        }
    }

    private function assign(int $project, int $user, string $role, int $allocation, string $type = 'project'): void
    {
        DB::table('project_user')->updateOrInsert([
            'project_id' => $project, 'user_id' => $user,
            'assignmentrole_id' => DB::table('assignmentroles')->where('alias', $role)->value('id'),
            'startwork_date' => '2026-09-01',
        ], ['allocation_type' => $type, 'endwork_date' => null, 'work_allocation' => $allocation,
            'created_at' => now(), 'updated_at' => now()]);
    }

    private function projects(): void
    {
        $users = User::all()->keyBy('email');
        $template = (array) DB::table('projects')->where('alias', 'Portale Clienti')->where('is_timesheet', false)->first();
        unset($template['id'], $template['managed_alias']);
        $definitions = [
            ['App Assistenza', 'Applicazione per la gestione delle richieste di assistenza.', 'in corso', 'alta'],
            ['Portale HR', 'Portale per servizi e comunicazioni dedicati alle persone.', 'in corso', 'media'],
            ['Dashboard Vendite', 'Analisi e visualizzazione dei risultati commerciali.', 'in corso', 'bassa'],
            ['Catalogo Digitale', 'Catalogo dei prodotti con ricerca e schede di dettaglio.', 'manutenzione', 'media'],
            ['Area Partner', 'Area riservata per la collaborazione con i partner.', 'in corso', 'alta'],
            ['Archivio Documentale', 'Organizzazione e consultazione dei documenti aziendali.', 'concluso', 'bassa'],
        ];
        $developers = ['davide.romano', 'chiara.gallo', 'alessandro.greco', 'francesca.marino',
            'simone.bruno', 'martina.rizzo', 'federico.lombardi', 'alice.moretti', 'gabriele.didio', 'sofia.esposito'];
        foreach ($definitions as $i => [$name, $description, $status, $priority]) {
            $manager = $users[($i < 3 ? 'laura.bianchi' : 'matteo.ricci').'@example.com'];
            $leader = $users[($i < 3 ? 'luca.verdi' : 'sara.conti').'@example.com'];
            DB::table('projects')->updateOrInsert(['alias' => $name, 'is_timesheet' => false, 'is_course' => false], [
                ...$template, 'alias' => $name, 'description' => $description, 'status' => $status,
                'priority' => $priority, 'project_manager_id' => $manager->id, 'team_leader_id' => $leader->id,
                'scrum_master_id' => $leader->id, 'azure_project_name' => $name, 'azure_team_name' => 'Team '.$name,
                'start_date' => '2026-09-01', 'end_date' => $status === 'concluso' ? '2026-10-30' : null,
                'bug_count_url' => null, 'note' => 'Dati dimostrativi per la presentazione.',
            ]);
            $project = Project::where('alias', $name)->firstOrFail();
            foreach (['Project Manager' => $manager->id, 'Team Leader' => $leader->id, 'Scrum Master' => $leader->id] as $role => $id) {
                $this->assign($project->id, $id, $role, 0);
            }
            foreach ([$i % 10, ($i + 4) % 10] as $offset) {
                $person = $users[$developers[$offset].'@example.com'];
                $this->assign($project->id, $person->id, $person->assignmentRoles()->firstOrFail()->alias, 40);
            }
            DB::table('necessities')->updateOrInsert(['project_id' => $project->id,
                'assignmentrole_id' => DB::table('assignmentroles')->where('alias', 'UX-UI Designer')->value('id')],
                ['allocation_baseline_id' => DB::table('project_user')->max('id'), 'created_at' => now(), 'updated_at' => now()]);
        }
        // One intentional overload makes the warning visible during the demo.
        $davide = $users['davide.romano@example.com'];
        foreach (['App Assistenza' => 70, 'Area Partner' => 50] as $alias => $percent) {
            $this->assign(Project::where('alias', $alias)->value('id'), $davide->id, 'Sviluppatore Back-end', $percent);
        }
        foreach (['Formazione interna', 'Supporto organizzativo'] as $index => $alias) {
            $project = Project::updateOrCreate(['alias' => $alias, 'is_timesheet' => true, 'is_course' => false], [
                'description' => 'Attività interne dimostrative.', 'is_daily' => false, 'is_azure' => false, 'is_ux_ui' => false,
                'filestorage_id' => $template['filestorage_id'],
            ]);
            foreach (array_slice($developers, $index * 3, 3) as $email) {
                $person = $users[$email.'@example.com'];
                $this->assign($project->id, $person->id, $person->assignmentRoles()->firstOrFail()->alias, 0, 'timesheet');
            }
        }
        $monday = CarbonImmutable::now('Europe/Rome')->startOfWeek();
        foreach (Project::where('is_timesheet', false)->where('is_course', false)->get() as $project) {
            foreach ([10 => -2, 11 => 0, 12 => 2] as $number => $weeks) {
                $start = $monday->addWeeks($weeks);
                $concluded = $weeks < 0 || $project->status === 'concluso';
                $sprint = ['start_date' => $start->toDateString(), 'end_date' => $start->addDays(11)->toDateString(),
                    'teamcategory_id' => DB::table('teamcategories')->where('alias', 'Web Development')->value('id'),
                    'is_concluded' => $concluded, 'is_pilot' => $number === 10, 'is_billing' => false,
                    'has_csv' => false, 'is_planningdone' => true, 'is_estimatedone' => $weeks <= 0, 'is_reviewdone' => $concluded,
                    'created_at' => now(), 'updated_at' => now()];
                if ($project->status === 'concluso') {
                    $sprint['start_date'] = '2026-09-'.($number === 10 ? '01' : ($number === 11 ? '14' : '28'));
                    $sprint['end_date'] = CarbonImmutable::parse($sprint['start_date'])->addDays(11)->toDateString();
                }
                DB::table('sprints')->updateOrInsert(['project_id' => $project->id, 'number' => $number], $sprint);
            }
        }
    }

    private function balancesAndRequests(): void
    {
        $month = now('Europe/Rome')->startOfMonth()->toDateString();
        foreach (User::all() as $person) {
            $contract = $person->contracts()->firstOrFail();
            foreach (['ferie' => 80, 'permesso' => 24, 'rol' => 16] as $type => $hours) {
                if ($type === 'rol' && ! $contract->has_rol) {
                    continue;
                }
                $ratio = $contract->weekly_hours / 40;
                DB::table('user_leave_balances')->updateOrInsert(['user_id' => $person->id, 'leave_type' => $type, 'reference_month' => $month],
                    ['reference_date' => $type === 'ferie' ? null : $month, 'remaining_hours' => $hours * $ratio,
                        'accrued_hours' => ($hours + 16) * $ratio, 'monthly_accrual' => ($type === 'ferie' ? 13.33 : 4) * $ratio,
                        'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $monday = CarbonImmutable::now('Europe/Rome')->addWeek()->startOfWeek();
        foreach (['mario.rossi', 'laura.bianchi', 'giulia.neri', 'davide.romano', 'chiara.gallo', 'gabriele.didio', 'sara.conti', 'alessandro.greco'] as $i => $email) {
            $person = User::where('email', $email.'@example.com')->firstOrFail();
            $type = $i % 2 === 0 ? 'Ferie' : 'Permesso';
            $date = $monday->addDays($i % 5)->toDateString();
            Calendar::updateOrCreate(['user_id' => $person->id, 'notes' => 'Demo presentazione: richiesta '.($i + 1)], [
                'typology_id' => DB::table('calendar_typology')->where('alias', $type)->value('id'),
                'start_date' => $date, 'end_date' => $date, 'start_time' => $type === 'Permesso' ? '10:00' : null,
                'end_time' => $type === 'Permesso' ? '12:00' : null,
                'status' => $i < 4 ? 'pending' : ($i < 7 ? 'approved' : 'rejected'), 'is_smart' => false,
            ]);
        }
        // Rebuild the current demo saldo from its opening amount on every seed run.
        foreach (Calendar::with(['user', 'typology'])->whereHas('user')->get() as $entry) {
            if (! in_array($entry->typology->alias, ['Ferie', 'Permesso'], true)) {
                continue;
            }
            if ($entry->status === 'approved') {
                $hours = CalendarLeave::hours($entry->user, $entry->typology->alias, $entry->start_date->toDateString(),
                    $entry->end_date->toDateString(), $entry->start_time, $entry->end_time);
                CalendarLeave::availableBalance($entry->user, $entry->typology->alias, $hours)->decrement('remaining_hours', $hours);
            }
            if ($entry->status === 'pending') {
                foreach (CalendarNotificationRecipients::forRequester($entry->user_id)->get() as $recipient) {
                    $this->notification($recipient, new InAppNotification('calendar', 'Nuova richiesta di '.strtolower($entry->typology->alias),
                        $entry->user_id, $entry->id), 'calendar-'.$entry->id);
                }
            }
        }
    }

    private function feedback(): void
    {
        $projects = Project::where('is_timesheet', false)->where('is_course', false)->orderBy('id')->get();
        foreach ($projects as $i => $project) {
            $receiver = $project->projectUsers()->whereHas('assignmentRole', fn ($query) => $query->whereNotIn('alias', ['Project Manager', 'Team Leader', 'Scrum Master']))->firstOrFail()->user;
            foreach ([0, 1] as $offset) {
                if ($i < 2 && $offset === 1) {
                    continue;
                }
                $writer = $offset === 0 ? $project->project_manager_id : $project->team_leader_id;
                // Existing feedback pairs are left intact.
                $sbi = Sbi::firstOrCreate(['writer_id' => $writer, 'receiver_id' => $receiver->id, 'project_id' => $project->id]);
                if ($sbi->revisions()->exists()) {
                    continue;
                }
                $positive = ($i + $offset) % 3 !== 0;
                $sent = now()->subDays(2 + $i + $offset);
                $revision = SbiRevision::create(['sbi_id' => $sbi->id, 'revision_number' => 1, 'is_positive' => $positive,
                    'event_date' => $sent->toDateString(), 'situation' => 'Durante la revisione delle attività di '.$project->alias.'.',
                    'behavior' => $positive ? 'Hai condiviso una soluzione chiara e aiutato i colleghi a completare le attività.' : 'Hai segnalato un impedimento solo al termine della giornata.',
                    'impact' => $positive ? 'Il team ha completato la consegna con maggiore sicurezza.' : 'Il team ha dovuto riorganizzare le attività previste.',
                    'suggestion' => $positive ? 'Continua a condividere esempi e conoscenze con il team.' : 'Condividi i blocchi appena li individui, così possiamo aiutarti.',
                    'personal_notes' => 'Nota privata dimostrativa del mittente.', 'viewed_at' => $i % 2 === 0 ? $sent : null,
                    'created_at' => $sent, 'updated_at' => $sent]);
                $sbi->update(['created_at' => $sent]);
                $this->notification($receiver, new InAppNotification('sbi', 'Hai ricevuto un nuovo SBI.', $writer, sbiId: $sbi->id), 'sbi-'.$sbi->id);
                if ($i % 2 === 0) {
                    SbiReply::create(['sbi_revision_id' => $revision->id, 'body' => 'Grazie, terrò conto del feedback nelle prossime attività.']);
                    $this->notification(User::findOrFail($writer), new InAppNotification('sbi', 'Hai ricevuto una risposta al tuo SBI.', $receiver->id,
                        sbiId: $sbi->id, sbiReply: true), 'reply-'.$sbi->id);
                }
            }
        }
    }

    private function notification(User $recipient, InAppNotification $notification, string $key): void
    {
        $hash = md5('stage-demo-'.$recipient->id.'-'.$key);
        $id = substr($hash, 0, 8).'-'.substr($hash, 8, 4).'-4'.substr($hash, 13, 3).'-a'.substr($hash, 17, 3).'-'.substr($hash, 20, 12);
        DB::table('notifications')->updateOrInsert(['id' => $id], ['type' => InAppNotification::class,
            'notifiable_type' => $recipient->getMorphClass(), 'notifiable_id' => $recipient->id,
            'data' => json_encode($notification->toDatabase($recipient), JSON_THROW_ON_ERROR),
            'read_at' => null, 'created_at' => now(), 'updated_at' => now()]);
    }
}
