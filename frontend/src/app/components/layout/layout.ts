import { Component, input, output } from '@angular/core';
import { Header } from '../header/header';
import { Navbar } from '../navbar/navbar';

@Component({
  selector: 'app-layout',
  standalone: true,
  imports: [Header, Navbar],
  templateUrl: './layout.html',
  styleUrl: './layout.css',
})
export class Layout {
  readonly canAccessSettings = input(false);
  readonly openSettings = output<void>();
}
