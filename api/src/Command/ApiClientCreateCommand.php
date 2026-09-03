<?php

namespace App\Command;

use App\Entity\ApiClient;
use App\Repository\ApiClientRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Creates an API client and prints its API key exactly once.
 * Only the SHA-256 hash of the key is stored in the database.
 */
#[AsCommand(
    name: 'gardenhub:api-client:create',
    description: 'Create an API client and print its API key once',
)]
class ApiClientCreateCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ApiClientRepository $apiClientRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Client name, e.g. "grafana" or "react-frontend"')
            ->addOption('readonly', null, InputOption::VALUE_NONE, 'Grant read-only access (no write rights)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = (string) $input->getArgument('name');

        if (null !== $this->apiClientRepository->findOneBy(['name' => $name])) {
            $io->error(sprintf('An API client named "%s" already exists.', $name));
            return Command::INVALID;
        }

        $key = 'gh_'.bin2hex(random_bytes(24));

        $roles = [ApiClient::ROLE_READ];
        if (!$input->getOption('readonly')) {
            $roles[] = ApiClient::ROLE_WRITE;
        }

        $client = (new ApiClient())
            ->setName($name)
            ->setKeyHash(hash('sha256', $key))
            ->setRoles($roles);

        $this->entityManager->persist($client);
        $this->entityManager->flush();

        $io->success(sprintf('API client "%s" created with roles: %s', $name, implode(', ', $roles)));
        $io->warning('Store this key now — it will never be shown again:');
        $io->writeln($key);

        return Command::SUCCESS;
    }
}
