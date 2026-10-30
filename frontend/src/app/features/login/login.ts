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
  errore = '';

  private auth = inject(Auth);
  private router = inject(Router);

  accedi(): void {
    this.errore = '';

    if (!this.email.trim() || !this.password) {
      this.errore = 'Inserisci email e password.';
      return;
    }

    const success = this.auth.login(
      this.email,
      this.password
    );

    if (success) {
      this.router.navigate(['/home']);
    }
  }
}
