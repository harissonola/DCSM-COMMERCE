<?php

namespace App\Command;

use App\Entity\User;
use App\Entity\Product;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CalculateDailyRewards extends Command
{
    protected static $defaultName = 'app:calculate-daily-rewards';
    protected static $defaultDescription = 'Calcule les récompenses quotidiennes des utilisateurs';

    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $userRepository = $this->entityManager->getRepository(User::class);
        $users = $userRepository->findAll();

        foreach ($users as $user) {
            $totalReward = 0;

            // Calcul des intérêts quotidiens basés sur les produits achetés
            foreach ($user->getProduct() as $product) {
                $totalReward += $product->getPrice() * ($user->getReferralRewardRate() / 100);
            }

            // Ajout des récompenses au solde
            $user->setBalance($user->getBalance() + $totalReward);

            // Bonus de 10$ pour 40 parrainages
            if ($user->getReward() > 0) {
                $user->setBalance($user->getBalance() + $user->getReward());
                $user->setReward(0); // Réinitialiser le bonus après versement
            }

            $this->entityManager->persist($user);
        }

        $this->entityManager->flush();
        $output->writeln('Récompenses quotidiennes calculées avec succès.');
        return Command::SUCCESS;
    }
}
