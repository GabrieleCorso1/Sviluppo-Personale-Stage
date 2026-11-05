
import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { Auth } from '../../core/services/auth';

@Component({
  imports: [FormsModule, RouterLink],
  standalone: true,
  selector: 'app-login',
  styleUrl: './login.css',
  templateUrl: './login.html',
})
export class Login {
  email = '';
  password = '';
  remember = false;
  private route = inject(ActivatedRoute);
  errore = signal(this.initialError());
  caricamento = signal(false);
  messaggio = signal(this.route.snapshot.queryParamMap.get('passwordReset') === 'success'
    ? 'Password aggiornata. Accedi con la nuova password.' : '');

  private auth = inject(Auth);

  private initialError(): string {
    switch (this.route.snapshot.queryParamMap.get('reason')) {
      case 'session-expired':
        return 'Sessione scaduta o non più valida. Accedi nuovamente.';
      case 'verification-unavailable':
        return 'Impossibile verificare la sessione. Riprova tra poco: puoi riaprire la pagina richiesta.';
      default:
        return '';
    }
  }

  accedi(): void {
    if (this.caricamento()) return;
    this.errore.set('');
    this.messaggio.set('');

    if (!this.email.trim() || !this.password) {
      this.errore.set('Inserisci email e password.');
      return;
    }

    this.caricamento.set(true);

    this.auth.login(
      this.email.trim(),
      this.password,
      this.remember
    ).subscribe({
      next: () => {
        this.caricamento.set(false);
        this.messaggio.set('Accesso effettuato');
      },
      error: (err) => {
        this.caricamento.set(false);

        if (err.status === 422) {
          this.errore.set(err.error?.errors?.email?.[0] ||
            err.error?.message ||
            'Email o password non corrette.');
        } else if (err.status === 429) {
          this.errore.set(err.error?.message ||
            'Troppi tentativi. Riprova più tardi.');
        } else {
          this.errore.set('Errore di connessione al server. Riprova.');
        }
      }
    });
  }
}
