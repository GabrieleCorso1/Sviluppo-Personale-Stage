import { DatePipe, NgTemplateOutlet } from '@angular/common';
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

interface Person {
  id: number;
  first_name: string;
  last_name: string;
  avatar_url?: string;
  assignment_roles?: { id: number; alias: string }[];
}
interface Project {
  id: number;
  alias: string;
  description: string | null;
  is_timesheet: boolean;
  start_date: string | null;
  status: string | null;
  priority: string | null;
  is_daily: boolean;
  is_azure: boolean;
  is_ux_ui: boolean;
  project_managers: Person[];
  team_leader: Person | null;
  scrum_master: Person | null;
  azure_project_name: string | null;
  azure_team_name: string | null;
  bug_count_url: string | null;
  pbi_without_feature_url: string | null;
  pbi_past_feature_url: string | null;
  note: string | null;
  logo_url: string;
  logo_requires_auth: boolean;
  delivery_plan_url: string | null;
  missing_csv_count: number;
  next_sprint_review_date: string | null;
}
interface Draft {
  alias: string;
  description: string;
  project_manager_id: number | null;
  start_date: string;
  status: string;
  priority: string;
  is_daily: boolean;
  is_azure: boolean;
  is_ux_ui: boolean;
  azure_project_name: string;
  azure_team_name: string;
  bug_count_url: string;
  pbi_without_feature_url: string;
  pbi_past_feature_url: string;
}
interface Page<T> {
  data: T[];
  current_page: number;
  last_page: number;
  total: number;
}
interface ProjectPage extends Page<Project> {
  counts: { projects: number; timesheets: number };
}
type FilterKey = 'search' | 'manager' | 'leader' | 'scrumMaster' | 'status' | 'priority';
type CsvGroup = 'uploaded' | 'missing' | 'not_required' | 'pending';
interface Sprint {
  id: number;
  number: number;
  is_concluded: boolean;
  is_pilot: boolean;
  is_billing: boolean;
  has_csv: boolean;
  csv_status: CsvGroup;
  csv_url: string | null;
}
interface CsvData {
  data: Record<CsvGroup, Sprint[]>;
  counts: Record<CsvGroup, number>;
}
type ProjectModalState = { kind: 'note' | 'delete' | 'people' | 'pbi' | 'csv'; project: Project };

@Directive({ selector: 'dialog[projectModal]' })
class ProjectModal implements AfterViewInit {
  private readonly element = inject<ElementRef<HTMLDialogElement>>(ElementRef);
  ngAfterViewInit() {
    this.element.nativeElement.showModal();
  }
}

