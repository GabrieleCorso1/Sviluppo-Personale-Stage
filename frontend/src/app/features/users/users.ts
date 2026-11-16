import { DatePipe, DecimalPipe } from '@angular/common';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import {
  AfterViewInit,
  Component,
  DestroyRef,
  Directive,
  ElementRef,
  computed,
  inject,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { Layout } from '../../components/layout/layout';
import { API_URL } from '../../core/auth-http';
import { AuthenticatedImage } from '../../core/authenticated-image';
import { Auth } from '../../core/services/auth';
import { ProfileAvatar } from '../../core/services/profile-avatar';

interface Option {
  id: number;
  alias: string;
  pivot?: { expertise: string };
}
interface WorkingDay {
  day_of_week: string;
  start_time: string | null;
  end_time: string | null;
  is_working_day: boolean;
}
interface Contract {
  id: number;
  contract_start_date: string;
  contract_end_date: string | null;
  weekly_hours: number;
  contract_type: string | null;
  has_rol: boolean;
  working_days: WorkingDay[];
}
interface Person {
  avatar_url?: string;
  avatar_requires_auth?: boolean;
  id: number;
  first_name: string;
  last_name: string;
  email: string;
  azure_email: string | null;
  company_email: string | null;
  gender: string | null;
  birth_date: string;
  hiring_date: string;
  resignation_date: string | null;
  is_intern: boolean;
  isAllocation_visible: boolean;
  roles: Option[];
  assignment_roles: Option[];
  contracts: Contract[];
}
interface Page<T> {
  data: T[];
  current_page: number;
  last_page: number;
  total: number;
}
interface Options {
  roles: Option[];
  assignment_roles: Option[];
  expertise: string[];
  default_avatar_id: number | null;
}
interface Draft {
  first_name: string;
  last_name: string;
  email: string;
  azure_email: string;
  company_email: string;
  gender: string;
  birth_date: string;
  hiring_date: string;
  role_id: number | null;
  assignment_role_id: number | null;
  expertise: string;
  is_intern: boolean;
  isAllocation_visible: boolean;
  contract_start_date: string;
  contract_end_date: string;
  weekly_hours: number;
  contract_type: string;
  has_rol: boolean;
  working_days: WorkingDay[];
}
type UserFilter = 'search' | 'role' | 'assignment' | 'expertise' | 'visible';
type LeaveType = 'ferie' | 'permesso' | 'rol';
interface Balance {
  remaining_hours: number;
  accrued_hours: number;
  monthly_accrual: number;
  reference_date?: string;
}
interface LeaveMonth {
  reference_month: string;
  balances: Record<LeaveType, Balance | null>;
  full_time_vacation_days: unknown;
}
interface LeaveResponse {
  months: LeaveMonth[];
  rol_entitlements: {
    has_rol: boolean;
    contract_start_date: string;
    contract_end_date: string | null;
  }[];
}
interface LeaveEdit {
  month: string;
  label: string;
  remaining: number | null;
  accrued: number | null;
  rate: number | null;
  dirty: boolean;
  fullTime: boolean;
}
interface Audit {
  id: number;
  leave_type: string;
  reference_month: string;
  field_name: string;
  old_value: number | null;
  new_value: number | null;
  created_at: string;
  admin: { first_name: string; last_name: string } | null;
}

@Directive({ selector: 'dialog[userModal]' })
class UserModal implements AfterViewInit {
  private readonly element = inject<ElementRef<HTMLDialogElement>>(ElementRef);
  ngAfterViewInit() {
    this.element.nativeElement.showModal();
  }
}

@Component({
  imports: [AuthenticatedImage, FormsModule, DatePipe, DecimalPipe, Layout, UserModal],
  templateUrl: './users.html',
  host: { '(document:click)': 'closeMenu($event)' },
  styleUrls: ['./users.css', './users-modal.css'],
})
export class Users {
  private readonly http = inject(HttpClient);
  private readonly destroyRef = inject(DestroyRef);
  private readonly auth = inject(Auth);
  private readonly avatar = inject(ProfileAvatar);
  readonly router = inject(Router);
  readonly avatarUrl = this.avatar.url;
  readonly avatarFallback = API_URL + '/images/defaults/default-avatar.svg';
  readonly actor = this.auth.currentUser;
  readonly allowed = computed(
    () =>
      this.actor()?.roles.some((role) => ['Admin', 'HR Manager'].includes(role)) &&
      this.actor()?.permissions.includes('users.view'),
  );
  readonly canManage = computed(() => !!this.actor()?.permissions.includes('users.manage'));
  readonly canLeaves = computed(() => !!this.actor()?.permissions.includes('users.leaves.manage'));
  readonly active = signal<Page<Person> | null>(null);
  readonly disabled = signal<Page<Person> | null>(null);
  readonly options = signal<Options | null>(null);
  readonly loading = signal(false);
  readonly disabledLoading = signal(false);
  readonly error = signal('');
  readonly disabledError = signal('');
  readonly modalError = signal('');
  readonly fieldErrors = signal<Record<string, string[]>>({});
  readonly busy = signal(false);
  readonly notice = signal('');
  private listSequence = 0;
  private disabledSequence = 0;
  private leaveSequence = 0;
  columnFilter: UserFilter | null = null;
  filterDraft = '';
  filterTop = 0;
  filterLeft = 0;
  search = '';
  role = '';
  assignment = '';
  expertise = '';
  visible = '';
  sortBy = 'name';
  sortDirection = 'asc';
  showDisabled = false;
  menuId: number | null = null;
  menuTop = 0;
  menuLeft = 0;
  draft: Draft | null = null;
  editing: Person | null = null;
  selectedContract: number | null = null;
  tab: 'personal' | 'contract' = 'personal';
  confirmation: { person: Person; restore: boolean } | null = null;
  resignationDate = '';
  readonly countdown = signal(0);
  private countdownTimer?: ReturnType<typeof setInterval>;
  success = '';
  initialPassword = '';
  leaveUser: Person | null = null;
  leaveNotice = '';
  leaveTab: LeaveType | 'history' = 'ferie';
  year = Number(this.today.slice(0, 4));
  leaveRows: LeaveEdit[] = [];
  leaveData: LeaveResponse | null = null;
  leaveUnits: 'hours' | 'days' = 'hours';
  referenceDate = this.today;
  remaining: number | null = null;
  accrued: number | null = null;
  rate: number | null = null;
  readonly leavesLoading = signal(false);
  history: Page<Audit> | null = null;
  readonly weekdays = [
    { key: 'monday', label: 'Lunedì' },
    { key: 'tuesday', label: 'Martedì' },
    { key: 'wednesday', label: 'Mercoledì' },
    { key: 'thursday', label: 'Giovedì' },
    { key: 'friday', label: 'Venerdì' },
  ];
  readonly months = [
    'Gennaio',
    'Febbraio',
    'Marzo',
    'Aprile',
    'Maggio',
    'Giugno',
    'Luglio',
    'Agosto',
    'Settembre',
    'Ottobre',
    'Novembre',
    'Dicembre',
  ];

  constructor() {
    this.destroyRef.onDestroy(() => clearInterval(this.countdownTimer));
    if (this.allowed()) void this.initialize();
    this.avatar
      .load()
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({ error: () => undefined });
  }

  get today(): string {
    return new Intl.DateTimeFormat('sv-SE', { timeZone: 'Europe/Rome' }).format(new Date());
  }
  private request<T>(
    method: string,
    path: string,
    body?: unknown,
    params: Record<string, string | number> = {},
  ) {
    return firstValueFrom(
      this.http
        .request<T>(method, API_URL + '/api/users' + path, { body, params })
        .pipe(takeUntilDestroyed(this.destroyRef)),
    );
  }
  private message(error: unknown): string {
    if (error instanceof HttpErrorResponse) {
      if (error.status === 403) return 'Non hai i permessi per questa operazione.';
      const first = Object.values(error.error?.errors ?? {}).flat()[0];
      if (typeof first === 'string') return first;
      if (typeof error.error?.message === 'string') return error.error.message;
    }
    return 'Impossibile completare l’operazione. Riprova.';
  }
  name(person: Person): string {
    return person.first_name + ' ' + person.last_name;
  }
  aliases(values: Option[]): string {
    return values.map((value) => value.alias).join(', ') || '—';
  }
  expertiseLabel(person: Person): string {
    const value = person.assignment_roles[0]?.pivot?.expertise;
    return value ? value[0].toUpperCase() + value.slice(1) : '—';
  }
  canEdit(person: Person): boolean {
    return (
      this.canManage() &&
      (!!this.actor()?.roles.includes('Admin') ||
        !person.roles.some((role) => ['Admin', 'HR Manager'].includes(role.alias)))
    );
  }
  get assignableRoles(): Option[] {
    return (this.options()?.roles ?? []).filter(
      (role) =>
        this.actor()?.roles.includes('Admin') || !['Admin', 'HR Manager'].includes(role.alias),
    );
  }

  async initialize() {
    try {
      const response = await this.request<{ data: Options }>('GET', '/options');
      this.options.set(response.data);
    } catch (error) {
      this.error.set(this.message(error));
      return;
    }
    await this.load();
  }
  async load(page = 1, disabled = false) {
    const sequence = disabled ? ++this.disabledSequence : ++this.listSequence;
    const loading = disabled ? this.disabledLoading : this.loading;
    const errorSignal = disabled ? this.disabledError : this.error;
    loading.set(true);
    errorSignal.set('');
    this.menuId = null;
    const params: Record<string, string | number> = {
      status: disabled ? 'disabled' : 'active',
      page,
      per_page: 10,
      search: this.search.trim(),
      sort_by: this.sortBy,
      sort_direction: this.sortDirection,
    };
    if (!disabled) {
      for (const [key, value] of Object.entries({
        role_id: this.role,
        assignment_role_id: this.assignment,
        expertise: this.expertise,
        isAllocation_visible: this.visible,
      })) {
        if (value !== '') params[key] = value;
      }
    }
    try {
      const result = await this.request<{ data: Page<Person> }>('GET', '', undefined, params);
      if (sequence !== (disabled ? this.disabledSequence : this.listSequence)) return;
      if (result.data.current_page > result.data.last_page) {
        void this.load(result.data.last_page, disabled);
        return;
      }
      (disabled ? this.disabled : this.active).set(result.data);
    } catch (error) {
      if (sequence === (disabled ? this.disabledSequence : this.listSequence))
        errorSignal.set(this.message(error));
    } finally {
      if (sequence === (disabled ? this.disabledSequence : this.listSequence)) loading.set(false);
    }
  }
  toggleMenu(person: Person, event: Event) {
    const bounds = (event.currentTarget as HTMLElement).getBoundingClientRect();
    this.menuTop = Math.max(8, Math.min(bounds.bottom + 3, window.innerHeight - 180));
    this.menuLeft = Math.max(8, bounds.right - 180);
    this.menuId = this.menuId === person.id ? null : person.id;
  }
  closeMenu(event: Event) {
    if (!(event.target as HTMLElement).closest('.column-filter-trigger, .column-filter-popover'))
      this.columnFilter = null;
    if (!(event.target as HTMLElement).closest('.row-actions')) this.menuId = null;
  }
  openFilter(key: UserFilter, event: Event): void {
    if (this.columnFilter === key) {
      this.columnFilter = null;
      return;
    }
    const bounds = (event.currentTarget as HTMLElement).getBoundingClientRect();
    this.filterTop = Math.max(8, Math.min(bounds.bottom + 8, window.innerHeight - 210));
    this.filterLeft = Math.max(8, Math.min(bounds.left, window.innerWidth - 260));
    this.columnFilter = key;
    this.filterDraft = this[key];
  }
  applyFilter(): void {
    if (!this.columnFilter) return;
    this[this.columnFilter] = this.filterDraft;
    this.columnFilter = null;
    this.filter();
  }
  clearFilter(): void {
    this.filterDraft = '';
    this.applyFilter();
  }
  filterLabel(key: UserFilter): string {
    return {
      search: 'Nome e Cognome',
      role: 'Ruolo',
      assignment: 'Incarico',
      expertise: 'Competenza',
      visible: 'Visibilità in allocazione',
    }[key];
  }
  hasFilters(): boolean {
    return !!(this.search || this.role || this.assignment || this.expertise || this.visible);
  }
  filter() {
    void this.load();
    if (this.showDisabled) void this.load(1, true);
  }
  reset() {
    this.columnFilter = null;
    this.search = this.role = this.assignment = this.expertise = this.visible = '';
    this.filter();
  }
  sort(field: string) {
    this.sortDirection = this.sortBy === field && this.sortDirection === 'asc' ? 'desc' : 'asc';
    this.sortBy = field;
    this.filter();
  }
  toggleDisabled() {
    this.showDisabled = !this.showDisabled;
    if (this.showDisabled) void this.load(1, true);
  }
  private defaultDays(): WorkingDay[] {
    return this.weekdays.map((day) => ({
      day_of_week: day.key,
      start_time: '09:00',
      end_time: '18:00',
      is_working_day: true,
    }));
  }
  start(person?: Person) {
    this.menuId = null;
    this.modalError.set('');
    this.fieldErrors.set({});
    this.tab = 'personal';
    this.editing = person ?? null;
    this.selectedContract = null;
    this.draft = {
      first_name: person?.first_name ?? '',
      last_name: person?.last_name ?? '',
      email: person?.email ?? '',
      azure_email: person?.azure_email ?? '',
      company_email: person?.company_email ?? '',
      gender: person?.gender ?? '',
      birth_date: person?.birth_date?.slice(0, 10) ?? '',
      hiring_date: person?.hiring_date?.slice(0, 10) ?? '',
      role_id: person?.roles[0]?.id ?? null,
      assignment_role_id: person?.assignment_roles[0]?.id ?? null,
      expertise: person?.assignment_roles[0]?.pivot?.expertise ?? '',
      is_intern: person?.is_intern ?? false,
      isAllocation_visible: person?.isAllocation_visible ?? true,
      contract_start_date: '',
      contract_end_date: '',
      weekly_hours: 40,
      contract_type: '',
      has_rol: false,
      working_days: this.defaultDays(),
    };
    if (person?.contracts.length === 1) this.selectContract(person.contracts[0].id);
  }
  selectContract(id: number | null) {
    if (!this.draft) return;
    this.selectedContract = id;
    const contract = this.editing?.contracts.find((item) => item.id === id);
    if (!contract) return;
    Object.assign(this.draft, {
      contract_start_date: contract.contract_start_date.slice(0, 10),
      contract_end_date: contract.contract_end_date?.slice(0, 10) ?? '',
      weekly_hours: contract.weekly_hours,
      contract_type: contract.contract_type ?? '',
      has_rol: contract.has_rol,
      working_days: this.weekdays.map((day) => {
        const saved = contract.working_days.find((item) => item.day_of_week === day.key);
        return {
          day_of_week: day.key,
          start_time: saved?.start_time?.slice(0, 5) ?? null,
          end_time: saved?.end_time?.slice(0, 5) ?? null,
          is_working_day: saved?.is_working_day ?? false,
        };
      }),
    });
  }
  hoursChanged() {
    if (this.draft?.weekly_hours === 40) this.draft.working_days = this.defaultDays();
  }
  dayHours(day: WorkingDay): number {
    if (!day.is_working_day || !day.start_time || !day.end_time) return 0;
    const minutes = (value: string) => Number(value.slice(0, 2)) * 60 + Number(value.slice(3, 5));
    const duration = minutes(day.end_time) - minutes(day.start_time);
    return Math.max(0, duration - (duration > 360 ? 60 : 0)) / 60;
  }
  get totalHours(): number {
    return this.draft?.working_days.reduce((sum, day) => sum + this.dayHours(day), 0) ?? 0;
  }
  get scheduleValid(): boolean {
    return (
      !!this.draft &&
      Math.abs(this.totalHours - this.draft.weekly_hours) < 0.001 &&
      this.draft.working_days.every(
        (day) =>
          !day.is_working_day ||
          (!!day.start_time && !!day.end_time && day.end_time > day.start_time),
      )
    );
  }
  closeEditor() {
    if (!this.busy()) this.draft = null;
  }
  async save() {
    if (!this.draft || this.busy()) return;
    if (this.editing && this.editing.contracts.length > 1 && !this.selectedContract) {
      this.tab = 'contract';
      this.modalError.set('Seleziona il contratto da modificare.');
      return;
    }
    if (!this.scheduleValid) {
      this.tab = 'contract';
      this.modalError.set('Il totale della distribuzione deve corrispondere alle ore settimanali.');
      return;
    }
    this.busy.set(true);
    this.modalError.set('');
    this.fieldErrors.set({});
    const form = this.draft;
    const body: Record<string, unknown> = {
      ...form,
      gender: form.gender || null,
      azure_email: form.azure_email || null,
      company_email: form.company_email || null,
      expertise: form.assignment_role_id ? form.expertise || null : null,
      contract_end_date: form.contract_end_date || null,
      contract_type: form.contract_type || null,
      working_days: form.working_days.map((day) => ({
        ...day,
        start_time: day.is_working_day ? day.start_time : null,
        end_time: day.is_working_day ? day.end_time : null,
      })),
    };
    if (this.selectedContract) body['contract_id'] = this.selectedContract;
    if (!this.editing) {
      const avatarId = this.options()?.default_avatar_id;
      if (!avatarId) {
        this.modalError.set('Avatar predefinito non disponibile. Verifica i seeder.');
        this.busy.set(false);
        return;
      }
      body['filestorage_id'] = avatarId;
    }
    try {
      const result = await this.request<{ message: string; initial_password?: string }>(
        this.editing ? 'PUT' : 'POST',
        this.editing ? '/' + this.editing.id : '',
        body,
      );
      this.success = this.editing ? 'Utente aggiornato' : 'Utente creato';
      this.notice.set(result.message);
      this.initialPassword = result.initial_password ?? '';
      this.draft = null;
      await this.load(this.active()?.current_page ?? 1);
    } catch (error) {
      this.modalError.set(this.message(error));
      if (error instanceof HttpErrorResponse) {
        this.fieldErrors.set(error.error?.errors ?? {});
        if (
          Object.keys(this.fieldErrors()).some((key) =>
            [
              'first_name',
              'last_name',
              'email',
              'gender',
              'birth_date',
              'azure_email',
              'company_email',
            ].includes(key),
          )
        )
          this.tab = 'personal';
        else this.tab = 'contract';
      }
    } finally {
      this.busy.set(false);
    }
  }
  confirm(person: Person, restore: boolean) {
    this.menuId = null;
    this.confirmation = { person, restore };
    this.resignationDate = '';
    this.modalError.set('');
    clearInterval(this.countdownTimer);
    this.countdown.set(restore ? 0 : 3);
    if (!restore)
      this.countdownTimer = setInterval(() => {
        this.countdown.update((value) => Math.max(0, value - 1));
        if (!this.countdown()) clearInterval(this.countdownTimer);
      }, 1000);
  }
  closeConfirm() {
    if (!this.busy()) {
      this.confirmation = null;
      clearInterval(this.countdownTimer);
    }
  }
  async confirmSave() {
    if (!this.confirmation || this.busy() || this.countdown()) return;
    const { person, restore } = this.confirmation;
    this.busy.set(true);
    this.modalError.set('');
    try {
      await this.request(
        'POST',
        '/' + person.id + (restore ? '/restore' : '/disable'),
        restore ? {} : { resignation_date: this.resignationDate },
      );
      this.confirmation = null;
      this.success = restore ? 'Utente abilitato' : 'Utente disabilitato';
      await this.load(this.active()?.current_page ?? 1);
      if (this.showDisabled) await this.load(this.disabled()?.current_page ?? 1, true);
    } catch (error) {
      this.modalError.set(this.message(error));
    } finally {
      this.busy.set(false);
    }
  }

  async openLeaves(person: Person) {
    this.menuId = null;
    this.leaveNotice = '';
    this.leaveUser = person;
    this.leaveTab = 'ferie';
    this.year = Number(this.today.slice(0, 4));
    this.leaveUnits = 'hours';
    this.leaveRows = [];
    this.leaveData = null;
    this.history = null;
    await this.loadLeaves();
  }
  closeLeaves() {
    if (!this.busy()) {
      ++this.leaveSequence;
      this.leaveUser = null;
    }
  }
  async loadLeaves() {
    if (!this.leaveUser) return;
    const sequence = ++this.leaveSequence;
    this.leavesLoading.set(true);
    this.modalError.set('');
    try {
      const data = await this.request<LeaveResponse>(
        'GET',
        '/' + this.leaveUser.id + '/leaves',
        undefined,
        { year: this.year },
      );
      if (sequence !== this.leaveSequence) return;
      this.leaveData = data;
      this.leaveRows = data.months.map((month, index) => ({
        month: month.reference_month,
        label: this.months[index],
        remaining:
          month.balances.ferie === null ? null : Number(month.balances.ferie.remaining_hours),
        accrued: month.balances.ferie === null ? null : Number(month.balances.ferie.accrued_hours),
        rate: month.balances.ferie === null ? null : Number(month.balances.ferie.monthly_accrual),
        dirty: false,
        fullTime: this.fullTimeMonth(month.reference_month),
      }));
      this.loadReference();
    } catch (error) {
      if (sequence === this.leaveSequence) this.modalError.set(this.message(error));
    } finally {
      if (sequence === this.leaveSequence) this.leavesLoading.set(false);
    }
  }
  private fullTimeMonth(month: string): boolean {
    const end = new Date(Date.UTC(Number(month.slice(0, 4)), Number(month.slice(5, 7)), 0))
      .toISOString()
      .slice(0, 10);
    const matches =
      this.leaveUser?.contracts.filter(
        (contract) =>
          contract.contract_start_date.slice(0, 10) <= end &&
          (!contract.contract_end_date || contract.contract_end_date.slice(0, 10) >= month),
      ) ?? [];
    return (
      matches.length === 1 &&
      matches[0].weekly_hours === 40 &&
      matches[0].contract_start_date.slice(0, 10) <= month &&
      (!matches[0].contract_end_date || matches[0].contract_end_date.slice(0, 10) >= end)
    );
  }
  get vacationSummary(): string {
    const latest = this.leaveRows
      .filter((row) => row.month <= this.today && row.remaining !== null)
      .at(-1);
    return latest ? latest.remaining + (this.leaveUnits === 'days' ? ' gg' : ' h') : '—';
  }
  changeYear(delta: number) {
    if (
      this.leaveRows.some((row) => row.dirty) &&
      !window.confirm('Abbandonare le modifiche ai contatori non salvate?')
    )
      return;
    this.year += delta;
    void this.loadLeaves();
  }
  changeLeaveTab(tab: LeaveType | 'history') {
    this.leaveTab = tab;
    this.modalError.set('');
    if (tab === 'history') void this.loadHistory();
    else this.loadReference();
  }
  loadReference() {
    if (this.leaveTab === 'history' || this.leaveTab === 'ferie') return;
    const balance = this.leaveData?.months.find(
      (month) => month.reference_month.slice(0, 7) === this.referenceDate.slice(0, 7),
    )?.balances[this.leaveTab];
    this.remaining = balance ? Number(balance.remaining_hours) : null;
    this.accrued = balance ? Number(balance.accrued_hours) : null;
    this.rate = balance ? Number(balance.monthly_accrual) : null;
  }
  async referenceChanged() {
    const year = Number(this.referenceDate.slice(0, 4));
    if (year && year !== this.year) {
      this.year = year;
      await this.loadLeaves();
    } else this.loadReference();
  }
  get canUseDays(): boolean {
    return this.leaveRows.filter((row) => row.month <= this.today).every((row) => row.fullTime);
  }
  changeUnits(value: 'hours' | 'days') {
    if (value === this.leaveUnits) return;
    const factor = value === 'days' ? 1 / 8 : 8;
    for (const row of this.leaveRows)
      for (const field of ['remaining', 'accrued', 'rate'] as const) {
        if (row[field] !== null) row[field] = Number((row[field]! * factor).toFixed(4));
      }
    this.leaveUnits = value;
  }
  get rolAvailable(): boolean {
    return !!this.leaveData?.rol_entitlements.some((contract) => contract.has_rol);
  }
  async saveLeaves() {
    if (!this.leaveUser || this.busy() || this.leaveTab === 'history') return;
    const type = this.leaveTab;
    let leaves: Record<string, unknown>[];
    if (type === 'ferie') {
      const factor = this.leaveUnits === 'days' ? 8 : 1;
      leaves = this.leaveRows
        .filter((row) => row.dirty)
        .map((row) => ({
          leave_type: type,
          reference_month: row.month,
          ...Object.fromEntries(
            (
              [
                ['remaining_hours', row.remaining],
                ['accrued_hours', row.accrued],
                ['monthly_accrual', row.rate],
              ] as const
            )
              .filter(([, value]) => value !== null)
              .map(([key, value]) => [key, Math.round(value! * factor * 100) / 100]),
          ),
        }));
    } else {
      leaves = [
        {
          leave_type: type,
          reference_month: this.referenceDate.slice(0, 7) + '-01',
          reference_date: this.referenceDate,
          ...Object.fromEntries(
            (
              [
                ['remaining_hours', this.remaining],
                ['accrued_hours', this.accrued],
                ['monthly_accrual', this.rate],
              ] as const
            ).filter(([, value]) => value !== null),
          ),
        },
      ];
    }
    if (!leaves.length) {
      this.modalError.set('Modifica almeno un contatore.');
      return;
    }
    this.busy.set(true);
    this.modalError.set('');
    try {
      await this.request('PUT', '/' + this.leaveUser.id + '/leaves', { leaves });
      this.notice.set('Contatori aggiornati.');
      this.leaveUnits = 'hours';
      await this.loadLeaves();
      this.leaveNotice = 'Contatori aggiornati con successo.';
    } catch (error) {
      this.modalError.set(this.message(error));
    } finally {
      this.busy.set(false);
    }
  }
  async loadHistory(page = 1) {
    if (!this.leaveUser) return;
    const sequence = ++this.leaveSequence;
    this.leavesLoading.set(true);
    this.modalError.set('');
    try {
      const result = await this.request<{ data: Page<Audit> }>(
        'GET',
        '/' + this.leaveUser.id + '/leaves/history',
        undefined,
        { year: this.year, page },
      );
      if (sequence === this.leaveSequence) this.history = result.data;
    } catch (error) {
      if (sequence === this.leaveSequence) this.modalError.set(this.message(error));
    } finally {
      if (sequence === this.leaveSequence) this.leavesLoading.set(false);
    }
  }
}
