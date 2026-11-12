import { Controller } from '@hotwired/stimulus';

/* Filtres et tri appliqués dès le changement (P02.3 « application instantanée ») ; sans JavaScript, bouton « Appliquer ». */
export default class extends Controller {
    submit() {
        this.element.requestSubmit();
    }
}
