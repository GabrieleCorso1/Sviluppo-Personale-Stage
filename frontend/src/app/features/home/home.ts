import { Component, computed, DestroyRef, inject, OnInit, signal } from '@angular/core';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { finalize, Observable, switchMap } from 'rxjs';
import { ProfileAvatar } from '../../core/services/profile-avatar';
import { Auth, hasSettingsRole } from '../../core/services/auth';
import { Router } from '@angular/router';
import { CommonModule } from '@angular/common';
import { Layout } from '../../components/layout/layout';

@Component({
  selector: 'app-home',
  standalone: true,
  imports: [CommonModule, Layout],
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

  apriModalePassword(): void {
    this.modalePasswordAperta = true;
  }

  chiudiModalePassword(): void {
    this.modalePasswordAperta = false;
  }

  // Mostra/nascondi password
  mostraPasswordAttuale = false;
  mostraNuovaPassword = false;
  mostraConfermaPassword = false;
}
