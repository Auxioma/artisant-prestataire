/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/* Délai avant un nouvel envoi (CDC A02 : « bouton renvoyer, délai 60 s ») ; le serveur applique le même délai. */
export default class extends Controller {
    static targets = ['button', 'hint'];
    static values = { seconds: Number };

    connect() {
        this.remaining = this.secondsValue;
        this.tick();
        this.timer = setInterval(() => this.tick(), 1000);
    }

    disconnect() {
        clearInterval(this.timer);
    }

    tick() {
        const button = this.buttonTarget.matches('button') ? this.buttonTarget : this.buttonTarget.querySelector('button');
        if (this.remaining <= 0) {
            clearInterval(this.timer);
            button.disabled = false;
            this.hintTarget.textContent = '';
            return;
        }
        button.disabled = true;
        this.hintTarget.textContent = `Vous pourrez renvoyer l’e-mail dans ${this.remaining} s.`;
        this.remaining -= 1;
    }
}
