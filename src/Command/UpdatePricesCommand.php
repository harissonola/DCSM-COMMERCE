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
    
    private MessageBusInterface $messageBus;

    public function __construct(MessageBusInterface $messageBus)
    {
        parent::__construct();
        $this->messageBus = $messageBus;
    }

    protected function configure()
    {
        $this->setDescription('Met à jour les prix des produits toutes les 3 minutes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Envoi de la commande de mise à jour des prix...');
        $this->messageBus->dispatch(new UpdatePricesMessage());
        return Command::SUCCESS;
    }
}