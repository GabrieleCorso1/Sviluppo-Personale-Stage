import { Component, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { Auth } from '../../core/services/auth';

@Component({
  imports: [],
  standalone: true,
  selector: 'app-home',
  styleUrl: './home.css',
  templateUrl: './home.html',
})
export class Home {
  errore = signal('');
  caricamento = signal(false);
  private auth = inject(Auth);
  private router = inject(Router);

  logout(): void {
    if (this.caricamento()) return;
    this.errore.set('');
    this.caricamento.set(true);
    this.auth.logout().subscribe({
      next: () => {
        this.caricamento.set(false);
        void this.router.navigate(['/login']);
      },
      error: () => {
        this.caricamento.set(false);
        this.errore.set('Impossibile completare il logout. Controlla la connessione e riprova.');
      }
    });
  }
}
