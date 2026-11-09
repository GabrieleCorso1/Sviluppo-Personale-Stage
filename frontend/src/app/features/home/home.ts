import { Component, computed, DestroyRef, inject, OnInit, signal } from '@angular/core';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { finalize, Observable, switchMap } from 'rxjs';
import { ProfileAvatar } from '../../core/services/profile-avatar';
import { Auth, hasSettingsRole } from '../../core/services/auth';
import { Router } from '@angular/router';
import { FormsModule } from '@angular/forms';
import { API_URL } from '../../core/auth-http';
import { CommonModule } from '@angular/common';
import { Layout } from '../../components/layout/layout';

@Component({
  selector: 'app-home',
  standalone: true,
  imports: [CommonModule, FormsModule, Layout],
  templateUrl: './home.html',
  styleUrl: './home.css',
})
export class Home implements OnInit {
  readonly roleTitle = 'Sviluppatore Front-end';

  protected readonly router = inject(Router);
  private readonly auth = inject(Auth);
  readonly user = this.auth.currentUser;
  readonly fullName = computed(() => {
    const user = this.auth.currentUser();
    return user ? [user.first_name, user.last_name].filter(Boolean).join(' ') : '';
  });
  readonly canAccessSettings = computed(() => {
    const user = this.auth.currentUser();
    return user !== null && hasSettingsRole(user);
  });

  private readonly http = inject(HttpClient);
  private readonly destroyRef = inject(DestroyRef);
  private readonly avatar = inject(ProfileAvatar);
  readonly avatarUrl = this.avatar.url;
  readonly avatarBusy = signal(true);
  readonly avatarError = signal('');

  ngOnInit(): void {
    this.avatar.load().pipe(
      takeUntilDestroyed(this.destroyRef),
      finalize(() => this.avatarBusy.set(false)),
    ).subscribe({
      error: () => this.avatarError.set('Impossibile caricare la foto del profilo.'),
    });
  }

  changeAvatar(input: HTMLInputElement, dialog: HTMLDialogElement): void {
    const file = input.files?.[0];
    input.value = '';
    if (!file || this.avatarBusy()) return;
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 2 * 1024 * 1024) {
      this.avatarError.set('Scegli una foto JPG, PNG o WebP di massimo 2 MB.');
      return;
    }
    const body = new FormData();
    body.append('avatar', file);
    this.updateAvatar(this.http.post(this.avatar.endpoint, body), dialog);
  }

  removeAvatar(dialog: HTMLDialogElement): void {
    if (this.avatarBusy()) return;
    this.updateAvatar(this.http.delete(this.avatar.endpoint), dialog);
  }

  private updateAvatar(request: Observable<unknown>, dialog: HTMLDialogElement): void {
    this.avatarBusy.set(true);
    this.avatarError.set('');
    request.pipe(
      switchMap(() => this.avatar.load(true)),
      takeUntilDestroyed(this.destroyRef),
      finalize(() => this.avatarBusy.set(false)),
    ).subscribe({
      next: () => dialog.close(),
      error: (error: HttpErrorResponse) => this.avatarError.set(
        error.error?.errors?.avatar?.[0] ?? 'Impossibile aggiornare la foto. Riprova.',
      ),
    });
  }

  // Apertura e chiusura modale
  modalePasswordAperta = false;
  passwordAttuale = '';
  nuovaPassword = '';
  confermaPassword = '';
  readonly passwordBusy = signal(false);
  readonly passwordError = signal('');

  get canSavePassword(): boolean {
    return !this.passwordBusy() && !!this.passwordAttuale && !!this.nuovaPassword
      && this.nuovaPassword === this.confermaPassword;
  }

  savePassword(): void {
    if (!this.canSavePassword) return;
    this.passwordBusy.set(true);
    this.passwordError.set('');

    this.http.put(API_URL + '/api/user/password', {
      current_password: this.passwordAttuale,
      password: this.nuovaPassword,
      password_confirmation: this.confermaPassword,
    }).pipe(
      takeUntilDestroyed(this.destroyRef),
      finalize(() => this.passwordBusy.set(false)),
    ).subscribe({
      next: () => {
        this.resetPasswordForm();
        this.auth.clearSession();
        void this.router.navigate(['/login'], {
          queryParams: { passwordReset: 'success' },
          replaceUrl: true,
        });
      },
      error: (error: HttpErrorResponse) => {
        const errors = error.error?.errors;
        this.passwordError.set(errors?.current_password?.[0] ?? errors?.password?.[0]
          ?? (error.status === 429 ? 'Troppi tentativi. Riprova tra un minuto.'
            : 'Impossibile aggiornare la password. Riprova.'));
      },
    });
  }

  private resetPasswordForm(): void {
    this.modalePasswordAperta = false;
    this.passwordAttuale = '';
    this.nuovaPassword = '';
    this.confermaPassword = '';
    this.passwordError.set('');
    this.mostraPasswordAttuale = false;
    this.mostraNuovaPassword = false;
    this.mostraConfermaPassword = false;
  }

  apriModalePassword(): void {
    this.modalePasswordAperta = true;
  }

  chiudiModalePassword(): void {
    if (!this.passwordBusy()) this.resetPasswordForm();
  }

  // Mostra/nascondi password
  mostraPasswordAttuale = false;
  mostraNuovaPassword = false;
  mostraConfermaPassword = false;
}
