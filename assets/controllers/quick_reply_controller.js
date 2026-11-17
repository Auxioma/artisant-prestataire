import { Controller } from '@hotwired/stimulus';

/*
 * PR07.1 — insère une réponse type de l'artisan dans le champ de saisie (à la position du curseur), puis replie la
 * liste et rend la main au champ. Sans JavaScript, les réponses restent lisibles dans l'info-bulle.
 */
export default class extends Controller {
    static values = { field: String };

    insert(event) {
        const field = document.getElementById(this.fieldValue);
        const text = event.params.text;
        if (!field || typeof text !== 'string') {
            return;
        }
        const start = field.selectionStart ?? field.value.length;
        const end = field.selectionEnd ?? field.value.length;
        field.value = field.value.slice(0, start) + text + field.value.slice(end);
        field.selectionStart = field.selectionEnd = start + text.length;
        this.element.open = false;
        field.focus();
    }
}
