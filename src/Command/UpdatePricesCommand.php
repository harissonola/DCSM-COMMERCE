<?php

namespace App\Command;

use App\Message\UpdatePricesMessage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class UpdatePricesCommand extends Command
{
    protected static $defaultName = 'app:update-prices';
    
    public function __construct(
        private MessageBusInterface $messageBus
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Met à jour les prix des produits toutes les 3 minutes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Début de la mise à jour des prix...');
        $this->messageBus->dispatch(new UpdatePricesMessage());
        $output->writeln('Commande envoyée avec succès');
        
        return Command::SUCCESS;
    }
}