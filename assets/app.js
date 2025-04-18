import './bootstrap.js';
import './styles/app.css';

// Configuration globale de Turbo
Turbo.setProgressBarDelay(0); // Désactive complètement la barre de progression native

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

document.addEventListener('turbo:before-render', (event) => {
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
        
        // Liste des motifs à conserver (ajustez selon vos besoins)
        const keepPatterns = [
            /tidio/,
            /sweetalert2/,
            /jquery/,
            /datatables/,
            /aos/,
            /bootstrap/,
            /apexcharts/,
            /perfect-scrollbar/,
            /sneat/
        ];
        
        const shouldKeep = keepPatterns.some(pattern => pattern.test(src));
        
        if (!shouldKeep || loadedScripts.has(src)) {
            script.remove();
        } else {
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