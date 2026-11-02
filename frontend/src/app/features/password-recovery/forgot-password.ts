import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { PasswordRecovery, RECOVERY_MESSAGE } from '../../core/services/password-recovery';

@Component({
  selector: 'app-forgot-password',
  standalone: true,
  imports: [FormsModule, RouterLink],
  templateUrl: './forgot-password.html',
  styleUrl: './password-recovery.css'
})
export class ForgotPassword {
  email = '';
  caricamento = signal(false);
  errore = signal('');
  messaggio = signal('');
  private recovery = inject(PasswordRecovery);

  invia(): void {
    if (this.caricamento()) return;
    this.errore.set('');
    this.messaggio.set('');
    const email = this.email.trim();
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      this.errore.set('Inserisci un indirizzo email valido.');
      return;
    }
    this.caricamento.set(true);
    this.recovery.requestLink(email).subscribe({
      next: () => {
        this.caricamento.set(false);
        this.messaggio.set(RECOVERY_MESSAGE);
      },
      error: err => {
        this.caricamento.set(false);
        this.errore.set(err.status === 422
          ? err.error?.errors?.email?.[0] || 'Inserisci un indirizzo email valido.'
          : err.status === 429
            ? 'Troppi tentativi. Attendi qualche minuto e riprova.'
            : 'Impossibile inviare la richiesta. Controlla la connessione e riprova.');
      }
    });
  }
}
