
import { Component, inject } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { Auth } from '../../core/services/auth';

@Component({
  imports: [FormsModule],
  standalone: true,
  selector: 'app-login',
  styleUrl: './login.css',
  templateUrl: './login.html',
})
export class Login {
  email = '';
  password = '';
  remember = false;
  errore = '';
  caricamento = false;

  private auth = inject(Auth);
  private router = inject(Router);

  accedi(): void {
    this.errore = '';

    if (!this.email.trim() || !this.password) {
      this.errore = 'Inserisci email e password.';
      return;
    }

    this.caricamento = true;

    this.auth.login(
      this.email.trim(),
      this.password,
      this.remember
    ).subscribe({
      next: () => {
        this.caricamento = false;
        this.router.navigate(['/home']);
      },
      error: (err) => {
        this.caricamento = false;

        if (err.status === 422) {
          this.errore =
            err.error?.message ||
            err.error?.errors?.email?.[0] ||
            'Email o password non corrette.';
        } else if (err.status === 429) {
          this.errore =
            err.error?.message ||
            'Troppi tentativi. Riprova più tardi.';
        } else {
          this.errore =
            'Errore di connessione al server. Riprova.';
        }
      }
    });
  }
}
