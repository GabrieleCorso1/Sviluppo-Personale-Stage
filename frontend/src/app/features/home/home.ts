import { Component } from '@angular/core';
import { CommonModule } from '@angular/common';

@Component({
  selector: 'app-home',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './home.html',
  styleUrl: './home.css',
})
export class Home {
  user = {
    name: 'Claudia Leotta',
    roleTitle: 'Sviluppatore Front-end',
    role: 'Utente',
    hireDate: '01/10/2025',
    email: 'email@example.com',
  };

  avatarUrl = '/img/profiloUtente/Icon Avatar.png';

  // Apertura e chiusura modale
  modalePasswordAperta = false;

  apriModalePassword(): void {
    this.modalePasswordAperta = true;
  }

  chiudiModalePassword(): void {
    this.modalePasswordAperta = false;
  }

  // Mostra/nascondi password
  mostraPasswordAttuale = false;
  mostraNuovaPassword = false;
  mostraConfermaPassword = false;
}
