import './bootstrap.js';
import './styles/app.css';

// Configuration globale de Turbo
Turbo.setProgressBarDelay(0); // Désactive complètement la barre de progression native

console.log('Application Turbo chargée avec succès !');

// Gestion du spinner personnalisé
const spinner = document.getElementById('custom-spinner');

// Cache le spinner initialement
if (spinner) {
    spinner.classList.add('hidden');
}

// Gestion des événements Turbo
document.addEventListener('turbo:before-fetch-request', () => {
    if (spinner) spinner.classList.remove('hidden');
});

document.addEventListener('turbo:before-render', () => {
    if (spinner) spinner.classList.add('hidden');
    
    // Nettoie les scripts dupliqués avant le rendu
    cleanDuplicateScripts();
});

document.addEventListener('turbo:load', () => {
    // Initialise AOS après chaque chargement
    if (typeof AOS !== 'undefined') {
        AOS.init();
    }
});

// Fonction pour nettoyer les scripts dupliqués
function cleanDuplicateScripts() {
    const scripts = document.querySelectorAll('script[src]');
    const loadedScripts = new Set();

    scripts.forEach(script => {
        const src = script.src;

        // Si le script a déjà été chargé, on le supprime
        if (loadedScripts.has(src)) {
            script.remove();
        } else {
            // Sinon, on l'ajoute à la liste des scripts chargés
            loadedScripts.add(src);
        }
    });
}

// Version alternative pour debug
window.debugScripts = function() {
    console.group('Scripts chargés');
    document.querySelectorAll('script[src]').forEach(s => console.log(s.src));
    console.groupEnd();
};