/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/* BO04.1 — sélection de lignes : « n sélectionnée(s) », case « tout sélectionner ». */
export default class extends Controller {
    static targets = ['item', 'count'];

    connect() {
        this.update();
    }

    toggleAll(event) {
        this.itemTargets.forEach((item) => {
            item.checked = event.target.checked;
        });
        this.update();
    }

    update() {
        if (!this.hasCountTarget) {
            return;
        }
        const n = this.itemTargets.filter((item) => item.checked).length;
        this.countTarget.textContent = n === 0 ? 'Aucune sélection' : `${n} sélectionnée${n > 1 ? 's' : ''}`;
    }
}
