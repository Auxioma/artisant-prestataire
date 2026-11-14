import { Controller } from '@hotwired/stimulus';

/*
 * C04 / PR07 — pièce jointe d'un message : affiche le nom du fichier choisi sous la saisie (le trombone ouvre le
 * sélecteur natif). Sans JavaScript, le champ fichier reste utilisable tel quel.
 */
export default class extends Controller {
    static targets = ['input', 'name'];

    pick() {
        const file = this.inputTarget.files?.[0];
        this.nameTarget.hidden = !file;
        this.nameTarget.textContent = file ? `Pièce jointe : ${file.name}` : '';
    }
}
