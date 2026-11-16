import { Pipe, PipeTransform } from '@angular/core';

@Pipe({ name: 'duration' })
export class DurationPipe implements PipeTransform {
  transform(hours: number | null | undefined): string {
    if (hours === null || hours === undefined || !Number.isFinite(hours)) return 'Non disponibile';
    const total = Math.round(Math.abs(hours) * 60);
    const sign = hours < 0 ? '−' : '';
    return `${sign}${Math.floor(total / 60)} h ${String(total % 60).padStart(2, '0')} min`;
  }
}
