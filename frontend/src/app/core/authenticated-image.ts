import { HttpClient } from '@angular/common/http';
import { Directive, effect, inject, input, signal } from '@angular/core';
import { API_URL } from './auth-http';

@Directive({
  selector: 'img[authenticatedImage]',
  host: { '[attr.src]': 'src()' },
})
export class AuthenticatedImage {
  readonly authenticatedImage = input.required<string>();
  readonly imageFallback = input<string | null>(null);
  protected readonly src = signal<string | null>(null);
  private readonly http = inject(HttpClient);

  constructor() {
    effect(onCleanup => {
      const fallback = this.imageFallback();
      this.src.set(fallback);
      const { pathname: path, search } = new URL(this.authenticatedImage(), API_URL);
      if (!path.startsWith('/api/sbis/') && !/^\/api\/notifications\/[a-f0-9-]+\/avatar$/i.test(path)) return;
      let objectUrl: string | undefined;
      const subscription = this.http.get(API_URL + path + search, { responseType: 'blob' }).subscribe({
        next: blob => {
          objectUrl = URL.createObjectURL(blob);
          this.src.set(objectUrl);
        },
        error: () => this.src.set(fallback),
      });
      onCleanup(() => {
        subscription.unsubscribe();
        if (objectUrl) URL.revokeObjectURL(objectUrl);
      });
    });
  }
}
