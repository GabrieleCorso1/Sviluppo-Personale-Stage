import { Component, input, output } from '@angular/core';

@Component({
  selector: 'app-header',
  standalone: true,
  templateUrl: './header.html',
  styleUrl: './header.css',
})
export class Header {
  readonly avatarEditable = input(false);
  readonly avatarUrl = input<string>();
  readonly pageTitle = input('Impostazioni');
  readonly canAccessSettings = input(false);
  readonly openAvatar = output<void>();
  readonly openSettings = output<void>();
}
