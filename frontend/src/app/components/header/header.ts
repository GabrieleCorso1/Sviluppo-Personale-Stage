import { Component, ElementRef, inject, input, output, signal } from '@angular/core';

import { toObservable, toSignal } from '@angular/core/rxjs-interop';
import { Router } from '@angular/router';
import { catchError, EMPTY, map, merge, of, startWith, switchMap, timer } from 'rxjs';
import { Auth } from '../../core/services/auth';
import { Notifications } from '../../core/services/notifications';
import { NotificationList } from '../../features/notifiche/notification-list';
import { ThemePicker } from '../theme-picker/theme-picker';

@Component({
  selector: 'app-header',
  standalone: true,
  imports: [NotificationList, ThemePicker],
  templateUrl: './header.html',
  styleUrl: './header.css',
  host: { '(document:click)': 'closeOutside($event)' },
})
export class Header {
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  protected readonly auth = inject(Auth);
  private readonly router = inject(Router);
  protected readonly loggingOut = signal(false);
  protected readonly accountError = signal('');
  protected readonly accountOpen = signal(false);
  protected readonly notificationsOpen = signal(false);
  private readonly notifications = inject(Notifications);
  protected readonly unreadCount = toSignal(toObservable(this.auth.currentUser).pipe(
    switchMap(user => user ? merge(timer(0, 30_000), this.notifications.changed).pipe(
      switchMap(() => this.notifications.list({ unread: true }, 1, 1).pipe(
        map(page => page.unread_count),
        catchError(() => EMPTY),
      )),
      startWith(0),
    ) : of(0)),
  ), { initialValue: 0 });
  readonly avatarEditable = input(false);
  readonly menuOpen = input(false);
  readonly toggleMenu = output<void>();
  readonly avatarUrl = input<string>();
  readonly pageTitle = input('Impostazioni');
  readonly canAccessSettings = input(false);
  readonly openAvatar = output<void>();
  readonly openSettings = output<void>();

  protected toggleAccount(): void {
    if (this.loggingOut()) return;
    this.notificationsOpen.set(false);
    this.accountError.set('');
    this.accountOpen.update(open => !open);
  }

  protected closeAccount(restoreFocus = false): void {
    if (this.loggingOut()) return;
    this.accountOpen.set(false);
    if (restoreFocus) this.host.nativeElement.querySelector<HTMLButtonElement>('.avatar-button')?.focus();
  }

  protected closeAccountOnBlur(event: FocusEvent): void {
    if (event.relatedTarget && !this.host.nativeElement.querySelector('.account-menu')?.contains(event.relatedTarget as Node)) {
      this.closeAccount();
    }
  }

  protected goToProfile(): void {
    if (this.loggingOut()) return;
    this.accountOpen.set(false);
    void this.router.navigate(['/home']);
  }

  protected logout(): void {
    if (this.loggingOut()) return;
    this.loggingOut.set(true);
    this.accountError.set('');
    // This finite request must complete even if the header is destroyed during navigation.
    this.auth.logout().subscribe({
      next: () => {
        this.loggingOut.set(false);
        this.accountOpen.set(false);
        void this.router.navigate(['/login']);
      },
      error: () => {
        this.loggingOut.set(false);
        this.accountError.set('Impossibile uscire. Controlla la connessione e riprova.');
      },
    });
  }

  protected closeOutside(event: MouseEvent): void {
    if (this.accountOpen() && !this.host.nativeElement.querySelector('.account-menu')?.contains(event.target as Node)) {
      this.closeAccount();
    }
    if (this.notificationsOpen() && !this.host.nativeElement.querySelector('.notification-bell')?.contains(event.target as Node)) {
      this.notificationsOpen.set(false);
    }
  }

  protected closeFromPreview(event: Event, bell: HTMLButtonElement): void {
    if ((event.target as HTMLElement).closest('dialog')) return;
    this.notificationsOpen.set(false);
    bell.focus();
  }
}
