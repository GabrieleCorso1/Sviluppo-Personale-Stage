import { Component, inject, signal } from '@angular/core';
import { Theme } from './core/services/theme';
import { RouterOutlet } from '@angular/router';

@Component({
  imports: [RouterOutlet],
  selector: 'app-root',
  styleUrl: './app.css',
  templateUrl: './app.html',
  standalone: true,
})
export class App {
  private readonly theme = inject(Theme);
  protected readonly title = signal('frontend');
}