@Component({
  imports: [NgTemplateOutlet, DatePipe, FormsModule, Layout, AuthenticatedImage, ProjectModal],
  templateUrl: './projects.html',
  styleUrls: ['./projects.css', './projects-filters.css', './projects-modal.css'],
  host: { '(document:click)': 'closeMenu($event)' },
})
export class Projects {
  private readonly http = inject(HttpClient);
  private readonly destroyRef = inject(DestroyRef);
  private readonly auth = inject(Auth);
  private readonly avatar = inject(ProfileAvatar);
  readonly router = inject(Router);
  readonly avatarUrl = this.avatar.url;
  readonly allowed = computed(() => !!this.auth.currentUser()?.roles.includes('Admin'));
  readonly fallbackLogo = API_URL + '/images/defaults/default-project.svg';
  readonly fallbackAvatar = API_URL + '/images/defaults/default-avatar.svg';
  readonly page = signal<ProjectPage | null>(null);
  readonly loading = signal(false);
  readonly error = signal('');
  readonly notice = signal('');
  readonly modalError = signal('');
  readonly modalNotice = signal('');
  readonly busy = signal(false);
  readonly modalLoading = signal(false);
  readonly managers = signal<Person[]>([]);
  readonly managersLoading = signal(false);
  readonly managerError = signal('');
  readonly people = signal<Person[]>([]);
  readonly previews = signal<Record<number, Person[]>>({});
  readonly csv = signal<CsvData | null>(null);
  readonly fieldErrors = signal<Record<string, string[]>>({});
  private listSequence = 0;
  private modalSequence = 0;
  private previewUrl: string | null = null;
  private readonly temporaryUrls = new Set<string>();
  isTimesheet = false;
  search = '';
  status = '';
  priority = '';
  manager = '';
  leader = '';
  scrumMaster = '';
  sortDirection = 'asc';
  menuId: number | null = null;
  menuTop = 0;
  menuLeft = 0;
  draft: Draft | null = null;
  editing: Project | null = null;
  draftTimesheet = false;
  modal: ProjectModalState | null = null;
  note = '';
  peopleSearch = '';
  logoFile: File | null = null;
  planFile: File | null = null;
  logoPreview = '';
  success = '';
  csvTab: CsvGroup = 'missing';
  readonly csvTabs: { key: CsvGroup; label: string }[] = [
    { key: 'uploaded', label: 'CSV caricati' },
    { key: 'missing', label: 'CSV mancanti' },
  ];
  readonly peopleColumns: { key: FilterKey; label: string }[] = [
    { key: 'manager', label: 'Project manager' },
    { key: 'leader', label: 'Team leader' },
    { key: 'scrumMaster', label: 'Scrum master' },
  ];
  columnFilter: FilterKey | null = null;
  filterDraft = '';
  filterTop = 0;
  filterLeft = 0;
  expandedSprints = new Set<number>();
  sprintChoices: Record<number, string> = {};

