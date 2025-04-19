import './bootstrap.js';
import './styles/app.css';

// Configuration Turbo pour des performances maximales
Turbo.setProgressBarDelay(0);
Turbo.session.drive = true; // Activation du cache

console.log('Application Turbo optimisée chargée !');

// Cache le spinner initialement
const spinner = document.getElementById('custom-spinner')?.classList.add('hidden');

// Stratégie optimisée de gestion des scripts
const PERSISTENT_SCRIPTS = [
    'bootstrap.js',
    'styles/app.css',
    'turbo.js'
];

// Événements Turbo optimisés
document.addEventListener('turbo:before-visit', () => {
    spinner?.classList.remove('hidden');
    cleanTransientAssets(); // Nettoyage anticipé
});

document.addEventListener('turbo:before-render', () => {
    spinner?.classList.add('hidden');
    prepareDOMForRender(); // Préparation optimisée
});

document.addEventListener('turbo:render', initPageFeatures);
document.addEventListener('turbo:load', logPageLoad);

// Fonctions optimisées
function cleanTransientAssets() {
    // Nettoyage ciblé plus performant
    document.querySelectorAll('script[src]:not([data-turbo-permanent])').forEach(script => {
        if (!PERSISTENT_SCRIPTS.some(p => script.src.includes(p))) {
            script.remove();
        }
    });
    
    // Suppression des styles temporaires
    document.querySelectorAll('link[rel=stylesheet][data-turbo-temporary]')?.forEach(link => link.remove());
}

function prepareDOMForRender() {
    // Pré-optimisation du DOM
    document.querySelectorAll('[data-turbo-cache=false]')?.forEach(el => el.remove());
}

function initPageFeatures() {
    // Initialisation différée des composants
    requestIdleCallback(() => {
        if (window.AOS) AOS.init();
        if (window.initComponents) initComponents();
    });
}

function logPageLoad() {
    console.debug(`Page chargée en ${performance.now().toFixed(1)}ms`);
}

// Debug amélioré
window.analyzePerformance = () => {
    console.table(
        Array.from(document.querySelectorAll('script[src]'))
            .map(s => ({
                src: s.src.split('/').pop(),
                size: `${(s.text.length/1024).toFixed(2)}kB`,
                permanent: s.hasAttribute('data-turbo-permanent')
            }))
    );
};