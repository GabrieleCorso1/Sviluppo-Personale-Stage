import { HttpClient } from '@angular/common/http';
import { computed, effect, inject, Injectable, signal } from '@angular/core';
import { map, Observable, of } from 'rxjs';
import { API_URL } from '../auth-http';
import { Auth } from './auth';

@Injectable({ providedIn: 'root' })
export class ProfileAvatar {
  private readonly auth = inject(Auth);
  private readonly http = inject(HttpClient);
  private readonly photo = signal<{ userId: number | undefined; url: string } | null>(null);
  readonly endpoint = API_URL + '/api/user/avatar';
  readonly url = computed(() => {
    const photo = this.photo();
    return photo && photo.userId === this.auth.currentUser()?.id
      ? photo.url
      : '/img/profiloUtente/Icon Avatar.png';
  });

  constructor() {
    effect(() => {
      const userId = this.auth.currentUser()?.id;
      const photo = this.photo();
      if (photo && photo.userId !== userId) {
        URL.revokeObjectURL(photo.url);
        this.photo.set(null);
      }
    });
  }

  load(refresh = false): Observable<void> {
    const userId = this.auth.currentUser()?.id;
    const cached = this.photo();
    if (!refresh && cached && cached.userId === userId) return of(undefined);

    return this.http.get(this.endpoint, { responseType: 'blob' }).pipe(
      map(blob => {
        if (this.auth.currentUser()?.id !== userId) return;
        const previous = this.photo();
        this.photo.set({ userId, url: URL.createObjectURL(blob) });
        if (previous) URL.revokeObjectURL(previous.url);
      }),
    );
  }
}
