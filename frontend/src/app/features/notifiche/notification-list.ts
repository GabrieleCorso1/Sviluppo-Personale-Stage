import { DatePipe } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { Component, DestroyRef, computed, inject, input, OnInit, output, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { catchError, EMPTY, Observable, Subject, switchMap } from 'rxjs';
import { RouterLink } from '@angular/router';
import { NOTIFICATION_EXAMPLES } from './notification-examples';
import {
  NotificationFilters, NotificationItem, NotificationPage, Notifications,
  NotificationSelection,
} from '../../core/services/notifications';

const CATEGORY_ICONS: Record<string, string> = {
  calendar: 'calendar3', shares: 'share', feedback360: 'chat-dots', gamification: 'controller',
  hobby: 'puzzle', goals: 'flag', projects: 'people', surveys: 'clipboard-data', review: 'eye',
  sbi: 'credit-card', suggestions: 'lightbulb', wiki: 'globe2', timesheet: 'calendar-check',
};

@Component({
  selector: 'app-notification-list',
  imports: [DatePipe, RouterLink],
  templateUrl: './notification-list.html',
  styleUrl: './notification-list.css',
})
export class NotificationList implements OnInit {
  readonly preview = input(false);
  readonly dismiss = output<void>();

  private readonly api = inject(Notifications);
  private readonly destroyRef = inject(DestroyRef);
  private readonly requests = new Subject<number>();
  protected readonly result = signal<NotificationPage | null>(null);
  protected readonly categories = signal<string[]>([]);
  protected readonly unread = signal(false);
  protected readonly loading = signal(false);
  protected readonly saving = signal(false);
  protected readonly loadError = signal(false);
  protected readonly actionError = signal('');
  protected readonly selected = signal<string[]>([]);
  protected readonly allSelected = signal(false);
  protected readonly deletion = signal<{ selection: NotificationSelection; count: number } | null>(null);
  protected readonly examples = computed(() => {
    const categories = this.categories();
    return NOTIFICATION_EXAMPLES.filter(item => !categories.length || categories.includes(item.category));
  });

  ngOnInit(): void {
    this.requests.pipe(
      switchMap(page => this.api.list(this.filters(), page, this.preview() ? 6 : 20).pipe(
        catchError(() => {
          this.loading.set(false);
          this.loadError.set(true);
          return EMPTY;
        }),
      )),
      takeUntilDestroyed(this.destroyRef),
    ).subscribe(result => {
      if (result.current_page > result.last_page) {
        this.load(result.last_page);
        return;
      }
      this.result.set(result);
      this.loading.set(false);
    });
    this.api.changed.pipe(takeUntilDestroyed(this.destroyRef)).subscribe(() => {
      this.resetSelection();
      this.load(this.result()?.current_page);
    });
    this.load();
  }

  protected load(page = 1): void {
    this.loading.set(true);
    this.loadError.set(false);
    this.requests.next(page);
  }

  protected icon(category: string): string {
    return 'bi bi-' + (CATEGORY_ICONS[category] ?? 'bell');
  }

  protected label(category: string): string {
    return this.result()?.categories.find(item => item.key === category)?.label ?? category;
  }

  protected toggleCategory(category: string): void {
    this.categories.update(items => items.includes(category)
      ? items.filter(item => item !== category)
      : [...items, category]);
    this.resetSelection();
    this.load();
  }

  protected selectTab(unread: boolean): void {
    if (this.unread() === unread) return;
    this.unread.set(unread);
    this.resetSelection();
    this.load();
  }

  protected toggleItem(id: string): void {
    if (this.allSelected()) {
      this.allSelected.set(false);
      this.selected.set((this.result()?.data ?? []).filter(item => item.id !== id).map(item => item.id));
      return;
    }
    this.selected.update(ids => ids.includes(id) ? ids.filter(value => value !== id) : [...ids, id]);
  }

  protected toggleAll(): void {
    this.selected.set([]);
    this.allSelected.update(value => !value);
  }

  protected goTo(page: number): void {
    if (!this.allSelected()) this.selected.set([]);
    this.load(page);
  }

  protected mark(read: boolean, id?: string): void {
    this.mutate(this.api.mark(this.selection(id), read));
  }

  protected askToDelete(dialog: HTMLDialogElement, id?: string): void {
    const count = id ? 1 : this.allSelected() ? this.result()?.total ?? 0 : this.selected().length;
    this.deletion.set({ selection: this.selection(id), count });
    dialog.showModal();
  }

  protected confirmDelete(dialog: HTMLDialogElement): void {
    const deletion = this.deletion();
    if (!deletion) return;
    dialog.close();
    this.mutate(this.api.remove(deletion.selection));
  }

  protected decide(item: NotificationItem, status: 'approved' | 'rejected'): void {
    this.mutate(this.api.decide(item.id, status));
  }

  private filters(): NotificationFilters {
    return {
      ...(this.unread() ? { unread: true } : {}),
      ...(this.categories().length ? { categories: this.categories() } : {}),
    };
  }

  private selection(id?: string): NotificationSelection {
    if (id) return { ids: [id] };
    return this.allSelected() ? { all: true, filters: this.filters() } : { ids: this.selected() };
  }

  private resetSelection(): void {
    this.selected.set([]);
    this.allSelected.set(false);
    this.actionError.set('');
  }

  private mutate(request: Observable<unknown>): void {
    if (this.saving() || this.loading()) return;
    this.saving.set(true);
    this.actionError.set('');
    request.pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: () => {
        this.saving.set(false);
        this.api.refreshLists();
      },
      error: (error: HttpErrorResponse) => {
        this.saving.set(false);
        if (error.status === 409) this.load(this.result()?.current_page);
        this.actionError.set(error.status === 409
          ? 'La richiesta è già stata gestita.'
          : 'Operazione non riuscita. Riprova.');
      },
    });
  }
}
