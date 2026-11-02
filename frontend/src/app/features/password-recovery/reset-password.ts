import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { PasswordRecovery } from '../../core/services/password-recovery';
import { Auth } from '../../core/services/auth';

@Component({
  selector: 'app-reset-password',
  standalone: true,
  imports: [FormsModule, RouterLink],
  templateUrl: './reset-password.html',
  styleUrl: './password-recovery.css'
})
export class ResetPassword {
  private route = inject(ActivatedRoute);
  private recovery = inject(PasswordRecovery);
  private auth = inject(Auth);
  private router = inject(Router);
  private token = this.route.snapshot.queryParamMap.get('token') || '';
  private email = this.route.snapshot.queryParamMap.get('email') || '';
  password = '';
  confirmation = '';
  caricamento = signal(false);
  linkNonValido = signal(!this.token || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(this.email));
  errore = signal(this.linkNonValido() ? 'Link di recupero incompleto o non valido. Richiedine uno nuovo.' : '');

  aggiorna(): void {
    if (this.caricamento() || this.linkNonValido()) return;
    this.errore.set('');
    if (Array.from(this.password).length < 8) {
      this.errore.set('La password deve contenere almeno 8 caratteri.');
      return;
    }
    if (this.password !== this.confirmation) {
      this.errore.set('Le password non coincidono.');
      return;
    }
    this.caricamento.set(true);
    this.recovery.resetPassword(this.token, this.email, this.password, this.confirmation).subscribe({
      next: () => {
        this.auth.clearSession();
        this.password = '';
        this.confirmation = '';
        this.caricamento.set(false);
        void this.router.navigate(['/login'], {
          queryParams: { passwordReset: 'success' },
          replaceUrl: true
        });
      },
      error: err => {
        this.caricamento.set(false);
        if (err.status === 422) {
          const errors = err.error?.errors;
          if (errors?.email || errors?.token) {
            this.linkNonValido.set(true);
            this.errore.set('Link di recupero non valido o scaduto. Richiedine uno nuovo.');
          } else {
            this.errore.set(errors?.password?.[0] || errors?.password_confirmation?.[0] ||
              'Controlla la nuova password e la conferma.');
          }
        } else {
          this.errore.set(err.status === 429
            ? 'Troppi tentativi. Attendi qualche minuto e riprova.'
            : 'Impossibile aggiornare la password. Controlla la connessione e riprova.');
        }
      }
    });
  }
}
