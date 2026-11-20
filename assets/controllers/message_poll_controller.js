/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/*
 * Messagerie C04 / PR07 — fil tenu à jour par interrogation périodique (repli prévu par le CDC, pas de serveur
 * WebSocket) : toutes les `interval` secondes tant que l'onglet est visible, le fragment des messages est rechargé ;
 * la vue reste en bas si elle y était. Sans JavaScript, la page se recharge à l'envoi.
 */
export default class extends Controller {
    static values = { url: String, interval: { type: Number, default: 15 } };
    static targets = ['list'];

    connect() {
        this.scrollToEnd();
        this.timer = setInterval(() => this.refresh(), this.intervalValue * 1000);
    }

    disconnect() {
        clearInterval(this.timer);
    }

    async refresh() {
        if (document.hidden || !this.hasListTarget) {
            return;
        }
        try {
            const response = await fetch(this.urlValue, { headers: { Accept: 'text/html' }, credentials: 'same-origin' });
            if (!response.ok) {
                return;
            }
            const html = await response.text();
            if (html === this.listTarget.innerHTML) {
                return;
            }
            const atEnd = this.listTarget.scrollHeight - this.listTarget.scrollTop - this.listTarget.clientHeight < 40;
            this.listTarget.innerHTML = html;
            if (atEnd) {
                this.scrollToEnd();
            }
        } catch {
            // Réseau indisponible : nouvel essai au prochain intervalle.
        }
    }

    scrollToEnd() {
        if (this.hasListTarget) {
            this.listTarget.scrollTop = this.listTarget.scrollHeight;
        }
    }
}
