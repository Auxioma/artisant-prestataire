/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/*
 * C07 « Rayon de recherche » : curseur et champ chiffré synchronisés. Seul le champ chiffré est envoyé ; sans JavaScript,
 * il reste utilisable seul.
 */
export default class extends Controller {
    static targets = ['range', 'number'];

    connect() {
        this.rangeTarget.hidden = false;
    }

    slide() {
        this.numberTarget.value = this.rangeTarget.value;
    }

    type() {
        if (this.numberTarget.value !== '') {
            this.rangeTarget.value = this.numberTarget.value;
        }
    }
}
