<?php
namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Annotation\Route;

class SitemapController extends AbstractController
{
    #[Route('/sitemap.xml', name: 'sitemap', defaults: ['_format' => 'xml'])]
    public function index(RouterInterface $router): Response
    {
        $urls = [];
        $routeCollection = $router->getRouteCollection();

        // Parcourir toutes les routes définies
        foreach ($routeCollection as $routeName => $route) {
            // Vérifier si la route est accessible publiquement (GET)
            if (in_array('GET', $route->getMethods()) || empty($route->getMethods())) {
                // Exclure certaines routes spécifiques si nécessaire
                if (strpos($routeName, 'app_') === 0) { // Inclure uniquement les routes qui commencent par 'app_'
                    // Vérifier si la route a des paramètres
                    $path = $route->getPath();
                    if (preg_match_all('/\{(\w+)\}/', $path, $matches)) {
                        // Gestion des routes dynamiques avec des slugs (exemple)
                        if (in_array('slug', $matches[1])) {
                            // Remplace par une requête BDD pour récupérer les slugs réels
                            $products = ['example-product-1', 'example-product-2'];
                            foreach ($products as $slug) {
                                $urls[] = "<url><loc>" . $this->generateUrl($routeName, ['slug' => $slug], UrlGeneratorInterface::ABSOLUTE_URL) . "</loc><changefreq>weekly</changefreq><priority>0.6</priority></url>";
                            }
                        }
                    } else {
                        // Routes statiques
                        $urls[] = "<url><loc>" . $this->generateUrl($routeName, [], UrlGeneratorInterface::ABSOLUTE_URL) . "</loc><changefreq>daily</changefreq><priority>0.8</priority></url>";
                    }
                }
            }
        }

        // Génération du XML
        $xml = "<?xml version='1.0' encoding='UTF-8'?>";
        $xml .= "<urlset xmlns='http://www.sitemaps.org/schemas/sitemap/0.9'>";
        $xml .= implode('', $urls);
        $xml .= "</urlset>";
        
        $response = new Response($xml);
        $response->headers->set('Content-Type', 'application/xml');
        return $response;
    }
}