import { RouterLink, RouterLinkActive } from '@angular/router';
import { Component, output } from '@angular/core';

@Component({
  selector: 'app-navbar',
  standalone: true, imports: [RouterLink, RouterLinkActive],
  templateUrl: './navbar.html',
  styleUrl: './navbar.css',
})
export class Navbar {
  readonly closeMenu = output<void>();
  readonly navigated = output<void>();
}
