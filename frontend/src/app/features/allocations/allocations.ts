import { DatePipe } from '@angular/common';
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

type AllocationType = 'project' | 'timesheet' | 'course';
interface Role {
  id: number;
  name: string;
  code: string;
}
interface Project {
  id: number;
  name: string;
  status: string | null;
  logo_url: string;
  resources_count: number;
  has_difficulty: boolean;
  necessities_count: number;
}
interface Assignment {
  id: number;
  type: AllocationType;
  project_id: number;
  user_id: number;
  role: Role | null;
  start_date: string;
  end_date: string | null;
  is_difficult: boolean;
  is_current: boolean;
  project?: Project;
}
interface Resource {
  id: number;
  first_name: string;
  last_name: string;
  full_name: string;
  avatar_url: string;
  projects_count: number;
  working_projects_count: number;
  is_unallocated: boolean;
  is_overloaded: boolean;
  has_difficulty: boolean;
  difficult_projects_count: number;
  allocations: Assignment[];
}
interface Page<T> {
  data: T[];
  current_page: number;
  last_page: number;
  total: number;
}
interface Grid {
  type: AllocationType;
  as_of: string;
  selected_project_id: number | null;
  projects: Project[];
  resources: Page<Resource>;
}
interface Summary {
  last_updated_at: string | null;
  total_resources: number;
  allocated_resources: number;
  unallocated_resources: number;
  overloaded_resources: number;
  resources_with_difficulty: number;
  open_necessities: number;
}
interface Need {
  id: number;
  role: Role | null;
}
interface ProjectDetail extends Project {
  resources: Resource[];
  necessities: Need[];
}
interface Options {
  roles: Role[];
  statuses: string[];
  types: AllocationType[];
}
type Modal =
  | { kind: 'assignment'; resource: Resource; project: Project; assignment?: Assignment }
  | { kind: 'resource'; resource: Resource }
  | { kind: 'project' | 'needs'; project: Project };

@Directive({ selector: 'dialog[allocationModal]' })
class AllocationModal implements AfterViewInit {
  private readonly element = inject<ElementRef<HTMLDialogElement>>(ElementRef);
  ngAfterViewInit() {
    this.element.nativeElement.showModal();
  }
}

@Component({
  imports: [DatePipe, FormsModule, Layout, AuthenticatedImage, AllocationModal],
  templateUrl: './allocations.html',
  styleUrls: ['./allocations-matrix.css', './allocations.css', './allocations-modal.css'],
})
export class Allocations {
  private readonly http = inject(HttpClient);
  private readonly destroyRef = inject(DestroyRef);
  private readonly auth = inject(Auth);
  private readonly avatar = inject(ProfileAvatar);
  readonly router = inject(Router);
  readonly avatarUrl = this.avatar.url;
  readonly allowed = computed(() => !!this.auth.currentUser()?.roles.includes('Admin'));
  readonly fallbackAvatar = API_URL + '/images/defaults/default-avatar.svg';
  readonly fallbackLogo = API_URL + '/images/defaults/default-project.svg';
  readonly grid = signal<Grid | null>(null);
  readonly summary = signal<Summary | null>(null);
  readonly options = signal<Options | null>(null);
  readonly loading = signal(false);
  readonly error = signal('');
  readonly summaryError = signal('');
  readonly optionsError = signal('');
  readonly notice = signal('');
  readonly busy = signal(false);
  readonly modalLoading = signal(false);
  readonly modalError = signal('');
  readonly resourceDetail = signal<Resource | null>(null);
  readonly projectDetail = signal<ProjectDetail | null>(null);
  private listSequence = 0;
  private modalSequence = 0;
  type: AllocationType = 'project';
  search = '';
  roleFilter = '';
  status = '';
  selectedProject: number | null = null;
  legendExpanded = true;
  modal: Modal | null = null;
  action: 'edit' | 'delete' | 'conclude' = 'edit';
  startDate = '';
  endDate = '';
  roleId: number | null = null;
  needs: number[] = [];
  addNeed = false;
  newNeedId: number | null = null;
  removingNeed: number | null = null;
  readonly tabs: { type: AllocationType; label: string }[] = [
    { type: 'project', label: 'Progetti' },
    { type: 'timesheet', label: 'Timesheet' },
    { type: 'course', label: 'Corsi' },
  ];

