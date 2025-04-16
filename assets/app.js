import './bootstrap.js';
import './styles/app.css';
import { setProgressBarDelay } from '@hotwired/turbo';

console.log('This log comes from assets/app.js - welcome to AssetMapper! 🎉');

// Configuration globale de Turbo
function configureTurbo() {
    // Désactive complètement la barre de progression native
    setProgressBarDelay(999999);
    
    // Cache la barre de progression au cas où
    document.addEventListener('turbo:load', () => {
        const turboProgress = document.querySelector('.turbo-progress');
        if (turboProgress) turboProgress.style.display = 'none';
    });
}

// Gestion du spinner personnalisé
function setupCustomSpinner() {
    let isFirstVisit = true;

    // Affiche le spinner au début de la navigation
    document.addEventListener('turbo:visit', () => {
        if (isFirstVisit) {
            isFirstVisit = false;
            return;
        }
        
        const spinner = document.getElementById('custom-spinner');
        if (spinner) {
            spinner.classList.remove('hidden');
            // Ajoute un timeout de sécurité au cas où la navigation échouerait
            spinner.timeout = setTimeout(() => {
                spinner.classList.add('hidden');
            }, 10000); // 10s timeout max
        }
    });

    // Cache le spinner quand la page est chargée
    document.addEventListener('turbo:load', () => {
        const spinner = document.getElementById('custom-spinner');
        if (spinner) {
            clearTimeout(spinner.timeout);
            spinner.classList.add('hidden');
        }
    });

    // Cache le spinner si la navigation échoue
    document.addEventListener('turbo:before-fetch-error', () => {
        const spinner = document.getElementById('custom-spinner');
        if (spinner) {
            clearTimeout(spinner.timeout);
            spinner.classList.add('hidden');
        }
    });
}

// Initialisation
document.addEventListener('turbo:load', () => {
    configureTurbo();
    setupCustomSpinner();
    
    // Polyfill pour les navigateurs sans support modules
    if (!window.turboLoaded) {
        window.turboLoaded = true;
        const event = new Event('turbo:load');
        document.dispatchEvent(event);
    }
});

// Gestion du rechargement manuel (si nécessaire)
window.reloadWithSpinner = () => {
    const spinner = document.getElementById('custom-spinner');
    if (spinner) spinner.classList.remove('hidden');
    window.location.reload();
};