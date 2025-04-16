import './bootstrap.js';
import './styles/app.css';
import { setProgressBarDelay } from '@hotwired/turbo';

console.log('This log comes from assets/app.js - welcome to AssetMapper! 🎉');

// Liste des scripts à conserver (ceux gérés par Webpack/AssetMapper)
const PRESERVED_SCRIPTS = [
    'runtime.js',
    'vendors-node_modules_',
    'app.js',
    'controllers/'
];

// Configuration globale de Turbo
function configureTurbo() {
    setProgressBarDelay(999999);
    
    document.addEventListener('turbo:load', () => {
        const turboProgress = document.querySelector('.turbo-progress');
        if (turboProgress) turboProgress.style.display = 'none';
    });
}

// Nettoie les scripts avant chaque navigation
function cleanupScripts() {
    document.querySelectorAll('script[src]').forEach(script => {
        const shouldRemove = !PRESERVED_SCRIPTS.some(term => script.src.includes(term));
        if (shouldRemove) {
            script.remove();
        }
    });
}

// Gestion du spinner personnalisé
function setupCustomSpinner() {
    let isFirstVisit = true;

    document.addEventListener('turbo:before-visit', () => {
        cleanupScripts(); // Nettoyage avant navigation
    });

    document.addEventListener('turbo:visit', () => {
        if (isFirstVisit) {
            isFirstVisit = false;
            return;
        }
        
        const spinner = document.getElementById('custom-spinner');
        if (spinner) {
            spinner.classList.remove('hidden');
            spinner.timeout = setTimeout(() => {
                spinner.classList.add('hidden');
            }, 10000);
        }
    });

    document.addEventListener('turbo:load', () => {
        const spinner = document.getElementById('custom-spinner');
        if (spinner) {
            clearTimeout(spinner.timeout);
            spinner.classList.add('hidden');
        }
    });

    document.addEventListener('turbo:before-fetch-error', () => {
        const spinner = document.getElementById('custom-spinner');
        if (spinner) {
            clearTimeout(spinner.timeout);
            spinner.classList.add('hidden');
        }
    });
}

// Réinitialise les composants JS nécessaires
function reinitializeComponents() {
    // Réinitialiser les composants spécifiques ici
    if (typeof PerfectScrollbar !== 'undefined') {
        document.querySelectorAll('[data-perfect-scrollbar]').forEach(el => {
            new PerfectScrollbar(el);
        });
    }
    
    // Réinitialiser d'autres librairies si nécessaire
}

// Initialisation
document.addEventListener('turbo:load', () => {
    configureTurbo();
    setupCustomSpinner();
    reinitializeComponents();
    
    if (!window.turboLoaded) {
        window.turboLoaded = true;
        document.dispatchEvent(new Event('turbo:load'));
    }
});

// Pour les rechargements manuels
window.reloadWithSpinner = () => {
    cleanupScripts();
    const spinner = document.getElementById('custom-spinner');
    if (spinner) spinner.classList.remove('hidden');
    window.location.reload();
};