  constructor() {
    this.avatar
      .load()
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({ error: () => undefined });
    if (this.allowed()) {
      void this.loadOptions();
      void this.loadSummary();
      void this.load();
    }
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
        .request<T>(method, API_URL + '/api/allocations' + path, { body, params })
        .pipe(takeUntilDestroyed(this.destroyRef)),
    );
  }
  private message(error: unknown): string {
    if (error instanceof HttpErrorResponse) {
      if (error.status === 403) return 'Non hai i permessi per questa operazione.';
      if (error.status === 404) return 'La risorsa o l’assegnazione non è più disponibile.';
      const first = Object.values(error.error?.errors ?? {}).flat()[0];
      if (typeof first === 'string') return first;
      if (typeof error.error?.message === 'string') return error.error.message;
    }
    return 'Operazione non riuscita. Riprova.';
  }
  stateLabel(state: string | null): string {
    return state === 'manutenzione'
      ? 'In manutenzione'
      : state
        ? state[0].toUpperCase() + state.slice(1)
        : '—';
  }
  roleClass(role: Role | null): string {
    return 'role role-' + (role?.code ?? 'none');
  }
  assignments(resource: Resource, projectId: number): Assignment[] {
    return resource.allocations.filter((item) => item.project_id === projectId);
  }
  get roles(): Role[] {
    return this.options()?.roles ?? [];
  }
  roleLabel(role: Role): string {
    return (
      (
        {
          F: 'Frontend',
          B: 'Backend',
          FB: 'Fullstack',
          D: 'Designer',
          TL: 'Team Leader',
          PM: 'Project Manager',
          SM: 'Scrum Master',
        } as Record<string, string>
      )[role.code] ?? role.name
    );
  }
  get availableNeeds(): Role[] {
    return this.roles.filter((role) => !this.needs.includes(role.id));
  }
  needRole(id: number): Role | undefined {
    return this.roles.find((role) => role.id === id);
  }
  async loadOptions() {
    this.optionsError.set('');
    try {
      const result = await this.request<{ data: Options }>('GET', '/options');
      this.options.set(result.data);
    } catch (error) {
      this.optionsError.set(this.message(error));
    }
  }
  async loadSummary() {
    this.summaryError.set('');
    try {
      const result = await this.request<{ data: Summary }>('GET', '/summary');
      this.summary.set(result.data);
    } catch (error) {
      this.summaryError.set(this.message(error));
    }
  }
  async load(page = 1) {
    const sequence = ++this.listSequence;
    this.loading.set(true);
    this.error.set('');
    const params: Record<string, string | number> = {
      type: this.type,
      search: this.search.trim(),
      page,
      per_page: 25,
    };
    if (this.selectedProject) params['selected_project_id'] = this.selectedProject;
    if (this.type === 'project') {
      if (this.roleFilter) params['assignment_role_id'] = this.roleFilter;
      if (this.status) params['status'] = this.status;
    }
    try {
      const result = await this.request<{ data: Grid }>('GET', '', undefined, params);
      if (sequence !== this.listSequence) return;
      if (result.data.resources.current_page > result.data.resources.last_page) {
        void this.load(result.data.resources.last_page);
        return;
      }
      this.grid.set(result.data);
    } catch (error) {
      if (sequence === this.listSequence) this.error.set(this.message(error));
    } finally {
      if (sequence === this.listSequence) this.loading.set(false);
    }
  }
  switchTab(type: AllocationType) {
    if (this.busy() || this.type === type) return;
    this.type = type;
    this.roleFilter = this.status = '';
    this.selectedProject = null;
    this.grid.set(null);
    void this.load();
  }
  applyFilters() {
    this.selectedProject = null;
    void this.load();
  }
  reset() {
    this.search = this.roleFilter = this.status = '';
    this.selectedProject = null;
    void this.load();
  }
  prioritize(project: Project) {
    this.selectedProject = this.selectedProject === project.id ? null : project.id;
    void this.load();
  }
  openAssignment(resource: Resource, project: Project, assignment?: Assignment) {
    if (this.busy()) return;
    ++this.modalSequence;
    this.modal = { kind: 'assignment', resource, project, assignment };
    this.action = 'edit';
    this.startDate = assignment?.start_date ?? this.today;
    this.roleId = assignment?.role?.id ?? null;
    this.endDate = '';
    this.modalError.set('');
  }
  closeModal() {
    if (!this.busy()) {
      ++this.modalSequence;
      this.modal = null;
      this.modalLoading.set(false);
    }
  }
  async saveAssignment() {
    if (this.modal?.kind !== 'assignment' || this.busy()) return;
    const { resource, project, assignment } = this.modal;
    if (!this.startDate || (this.type === 'project' && !this.roleId)) {
      this.modalError.set('Seleziona data di inizio e ruolo.');
      return;
    }
    this.busy.set(true);
    this.modalError.set('');
    try {
      const body: Record<string, unknown> = { start_date: this.startDate };
      if (this.type === 'project') body['assignment_role_id'] = this.roleId;
      if (!assignment)
        Object.assign(body, { type: this.type, project_id: project.id, user_id: resource.id });
      await this.request(assignment ? 'PUT' : 'POST', assignment ? '/' + assignment.id : '', body);
      this.modal = null;
      this.notice.set(assignment ? 'Assegnazione aggiornata.' : 'Risorsa assegnata.');
      await Promise.all([this.load(this.grid()?.resources.current_page ?? 1), this.loadSummary()]);
    } catch (error) {
      this.modalError.set(this.message(error));
    } finally {
      this.busy.set(false);
    }
  }
  async terminate() {
    if (this.modal?.kind !== 'assignment' || !this.modal.assignment || this.busy()) return;
    if (this.action === 'edit') return;
    if (this.action === 'conclude' && !this.endDate) {
      this.modalError.set('Seleziona la data di fine collaborazione.');
      return;
    }
    this.busy.set(true);
    this.modalError.set('');
    const assignment = this.modal.assignment;
    try {
      if (this.action === 'delete') await this.request('DELETE', '/' + assignment.id);
      else
        await this.request('POST', '/' + assignment.id + '/conclude', { end_date: this.endDate });
      this.notice.set(
        this.action === 'delete' ? 'Assegnazione eliminata.' : 'Collaborazione conclusa.',
      );
      this.modal = null;
      await Promise.all([this.load(this.grid()?.resources.current_page ?? 1), this.loadSummary()]);
    } catch (error) {
      this.modalError.set(this.message(error));
    } finally {
      this.busy.set(false);
    }
  }
  async toggleDifficulty(assignment: Assignment) {
    if (this.busy() || assignment.type !== 'project') return;
    this.busy.set(true);
    const errorSignal = this.modal ? this.modalError : this.error;
    errorSignal.set('');
    try {
      await this.request('PATCH', '/' + assignment.id + '/difficulty', {
        is_difficult: !assignment.is_difficult,
      });
      await Promise.all([this.load(this.grid()?.resources.current_page ?? 1), this.loadSummary()]);
      if (this.modal?.kind === 'project') await this.loadProject(this.modal.project);
      if (this.modal?.kind === 'resource') await this.loadResource(this.modal.resource);
    } catch (error) {
      errorSignal.set(this.message(error));
    } finally {
      this.busy.set(false);
    }
  }
  openResource(resource: Resource) {
    this.modal = { kind: 'resource', resource };
    this.resourceDetail.set(null);
    void this.loadResource(resource);
  }
  private async loadResource(resource: Resource) {
    const sequence = ++this.modalSequence;
    this.modalLoading.set(true);
    this.modalError.set('');
    try {
      const result = await this.request<{ data: Resource }>(
        'GET',
        '/resources/' + resource.id,
        undefined,
        { type: this.type },
      );
      if (sequence === this.modalSequence) this.resourceDetail.set(result.data);
    } catch (error) {
      if (sequence === this.modalSequence) this.modalError.set(this.message(error));
    } finally {
      if (sequence === this.modalSequence) this.modalLoading.set(false);
    }
  }
  openProject(project: Project) {
    this.modal = { kind: 'project', project };
    this.projectDetail.set(null);
    void this.loadProject(project);
  }
  private async loadProject(project: Project) {
    const sequence = ++this.modalSequence;
    this.modalLoading.set(true);
    this.modalError.set('');
    try {
      const result = await this.request<{ data: ProjectDetail }>(
        'GET',
        '/projects/' + project.id,
        undefined,
        { type: this.type },
      );
      if (sequence === this.modalSequence) this.projectDetail.set(result.data);
    } catch (error) {
      if (sequence === this.modalSequence) this.modalError.set(this.message(error));
    } finally {
      if (sequence === this.modalSequence) this.modalLoading.set(false);
    }
  }
  async openNeeds(project: Project) {
    this.modal = { kind: 'needs', project };
    this.projectDetail.set(null);
    this.needs = [];
    this.addNeed = false;
    this.newNeedId = null;
    this.removingNeed = null;
    await this.loadProject(project);
    if (this.modal?.kind === 'needs' && this.projectDetail()?.id === project.id)
      this.needs = this.projectDetail()!.necessities.flatMap((need) =>
        need.role ? [need.role.id] : [],
      );
  }
  appendNeed() {
    if (this.newNeedId && !this.needs.includes(this.newNeedId)) {
      this.needs = [...this.needs, this.newNeedId];
      this.newNeedId = null;
      this.addNeed = false;
    }
  }
  removeNeed(id: number) {
    this.needs = this.needs.filter((role) => role !== id);
    this.removingNeed = null;
  }
  async saveNeeds() {
    if (this.modal?.kind !== 'needs' || this.busy() || this.modalLoading()) return;
    this.busy.set(true);
    this.modalError.set('');
    try {
      await this.request('PUT', '/projects/' + this.modal.project.id + '/necessities', {
        assignment_role_ids: this.needs,
      });
      this.modal = null;
      this.notice.set('Necessità aggiornate.');
      await Promise.all([this.load(this.grid()?.resources.current_page ?? 1), this.loadSummary()]);
    } catch (error) {
      this.modalError.set(this.message(error));
    } finally {
      this.busy.set(false);
    }
  }
}
