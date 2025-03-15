<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class MainController extends AbstractController
{
    #[Route('/', name: 'app_main')]
    public function index(): Response
    {
        $user= $this->getUser();
        if ($user) {
            if ($user->getRoles()[0] == "ROLE_ADMIN") {
                return $this->redirectToRoute("app_dashboard"); //je dois changer ceci en la route admin
            }else {
                return $this->redirectToRoute("app_dashboard");
            }
        }

        return $this->render('main/index.html.twig', [
            'controller_name' => 'MainController',
        ]);
    }
}
