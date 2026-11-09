import { Component, model, input, viewChild, ElementRef, AfterViewInit, inject, SecurityContext } from '@angular/core';
import { DomSanitizer } from '@angular/platform-browser';

@Component({
  selector: 'app-sbi-editor',
  template: `
    <div class="editor">
      <div class="tools" role="group" aria-label="Formattazione">
        @for (tool of tools; track tool.command) {
          <button type="button" [attr.aria-label]="tool.label" [title]="tool.label"
            (mousedown)="$event.preventDefault()" (click)="format(tool.command)">
            <i [class]="'bi bi-' + tool.icon" aria-hidden="true"></i>
          </button>
        }
      </div>
      <div #field class="field" contenteditable="true" role="textbox" aria-multiline="true"
        [attr.aria-label]="label()" (input)="sync()" (paste)="paste($event)"></div>
    </div>
  `,
  styles: `
    :host { display: block; }
    .editor { border: 1px solid #d6dfe7; border-radius: 7px; background: #fff; }
    .tools { display: flex; flex-wrap: wrap; gap: 4px; padding: 5px 8px; border-bottom: 1px solid #edf0f3; }
    button { color: #233347; border: 0; border-radius: 4px; background: transparent; padding: 4px 6px; }
    button:hover { background: #edf4fb; }
    .field { min-height: var(--editor-height, 130px); padding: 12px; overflow-wrap: anywhere; font-size: .875rem; }
  `,
})
export class SbiEditor implements AfterViewInit {
  readonly value = model.required<string>();
  readonly label = input.required<string>();
  private readonly field = viewChild.required<ElementRef<HTMLDivElement>>('field');
  private readonly sanitizer = inject(DomSanitizer);
  readonly tools = [
    { command: 'undo', label: 'Annulla ultima modifica', icon: 'arrow-counterclockwise' },
    { command: 'redo', label: 'Ripeti ultima modifica', icon: 'arrow-clockwise' },
    { command: 'bold', label: 'Grassetto', icon: 'type-bold' },
    { command: 'italic', label: 'Corsivo', icon: 'type-italic' },
    { command: 'underline', label: 'Sottolineato', icon: 'type-underline' },
    { command: 'strikeThrough', label: 'Barrato', icon: 'type-strikethrough' },
    { command: 'insertUnorderedList', label: 'Elenco puntato', icon: 'list-ul' },
    { command: 'insertOrderedList', label: 'Elenco numerato', icon: 'list-ol' },
  ];

  ngAfterViewInit() {
    this.field().nativeElement.innerHTML = this.sanitizer.sanitize(SecurityContext.HTML, this.value()) ?? '';
  }

  format(command: string) {
    const field = this.field().nativeElement;
    field.focus();
    field.ownerDocument.execCommand(command);
    this.sync();
  }

  paste(event: ClipboardEvent) {
    event.preventDefault();
    this.field().nativeElement.ownerDocument.execCommand('insertText', false, event.clipboardData?.getData('text/plain') ?? '');
    this.sync();
  }

  sync() {
    const field = this.field().nativeElement;
    this.value.set(field.innerText.trim() ? field.innerHTML.replace(/<div>/gi, '<p>').replace(/<\/div>/gi, '</p>') : '');
  }
}

