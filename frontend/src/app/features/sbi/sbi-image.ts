import { HttpClient } from '@angular/common/http';
import { Directive, effect, inject, input, signal } from '@angular/core';
import { API_URL } from '../../core/auth-http';

@Directive({
  selector: 'img[sbiImage]',
  host: { '[attr.src]': 'src()' },
})
export class SbiImage {
  readonly sbiImage = input.required<string>();
  readonly imageFallback = input.required<string>();
  protected readonly src = signal('');
  private readonly http = inject(HttpClient);

  constructor() {
    effect(onCleanup => {
      const fallback = this.imageFallback();
      this.src.set(fallback);
      const path = new URL(this.sbiImage(), API_URL).pathname;
      if (!path.startsWith('/api/sbis/')) return;
      let objectUrl: string | undefined;
      const subscription = this.http.get(API_URL + path, { responseType: 'blob' }).subscribe({
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
