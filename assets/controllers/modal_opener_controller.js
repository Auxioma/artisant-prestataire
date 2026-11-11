import { Controller } from '@hotwired/stimulus';
import { openDialog } from './modal_controller.js';

/* Déclencheur d'une modale : data-modal-opener-dialog-value="<id du dialog>". */
export default class extends Controller {
    static values = { dialog: String };

    open(event) {
        event.preventDefault();
        const dialog = document.getElementById(this.dialogValue);
        if (dialog instanceof HTMLDialogElement) {
            openDialog(dialog, this.element);
        }
    }
}
