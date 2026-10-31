<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\TransferManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:prune', description: 'Supprime les fichiers et métadonnées des transferts expirés.')]
final class PruneCommand extends Command
{
    public function __construct(private TransferManager $transfers)
    {
        parent::__construct();
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln($this->transfers->purgeExpired() . ' transfert(s) expiré(s) supprimé(s).');
        return Command::SUCCESS;
    }
}
