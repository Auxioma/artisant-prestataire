import { Controller } from '@hotwired/stimulus';

/*
 * A03.6 — glisser-déposer d'un justificatif sur la zone de dépôt : le fichier déposé rejoint le champ « fichier »
 * et le formulaire est envoyé (type de pièce choisi dans la liste). Sans JavaScript : choix du fichier puis « Envoyer ».
 */
export default class extends Controller {
    static targets = ['input', 'hint', 'submit'];

    over(event) {
        event.preventDefault();
        this.element.classList.add('tm-dropzone--over');
    }

    leave() {
        this.element.classList.remove('tm-dropzone--over');
    }

    drop(event) {
        event.preventDefault();
        this.leave();
        const files = event.dataTransfer?.files;
        if (!files || files.length === 0) {
            return;
        }
        const transfer = new DataTransfer();
        transfer.items.add(files[0]); // une pièce à la fois : le type choisi s'applique à ce fichier
        this.inputTarget.files = transfer.files;
        this.chosen({ target: this.inputTarget });
    }

    chosen(event) {
        const file = event.target.files?.[0];
        if (!file) {
            return;
        }
        this.hintTarget.textContent = `Envoi de « ${file.name} »…`;
        this.submitTarget.form.requestSubmit(this.submitTarget);
    }
}
