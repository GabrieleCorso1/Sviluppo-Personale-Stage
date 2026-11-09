import { DatePipe } from '@angular/common';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { AfterViewInit, Component, Directive, ElementRef, DestroyRef, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { Layout } from '../../components/layout/layout';
import { API_URL } from '../../core/auth-http';
import { Auth, hasSettingsRole } from '../../core/services/auth';

import { SbiEditor } from './sbi-editor';
import { SbiImage } from './sbi-image';
import { ProfileAvatar } from '../../core/services/profile-avatar';

interface Person { id: number; first_name: string; last_name: string; avatar_url?: string; }
interface Project { id: number; alias: string; logo_url?: string; }
interface Draft {
  is_positive: boolean;
  event_date: string;
  situation: string;
  behavior: string;
  impact: string;
  suggestion: string;
  personal_notes: string;
}
interface Revision extends Omit<Draft, 'suggestion' | 'personal_notes'> {
  id: number;
  revision_number: number;
  created_at: string;
  suggestion: string | null;
  personal_notes?: string | null;
  has_personal_notes: boolean;
  reply: { body: string } | null;
}
interface Entry {
  id: number;
  writer: Person;
  receiver: Person;
  project: Project;
  created_at: string;
  latest_revision: Revision;
  revisions?: Revision[];
  revisions_count: number;
  can_update: boolean;
  can_delete: boolean;
  can_reply: boolean;
}
interface Page {
  data: Entry[];
  counts: { sent: number; received: number };
  meta: { current_page: number; last_page: number };
}
type Direction = 'sent' | 'received';
type Confirmation = { entry: Entry; revision?: Revision };

@Directive({ selector: 'dialog[sbiModal]' })
class SbiModal implements AfterViewInit {
  private readonly element = inject<ElementRef<HTMLDialogElement>>(ElementRef);
  ngAfterViewInit() { this.element.nativeElement.showModal(); }
}

@Component({
  imports: [DatePipe, FormsModule, Layout, SbiImage, SbiEditor, SbiModal],
  templateUrl: './sbi.html',
  styleUrls: ['./sbi.css', './sbi-wizard.css'],
})
export class Sbi {
  private readonly http = inject(HttpClient);
  private readonly destroyRef = inject(DestroyRef);
  private readonly auth = inject(Auth);
  private readonly router = inject(Router);
  private readonly endpoint = API_URL + '/api/sbis';
  private listRequest = 0;
  private detailRequest = 0;
  private optionsRequest = 0;

  private readonly avatar = inject(ProfileAvatar);
  readonly avatarUrl = this.avatar.url;
  readonly user = this.auth.currentUser;
  readonly avatarFallback = API_URL + '/images/defaults/default-avatar.svg';
  readonly logoFallback = API_URL + '/images/defaults/default-project.svg';
  readonly canCreate = computed(() => this.user()?.roles.some(role =>
    ['Admin', 'Project Manager', 'Team Leader'].includes(role)) ?? false);
  readonly settings = computed(() => { const user = this.user(); return !!user && hasSettingsRole(user); });
  readonly page = signal<Page | null>(null);
  readonly loading = signal(false);
  readonly error = signal('');
  readonly detail = signal<Entry | null>(null);
  readonly openId = signal<number | null>(null);
  readonly revisionId = signal<number | null>(null);
  readonly revision = computed(() => this.detail()?.revisions?.find(item => item.id === this.revisionId()));
  readonly detailError = signal('');
  readonly busy = signal(false);
  readonly notice = signal('');
  readonly people = signal<Person[]>([]);
  readonly peopleLastPage = signal(1);
  readonly peopleLoading = signal(false);
  readonly peopleError = signal('');
  peoplePage = 1;
  peopleSearch = '';
  subject: Person | null = null;
  direction: Direction = 'received';
  search = '';
  projectSearch = '';
  dateFrom = '';
  dateTo = '';
  type = '';
  response = '';
  reply: string | null = null;
  savedEntry: Entry | null = null;
  confirmation: Confirmation | null = null;
  editing: Entry | null = null;
  draft: Draft | null = null;
  step = 0;
  projectId: number | null = null;
  receiverId: number | null = null;
  readonly projects = signal<Project[]>([]);
  readonly receivers = signal<Person[]>([]);
  readonly optionsLoading = signal(false);
  readonly optionsError = signal('');
  readonly formError = signal('');
  readonly steps = ['Situation', 'Behaviour', 'Impact', 'Suggestion e note', 'Riepilogo'];
  readonly stepIcons = ['calendar-check', 'person-gear', 'lightning-charge', 'lightbulb', 'file-earmark-text'];
  readonly descriptions = ['Descrivi la situazione iniziale.', 'Descrivi il comportamento osservato.',
    'Descrivi gli effetti del comportamento.', 'Aggiungi suggerimenti e note personali.',
    'Controlla le informazioni prima di salvare.'];
  readonly textFields = ['situation', 'behavior', 'impact'] as const;

  constructor() {
    inject(ActivatedRoute).queryParamMap.pipe(takeUntilDestroyed(this.destroyRef)).subscribe(params => {
      const sent = params.has('mandato');
      const id = Number(params.get(sent ? 'mandato' : 'ricevuto'));
      const linkedId = Number.isSafeInteger(id) && id > 0 ? id : undefined;
      if (linkedId !== undefined) {
        this.direction = sent ? 'sent' : 'received';
        this.subject = null;
        this.search = this.projectSearch = this.dateFrom = this.dateTo = this.type = this.response = '';
      }
      void this.load(1, false, linkedId);
    });
    this.avatar.load().pipe(takeUntilDestroyed(this.destroyRef)).subscribe({ error: () => undefined });
  }

  private request<T>(method: string, path: string, options: { params?: Record<string, string | number>; body?: unknown } = {}) {
    return firstValueFrom(this.http.request<T>(method, this.endpoint + path, options)
      .pipe(takeUntilDestroyed(this.destroyRef)));
  }

  private message(error: unknown): string {
    if (error instanceof HttpErrorResponse) {
      const first = Object.values(error.error?.errors ?? {}).flat()[0];
      if (typeof first === 'string') return first;
      if (error.status === 403) return 'Non hai i permessi per questa operazione.';
      if (error.status === 404) return 'Questo elemento non è più disponibile.';
    }
    return 'Operazione non riuscita. Riprova.';
  }

  openSettings() { void this.router.navigate(['/impostazioni']); }

  preview(html: string): string {
    return new DOMParser().parseFromString(html, 'text/html').body.textContent ?? '';
  }

  name(person: Person): string { return person.first_name + ' ' + person.last_name; }

  async load(page = 1, preserveDetail = false, linkedId?: number) {
    if (!preserveDetail) this.closeDetail();
    const request = ++this.listRequest;
    this.error.set('');
    if (!preserveDetail) this.page.set(null);
    if (this.dateFrom && this.dateTo && this.dateFrom > this.dateTo) {
      this.loading.set(false);
      this.error.set('La data di fine deve essere uguale o successiva alla data di inizio.');
      return;
    }
    this.loading.set(true);
    const params: Record<string, string | number> = { direction: this.direction, page };
    if (this.subject) params['user_id'] = this.subject.id;
    if (linkedId !== undefined) params['sbi_id'] = linkedId;
    for (const [key, value] of Object.entries({
      search: this.search.trim(), project_search: this.projectSearch.trim(),
      date_from: this.dateFrom, date_to: this.dateTo, is_positive: this.type, response_status: this.response,
    })) {
      if (value) params[key] = value;
    }
    try {
      const result = await this.request<Page>('GET', '', { params });
      if (request !== this.listRequest) return;
      if (result.meta.current_page > result.meta.last_page) {
        void this.load(result.meta.last_page, preserveDetail);
        return;
      }
      this.page.set(result);
      if (linkedId !== undefined) {
        if (result.data.some(item => item.id === linkedId)) {
          void this.show(linkedId);
        } else {
          this.error.set('Questo SBI non è più disponibile nella tua casella.');
        }
      }
    } catch (error) {
      if (request === this.listRequest) this.error.set(this.message(error));
    } finally {
      if (request === this.listRequest) this.loading.set(false);
    }
  }

  resetFilters() {
    this.search = this.projectSearch = this.dateFrom = this.dateTo = this.type = this.response = '';
    void this.load();
  }

  async findPeople(page = 1) {
    if (this.peopleLoading()) return;
    this.peopleLoading.set(true);
    this.peopleError.set('');
    try {
      const result = await this.request<{ data: { data: Person[]; current_page: number; last_page: number } }>(
        'GET', '/users', { params: { search: this.peopleSearch.trim(), page } });
      this.people.set(result.data.data);
      this.peoplePage = result.data.current_page;
      this.peopleLastPage.set(result.data.last_page);
    } catch (error) { this.peopleError.set(this.message(error)); }
    finally { this.peopleLoading.set(false); }
  }

  selectSubject(person: Person | null) {
    this.subject = person;
    this.people.set([]);
    this.peopleError.set('');
    this.peopleSearch = '';
    void this.load();
  }

  closeDetail() {
    ++this.detailRequest;
    this.openId.set(null);
    this.detail.set(null);
    this.revisionId.set(null);
    this.reply = null;
    this.confirmation = null;
  }

  async show(id: number, revisionId?: number) {
    const request = ++this.detailRequest;
    this.openId.set(id);
    this.detail.set(null);
    this.reply = null;
    this.detailError.set('');
    try {
      const { data } = await this.request<{ data: Entry }>('GET', '/' + id, {
        params: revisionId ? { revision_id: revisionId } : {},
      });
      if (request !== this.detailRequest) return;
      this.detail.set(data);
      this.revisionId.set(revisionId ?? data.latest_revision.id);
    } catch (error) {
      if (request === this.detailRequest) this.detailError.set(this.message(error));
    }
  }

  startForm(entry?: Entry, revision?: Revision) {
    this.notice.set('');
    this.editing = entry ?? null;
    this.step = 0;
    this.formError.set('');
    this.optionsError.set('');
    this.projectId = entry?.project.id ?? null;
    this.receiverId = entry?.receiver.id ?? null;
    this.draft = {
      is_positive: revision?.is_positive ?? true, event_date: revision?.event_date ?? '',
      situation: revision?.situation ?? '', behavior: revision?.behavior ?? '', impact: revision?.impact ?? '',
      suggestion: revision?.suggestion ?? '', personal_notes: revision?.personal_notes ?? '',
    };
    if (!entry) void this.loadOptions();
  }

  async loadOptions() {
    const request = ++this.optionsRequest;
    this.receiverId = null;
    this.receivers.set([]);
    this.optionsError.set('');
    this.optionsLoading.set(true);
    const params: Record<string, string | number> = {};
    if (this.projectId && this.draft?.event_date) {
      params['project_id'] = this.projectId;
      params['event_date'] = this.draft.event_date;
    }
    try {
      const { data } = await this.request<{ data: { projects: Project[]; receivers: Person[] } }>(
        'GET', '/options', { params });
      if (request !== this.optionsRequest) return;
      this.projects.set(data.projects);
      this.receivers.set(data.receivers);
    } catch (error) {
      if (request === this.optionsRequest) this.optionsError.set(this.message(error));
    } finally {
      if (request === this.optionsRequest) this.optionsLoading.set(false);
    }
  }

  closeForm() {
    ++this.optionsRequest;
    this.draft = null;
  }

  next() {
    const draft = this.draft;
    if (!draft) return;
    if (this.step === 0 && (!draft.event_date || (!this.editing && (!this.projectId || !this.receiverId)))) {
      this.formError.set('Seleziona progetto, data e destinatario.');
      return;
    }
    const field = this.textFields[this.step];
    if (field && !draft[field].trim()) {
      this.formError.set('Compila il testo prima di continuare.');
      return;
    }
    this.formError.set('');
    this.step++;
  }

  async save() {
    if (this.busy() || !this.draft || this.step !== 4) return;
    const entry = this.editing;
    this.busy.set(true);
    this.formError.set('');
    try {
      const body = entry ? this.draft : { ...this.draft, project_id: this.projectId, receiver_id: this.receiverId };
      const { data } = await this.request<{ data: Entry }>(entry ? 'PUT' : 'POST', entry ? '/' + entry.id : '', { body });
      if (!entry) {
        this.savedEntry = data;
        return;
      }
      this.closeForm();
      this.notice.set(entry ? 'Nuova revisione salvata.' : 'SBI inviato.');
      void this.load(this.page()?.meta.current_page ?? 1, true);
      void this.show(entry.id);
    } catch (error) { this.formError.set(this.message(error)); }
    finally { this.busy.set(false); }
  }

  async viewSaved() {
    const entry = this.savedEntry;
    if (!entry) return;
    this.savedEntry = null;
    this.closeForm();
    this.direction = 'sent';
    this.subject = null;
    this.search = this.projectSearch = this.dateFrom = this.dateTo = this.type = this.response = '';
    await this.load();
    if (this.page()?.data.some(item => item.id === entry.id)) await this.show(entry.id);
  }

  async saveReply(entry: Entry, revision: Revision) {
    if (this.busy() || !this.reply?.trim()) return;
    this.busy.set(true);
    this.detailError.set('');
    try {
      const { data } = await this.request<{ data: Entry }>('PUT',
        '/' + entry.id + '/revisions/' + revision.id + '/reply', { body: { body: this.reply } });
      this.detail.set(data);
      this.reply = null;
      this.notice.set('Risposta salvata.');
      void this.load(this.page()?.meta.current_page ?? 1, true);
    } catch (error) { this.detailError.set(this.message(error)); }
    finally { this.busy.set(false); }
  }

  cancelModal(event: Event, kind: 'reply' | 'delete') {
    event.preventDefault();
    if (this.busy()) return;
    if (kind === 'reply') this.reply = null;
    else this.confirmation = null;
    this.detailError.set('');
  }

  async remove() {
    if (this.busy() || !this.confirmation) return;
    const { entry, revision } = this.confirmation;
    this.busy.set(true);
    this.detailError.set('');
    try {
      if (revision) {
        const { data } = await this.request<{ data: Entry }>('DELETE',
          '/' + entry.id + '/revisions/' + revision.id);
        this.detail.set(data);
        this.revisionId.set(data.latest_revision.id);
      } else {
        await this.request('DELETE', '/' + entry.id);
        this.closeDetail();
      }
      this.confirmation = null;
      this.notice.set(revision ? 'Revisione eliminata.' : 'SBI eliminato.');
      void this.load(this.page()?.meta.current_page ?? 1, true);
    } catch (error) { this.detailError.set(this.message(error)); }
    finally {
      this.busy.set(false);
    }
  }
}

