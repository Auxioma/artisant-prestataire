/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/*
 * C04 / PR07 — pièce jointe d'un message (et photos d'un avis, C06) : affiche le ou les fichiers choisis (le bouton ouvre
 * le sélecteur natif). Sans JavaScript, le champ fichier reste utilisable tel quel.
 */
export default class extends Controller {
    static targets = ['input', 'name'];
    static values = { label: { type: String, default: 'Pièce jointe' } };

    pick() {
        const names = Array.from(this.inputTarget.files ?? []).map((file) => file.name);
        this.nameTarget.hidden = names.length === 0;
        this.nameTarget.textContent = names.length ? `${this.labelValue} : ${names.join(', ')}` : '';
    }
}