  openFilter(key: FilterKey, event: Event): void {
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
  filterValue(key: FilterKey): string {
    return this[key];
  }
  filterLabel(key: FilterKey): string {
    return {
      search: 'Cerca progetto',
      manager: 'Project manager',
      leader: 'Team leader',
      scrumMaster: 'Scrum master',
      status: 'Stato',
      priority: 'Priorità',
    }[key];
  }
  applyFilter(): void {
    if (!this.columnFilter) return;
    this[this.columnFilter] = this.filterDraft;
    this.columnFilter = null;
    void this.load();
  }
  clearFilter(): void {
    this.filterDraft = '';
    this.applyFilter();
  }
  hasFilters(): boolean {
    return !!(
      this.search ||
      this.manager ||
      this.leader ||
      this.scrumMaster ||
      this.status ||
      this.priority
    );
  }
  paginationPages(): (number | 'ellipsis')[] {
    const page = this.page();
    if (!page) return [];
    const numbers = [
      ...new Set([
        1,
        2,
        3,
        page.current_page - 1,
        page.current_page,
        page.current_page + 1,
        page.last_page,
      ]),
    ]
      .filter((value) => value >= 1 && value <= page.last_page)
      .sort((a, b) => a - b);
    const result: (number | 'ellipsis')[] = [];
    for (const value of numbers) {
      const previous = result.at(-1);
      if (typeof previous === 'number' && value - previous > 1) result.push('ellipsis');
      result.push(value);
    }
    return result;
  }
  toggleSprint(id: number): void {
    if (this.expandedSprints.has(id)) this.expandedSprints.delete(id);
    else this.expandedSprints.add(id);
  }
  sprintChoice(sprint: Sprint): string {
    return (
      this.sprintChoices[sprint.id] ??
      (sprint.is_billing ? 'billing' : sprint.is_pilot ? 'pilot' : 'normal')
    );
  }
  additionalSprints(): Sprint[] {
    const groups = this.csv()?.data;
    return groups ? [...groups.not_required, ...groups.pending] : [];
  }
  readonly states = ['in corso', 'manutenzione', 'concluso', 'sospeso'];
  readonly priorities = ['alta', 'media', 'bassa'];

  constructor() {
    this.destroyRef.onDestroy(() => {
      this.releasePreview();
      for (const url of this.temporaryUrls) URL.revokeObjectURL(url);
    });
    this.avatar
      .load()
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({ error: () => undefined });
    if (this.allowed()) {
      void this.load();
      void this.loadManagers();
    }
  }
  private request<T>(
    method: string,
    path: string,
    body?: unknown,
    params: Record<string, string | number> = {},
  ) {
    return firstValueFrom(
      this.http
        .request<T>(method, API_URL + '/api' + path, { body, params })
        .pipe(takeUntilDestroyed(this.destroyRef)),
    );
  }
  private message(error: unknown): string {
    if (error instanceof HttpErrorResponse) {
      if (error.status === 403) return 'Non hai i permessi per questa operazione.';
      if (error.status === 404) return 'L’elemento o il file non è più disponibile.';
      const first = Object.values(error.error?.errors ?? {}).flat()[0];
      if (typeof first === 'string') return first;
      if (typeof error.error?.message === 'string') return error.error.message;
    }
    return 'Operazione non riuscita. Riprova.';
  }
  name(person: Person | null): string {
    return person ? person.first_name + ' ' + person.last_name : '—';
  }
  managerNames(project: Project): string {
    return project.project_managers.map((person) => this.name(person)).join(', ') || '—';
  }
  roles(person: Person): string {
    return person.assignment_roles?.map((role) => role.alias).join(', ') || '';
  }
  stateLabel(value: string | null): string {
    return value === 'manutenzione'
      ? 'In manutenzione'
      : value
        ? value[0].toUpperCase() + value.slice(1)
        : '—';
  }
  toggleMenu(project: Project, event: Event) {
    const bounds = (event.currentTarget as HTMLElement).getBoundingClientRect();
    this.menuTop = Math.max(8, Math.min(bounds.bottom + 3, window.innerHeight - 225));
    this.menuLeft = Math.max(8, bounds.right - 185);
    this.menuId = this.menuId === project.id ? null : project.id;
  }
  closeMenu(event: Event) {
    if (!(event.target as HTMLElement).closest('.column-filter-trigger, .column-filter-popover'))
      this.columnFilter = null;
    if (!(event.target as HTMLElement).closest('.project-actions')) this.menuId = null;
  }
  async load(page = 1) {
    const sequence = ++this.listSequence;
    this.loading.set(true);
    this.error.set('');
    this.menuId = null;
    const params: Record<string, string | number> = {
      is_timesheet: this.isTimesheet ? 1 : 0,
      page,
      per_page: 10,
      search: this.search.trim(),
      sort_direction: this.sortDirection,
    };
    if (!this.isTimesheet) {
      for (const [key, value] of Object.entries({
        status: this.status,
        priority: this.priority,
        project_manager_id: this.manager,
        team_leader_id: this.leader,
        scrum_master_id: this.scrumMaster,
      })) {
        if (value !== '') params[key] = value;
      }
    }
    try {
      const result = await this.request<ProjectPage>('GET', '/projects', undefined, params);
      if (sequence !== this.listSequence) return;
      if (result.current_page > result.last_page) {
        void this.load(result.last_page);
        return;
      }
      this.page.set(result);
      if (this.isTimesheet)
        for (const project of result.data) void this.loadPreview(project.id, sequence);
    } catch (error) {
      if (sequence === this.listSequence) this.error.set(this.message(error));
    } finally {
      if (sequence === this.listSequence) this.loading.set(false);
    }
  }
  private async loadPreview(projectId: number, sequence: number) {
    try {
      const result = await this.request<{ data: Person[] }>(
        'GET',
        '/projects/' + projectId + '/users',
      );
      if (sequence === this.listSequence)
        this.previews.update((values) => ({ ...values, [projectId]: result.data }));
    } catch {
      /* Il dettaglio partecipanti permette di riprovare e mostra l'errore. */
    }
  }
  switchTab(timesheet: boolean) {
    if (timesheet === this.isTimesheet) return;
    this.isTimesheet = timesheet;
    this.page.set(null);
    this.reset();
  }
  reset() {
    this.columnFilter = null;
    this.search = this.status = this.priority = this.manager = this.leader = this.scrumMaster = '';
    void this.load();
  }
  sort() {
    this.sortDirection = this.sortDirection === 'asc' ? 'desc' : 'asc';
    void this.load();
  }
  async loadManagers() {
    this.managersLoading.set(true);
    this.managerError.set('');
    try {
      const all: Person[] = [];
      let page = 1;
      let lastPage = 1;
      do {
        const result = await this.request<Page<Person>>(
          'GET',
          '/project-options/users',
          undefined,
          { page, per_page: 100 },
        );
        all.push(...result.data);
        lastPage = result.last_page;
        page++;
      } while (page <= lastPage);
      this.managers.set(all);
    } catch (error) {
      this.managerError.set(this.message(error));
    } finally {
      this.managersLoading.set(false);
    }
  }
  start(project?: Project) {
    this.releasePreview();
    this.menuId = null;
    this.modalError.set('');
    this.modalNotice.set('');
    this.fieldErrors.set({});
    this.editing = project ?? null;
    this.draftTimesheet = project?.is_timesheet ?? this.isTimesheet;
    this.logoFile = this.planFile = null;
    this.draft = {
      alias: project?.alias ?? '',
      description: project?.description ?? '',
      project_manager_id: project?.project_managers[0]?.id ?? null,
      start_date: project?.start_date?.slice(0, 10) ?? '',
      status: project?.status ?? '',
      priority: project?.priority ?? '',
      is_daily: project?.is_daily ?? false,
      is_azure: project?.is_azure ?? false,
      is_ux_ui: project?.is_ux_ui ?? false,
      azure_project_name: project?.azure_project_name ?? '',
      azure_team_name: project?.azure_team_name ?? '',
      bug_count_url: project?.bug_count_url ?? '',
      pbi_without_feature_url: project?.pbi_without_feature_url ?? '',
      pbi_past_feature_url: project?.pbi_past_feature_url ?? '',
    };
  }
  closeEditor() {
    if (this.busy()) return;
    this.draft = null;
    this.releasePreview();
    this.logoFile = this.planFile = null;
  }
  private releasePreview() {
    if (this.previewUrl) URL.revokeObjectURL(this.previewUrl);
    this.previewUrl = null;
    this.logoPreview = '';
  }
  selectLogo(input: HTMLInputElement) {
    const file = input.files?.[0];
    input.value = '';
    if (!file) return;
    if (
      !['image/png', 'image/jpeg', 'image/webp'].includes(file.type) ||
      file.size > 2 * 1024 * 1024
    ) {
      this.modalError.set('Scegli un’immagine PNG, JPEG o WebP di massimo 2 MB.');
      return;
    }
    this.releasePreview();
    this.logoFile = file;
    this.previewUrl = URL.createObjectURL(file);
    this.logoPreview = this.previewUrl;
    this.modalError.set('');
  }
  selectPlan(input: HTMLInputElement) {
    const file = input.files?.[0];
    input.value = '';
    if (!file) return;
    if (file.type !== 'application/pdf' || file.size > 10 * 1024 * 1024) {
      this.modalError.set('Scegli un PDF di massimo 10 MB.');
      return;
    }
    this.planFile = file;
    this.modalError.set('');
  }
  async save() {
    if (!this.draft || this.busy()) return;
    this.busy.set(true);
    this.modalError.set('');
    this.fieldErrors.set({});
    const created = !this.editing;
    let persisted = false;
    try {
      const form = this.draft;
      const body: Record<string, unknown> = {
        alias: form.alias.trim(),
        description: form.description.trim() || null,
        azure_project_name: form.azure_project_name.trim() || null,
        azure_team_name: form.azure_team_name.trim() || null,
        bug_count_url: form.bug_count_url.trim() || null,
        pbi_without_feature_url: form.pbi_without_feature_url.trim() || null,
        pbi_past_feature_url: form.pbi_past_feature_url.trim() || null,
      };
      if (created) body['is_timesheet'] = this.draftTimesheet;
      if (!this.draftTimesheet) {
        Object.assign(body, {
          start_date: form.start_date,
          status: form.status,
          priority: form.priority,
          is_daily: form.is_daily,
          is_azure: form.is_azure,
          is_ux_ui: form.is_ux_ui,
        });
        if (created) body['project_manager_id'] = form.project_manager_id;
      }
      const response = await this.request<{ data: Project }>(
        created ? 'POST' : 'PATCH',
        '/projects' + (this.editing ? '/' + this.editing.id : ''),
        body,
      );
      this.editing = response.data;
      persisted = true;
      if (this.logoFile) {
        const upload = new FormData();
        upload.append('logo', this.logoFile);
        await this.request('POST', '/projects/' + this.editing.id + '/logo', upload);
        this.logoFile = null;
      }
      if (this.planFile && !this.draftTimesheet) {
        const upload = new FormData();
        upload.append('delivery_plan', this.planFile);
        await this.request('POST', '/projects/' + this.editing.id + '/delivery-plan', upload);
        this.planFile = null;
      }
      this.success = this.draftTimesheet
        ? created
          ? 'Timesheet creato'
          : 'Timesheet modificato'
        : created
          ? 'Progetto creato'
          : 'Progetto modificato';
      this.draft = null;
      this.releasePreview();
      await this.load(this.page()?.current_page ?? 1);
    } catch (error) {
      this.modalError.set(
        (persisted ? 'Dati salvati. Il caricamento del file non è riuscito: ' : '') +
          this.message(error),
      );
      if (error instanceof HttpErrorResponse) this.fieldErrors.set(error.error?.errors ?? {});
      if (persisted) void this.load(this.page()?.current_page ?? 1);
    } finally {
      this.busy.set(false);
    }
  }
  open(project: Project, kind: ProjectModalState['kind']) {
    ++this.modalSequence;
    this.menuId = null;
    this.modal = { project, kind };
    this.modalError.set('');
    this.modalNotice.set('');
    this.note = project.note ?? '';
    this.peopleSearch = '';
    this.people.set([]);
    this.csv.set(null);
    this.csvTab = 'missing';
    this.expandedSprints = new Set<number>();
    this.sprintChoices = {};
    if (kind === 'people') void this.loadPeople();
    if (kind === 'csv') void this.loadCsv();
  }
  closeModal() {
    if (!this.busy()) {
      ++this.modalSequence;
      this.modal = null;
      this.modalLoading.set(false);
    }
  }
  async saveNote() {
    if (!this.modal || this.busy()) return;
    this.busy.set(true);
    this.modalError.set('');
    try {
      await this.request('PATCH', '/projects/' + this.modal.project.id + '/note', {
        note: this.note.trim() || null,
      });
      this.modal = null;
      this.notice.set('Nota aggiornata.');
      await this.load(this.page()?.current_page ?? 1);
    } catch (error) {
      this.modalError.set(this.message(error));
    } finally {
      this.busy.set(false);
    }
  }
  async remove() {
    if (!this.modal || this.busy()) return;
    this.busy.set(true);
    this.modalError.set('');
    try {
      await this.request('DELETE', '/projects/' + this.modal.project.id);
      this.modal = null;
      this.notice.set('Progetto eliminato.');
      await this.load(this.page()?.current_page ?? 1);
    } catch (error) {
      this.modalError.set(this.message(error));
    } finally {
      this.busy.set(false);
    }
  }
  async loadPeople() {
    if (!this.modal) return;
    const sequence = ++this.modalSequence;
    this.modalLoading.set(true);
    this.modalError.set('');
    try {
      const result = await this.request<{ data: Person[] }>(
        'GET',
        '/projects/' + this.modal.project.id + '/users',
        undefined,
        { search: this.peopleSearch.trim() },
      );
      if (sequence === this.modalSequence) this.people.set(result.data);
    } catch (error) {
      if (sequence === this.modalSequence) this.modalError.set(this.message(error));
    } finally {
      if (sequence === this.modalSequence) this.modalLoading.set(false);
    }
  }
  async loadCsv() {
    if (!this.modal) return;
    const sequence = ++this.modalSequence;
    this.modalLoading.set(true);
    this.modalError.set('');
    try {
      const result = await this.request<CsvData>(
        'GET',
        '/projects/' + this.modal.project.id + '/csv',
      );
      if (sequence === this.modalSequence) this.csv.set(result);
    } catch (error) {
      if (sequence === this.modalSequence) this.modalError.set(this.message(error));
    } finally {
      if (sequence === this.modalSequence) this.modalLoading.set(false);
    }
  }
  async setSprintType(sprint: Sprint, type: string) {
    if (!this.modal || this.busy()) return;
    this.busy.set(true);
    this.modalError.set('');
    this.modalNotice.set('');
    try {
      await this.request(
        'PATCH',
        '/projects/' + this.modal.project.id + '/sprints/' + sprint.id + '/csv-type',
        { is_pilot: type === 'pilot', is_billing: type === 'billing' },
      );
      delete this.sprintChoices[sprint.id];
      this.modalNotice.set('Tipo sprint aggiornato.');
      await this.loadCsv();
      void this.load(this.page()?.current_page ?? 1);
    } catch (error) {
      this.modalError.set(this.message(error));
    } finally {
      this.busy.set(false);
    }
  }
  async uploadCsv(sprint: Sprint, input: HTMLInputElement) {
    const file = input.files?.[0];
    input.value = '';
    if (!this.modal || !file || this.busy()) return;
    if (!file.name.toLowerCase().endsWith('.csv') || file.size > 10 * 1024 * 1024) {
      this.modalError.set('Scegli un file CSV di massimo 10 MB.');
      return;
    }
    this.busy.set(true);
    this.modalError.set('');
    this.modalNotice.set('');
    try {
      const type = this.sprintChoice(sprint);
      if (type === 'billing') {
        this.modalError.set('Conferma la fatturazione: per questo tipo non serve un CSV.');
        return;
      }
      if ((type === 'pilot') !== sprint.is_pilot) {
        await this.request(
          'PATCH',
          '/projects/' + this.modal.project.id + '/sprints/' + sprint.id + '/csv-type',
          { is_pilot: type === 'pilot', is_billing: false },
        );
      }
      const upload = new FormData();
      upload.append('csv', file);
      await this.request(
        'POST',
        '/projects/' + this.modal.project.id + '/sprints/' + sprint.id + '/csv',
        upload,
      );
      delete this.sprintChoices[sprint.id];
      this.modalNotice.set('CSV caricato.');
      this.csvTab = 'uploaded';
      await this.loadCsv();
      void this.load(this.page()?.current_page ?? 1);
    } catch (error) {
      this.modalError.set(this.message(error));
    } finally {
      this.busy.set(false);
    }
  }
  async openFile(project: Project, sprint?: Sprint) {
    if (this.busy()) return;
    this.busy.set(true);
    const popup = sprint ? null : window.open('', '_blank');
    if (popup) popup.opener = null;
    try {
      const path =
        '/projects/' + project.id + (sprint ? '/sprints/' + sprint.id + '/csv' : '/delivery-plan');
      const file = await firstValueFrom(
        this.http
          .get(API_URL + '/api' + path, { responseType: 'blob' })
          .pipe(takeUntilDestroyed(this.destroyRef)),
      );
      const url = URL.createObjectURL(file);
      this.temporaryUrls.add(url);
      if (sprint) {
        const link = document.createElement('a');
        link.href = url;
        link.download = 'sprint-' + sprint.number + '.csv';
        link.click();
      } else if (popup) popup.location.href = url;
      else {
        const link = document.createElement('a');
        link.href = url;
        link.download = 'delivery-plan.pdf';
        link.click();
      }
    } catch (error) {
      popup?.close();
      (this.modal ? this.modalError : this.error).set(this.message(error));
    } finally {
      this.busy.set(false);
    }
  }
}
