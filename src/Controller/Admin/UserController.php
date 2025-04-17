<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Form\UserType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

#[Route('/admin/users')]
class UserController extends AbstractController
{
    private Filesystem $filesystem;

    public function __construct()
    {
        $this->filesystem = new Filesystem();
    }

    /**
     * Affiche la liste des utilisateurs et applique les filtres.
     */
    #[Route('/', name: 'admin_user_index', methods: ['GET'])]
    public function index(Request $request, UserRepository $userRepository): Response
    {
        $role       = $request->query->get('role');            // 'Administrateur' ou 'Utilisateur'
        $minBalance = $request->query->get('minBalance');      // string ou null

        $users = $userRepository->findWithFilters(
            $role,
            $minBalance !== null && $minBalance !== '' ? (float) $minBalance : null
        );

        return $this->render('admin/user/index.html.twig', [
            'users' => $users,
        ]);
    }

    #[Route('/new', name: 'admin_user_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        UrlGeneratorInterface $urlGenerator,
        MailerInterface $mailer
    ): Response {
        $user = new User();
        $form = $this->createForm(UserType::class, $user, ['is_new' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                // Validation du mot de passe
                $plainPassword = $form->get('plainPassword')->getData();
                if (empty($plainPassword)) {
                    $form->get('plainPassword')->addError(new FormError('Le mot de passe est requis'));
                    throw new \Exception('Le mot de passe est requis');
                }

                // Configuration de l'utilisateur
                $user
                    ->setPassword($passwordHasher->hashPassword($user, $plainPassword))
                    ->setCreatedAt(new \DateTimeImmutable())
                    ->setMiningBotActive(false)
                    ->setBalance(0)
                    ->setReferralCode(uniqid('ref_', false));

                // Upload de la photo
                $this->handleProfileImageUpload($user, $form);

                // Persist + flush pour obtenir un ID
                $entityManager->persist($user);
                $entityManager->flush();

                // Génération et sauvegarde du QR Code
                $this->generateAndSaveQrCode($user, $urlGenerator, $entityManager);

                $this->addFlash('success', 'Utilisateur créé avec succès');
                return $this->redirectToRoute('admin_user_index', [], Response::HTTP_SEE_OTHER);
            } catch (\Exception $e) {
                $this->addFlash('error', 'Erreur lors de la création : ' . $e->getMessage());
                $entityManager->clear();
            }
        }

        return $this->render('admin/user/new.html.twig', [
            'user' => $user,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}', name: 'admin_user_show', methods: ['GET'])]
    public function show(User $user): Response
    {
        return $this->render('admin/user/show.html.twig', [
            'user' => $user,
        ]);
    }

    #[Route('/{id}/edit', name: 'admin_user_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, User $user, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(UserType::class, $user, ['is_new' => false]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->handleProfileImageUpload($user, $form);
                $entityManager->flush();
                $this->addFlash('success', 'Utilisateur mis à jour avec succès');
                return $this->redirectToRoute('admin_user_index', [], Response::HTTP_SEE_OTHER);
            } catch (\Exception $e) {
                $this->addFlash('error', 'Erreur lors de la mise à jour : ' . $e->getMessage());
            }
        }

        return $this->render('admin/user/edit.html.twig', [
            'user' => $user,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/update-balance', name: 'admin_user_update_balance', methods: ['POST'])]
    public function updateBalance(Request $request, User $user, EntityManagerInterface $entityManager): Response
    {
        $amount = (float) $request->request->get('amount');
        $action = $request->request->get('action');

        if (!in_array($action, ['add', 'subtract', 'set'], true)) {
            $this->addFlash('error', 'Action invalide');
            return $this->redirectToRoute('admin_user_show', ['id' => $user->getId()]);
        }

        try {
            switch ($action) {
                case 'add':
                    $user->setBalance($user->getBalance() + $amount);
                    $message = sprintf('%.2f $ ajoutés au solde', $amount);
                    break;
                case 'subtract':
                    $user->setBalance($user->getBalance() - $amount);
                    $message = sprintf('%.2f $ retirés du solde', $amount);
                    break;
                default:
                    $user->setBalance($amount);
                    $message = 'Solde défini à ' . sprintf('%.2f $', $amount);
            }

            $entityManager->flush();
            $this->addFlash('success', $message);
        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur lors de la mise à jour du solde : ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_user_show', ['id' => $user->getId()]);
    }

    #[Route('/{id}', name: 'admin_user_delete', methods: ['POST'])]
    public function delete(Request $request, User $user, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$user->getId(), $request->request->get('_token'))) {
            try {
                $entityManager->remove($user);
                $entityManager->flush();
                $this->addFlash('success', 'Utilisateur supprimé avec succès');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Erreur lors de la suppression : '.$e->getMessage());
            }
        }

        return $this->redirectToRoute('admin_user_index', [], Response::HTTP_SEE_OTHER);
    }

    private function handleProfileImageUpload(User $user, $form): void
    {
        try {
            /** @var UploadedFile $image */
            $image = $form->get('photo')->getData();
            if ($image instanceof UploadedFile) {
                $safeFilename = transliterator_transliterate(
                    'Any-Latin; Latin-ASCII; [^A-Za-z0-9_] remove; Lower()',
                    pathinfo($image->getClientOriginalName(), PATHINFO_FILENAME)
                );
                $newFilename = $safeFilename.'-'.uniqid().'.'.$image->guessExtension();

                $uploadDir = $this->getParameter('kernel.project_dir').'/public/uploads/profile_images';
                if (!$this->filesystem->exists($uploadDir)) {
                    $this->filesystem->mkdir($uploadDir, 0755);
                }

                $image->move($uploadDir, $newFilename);
                $user->setPhoto('/uploads/profile_images/'.$newFilename);
            }
        } catch (\Exception $e) {
            $this->addFlash('warning', "Erreur upload image : ".$e->getMessage());
        }
    }

    private function generateAndSaveQrCode(
        User $user,
        UrlGeneratorInterface $urlGenerator,
        EntityManagerInterface $entityManager
    ): void {
        try {
            $referralLink = $urlGenerator->generate(
                'app_register',
                ['ref' => $user->getReferralCode()],
                UrlGeneratorInterface::ABSOLUTE_URL
            );

            $qrCode = new QrCode($referralLink);
            $writer = new PngWriter();
            $result = $writer->write($qrCode);

            $uploadDir = $this->getParameter('kernel.project_dir').'/public/uploads/qrcodes';
            if (!$this->filesystem->exists($uploadDir)) {
                $this->filesystem->mkdir($uploadDir, 0755);
            }

            $filename = 'qr-'.$user->getId().'.png';
            $result->saveToFile($uploadDir.'/'.$filename);
            $user->setQrCodePath('/uploads/qrcodes/'.$filename);
            $entityManager->flush();
        } catch (\Exception $e) {
            $this->addFlash('warning', "Erreur QR code : ".$e->getMessage());
        }
    }
}