import { Component, ElementRef, inject, signal, viewChild } from '@angular/core';
import { Theme, ThemePreference } from '../../core/services/theme';

@Component({
  selector: 'app-theme-picker',
  standalone: true,
  templateUrl: './theme-picker.html',
  styleUrl: './theme-picker.css',
  host: { '(document:click)': 'closeOutside($event)', '(keydown.escape)': 'closeWithKeyboard($event)' },
})
export class ThemePicker {
  protected readonly theme = inject(Theme);
  protected readonly open = signal(false);
  private readonly element = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly trigger = viewChild.required<ElementRef<HTMLButtonElement>>('trigger');
  protected readonly choices: { value: ThemePreference; label: string; icon: string }[] = [
    { value: 'system', label: 'Sistema', icon: 'display' },
    { value: 'light', label: 'Chiaro', icon: 'sun' },
    { value: 'dark', label: 'Scuro', icon: 'moon-stars' },
  ];

  protected label(): string {
    return this.choices.find(choice => choice.value === this.theme.preference())!.label;
  }

  protected choose(value: ThemePreference): void {
    this.theme.setPreference(value);
  }

  protected closeOutside(event: MouseEvent): void {
    if (!this.element.nativeElement.contains(event.target as Node)) this.open.set(false);
  }

  protected closeWithKeyboard(event: Event): void {
    if (!this.open()) return;
    event.stopPropagation();
    this.open.set(false);
    this.trigger().nativeElement.focus();
  }
}
