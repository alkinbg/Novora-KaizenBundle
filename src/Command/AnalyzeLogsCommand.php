<?php

declare(strict_types=1);

namespace Novora\KaizenBundle\Command;

use Novora\KaizenBundle\Analysis\LogRedactor;
use Novora\KaizenBundle\Analysis\ParetoAnalyzer;
use Novora\KaizenBundle\Source\MonologFileReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'kaizen:analyze', description: 'Identify recurring issues in a Symfony Monolog log file.')]
final class AnalyzeLogsCommand extends Command
{
    public function __construct(
        private readonly MonologFileReader $reader = new MonologFileReader(),
        private readonly ParetoAnalyzer $analyzer = new ParetoAnalyzer(),
        private readonly LogRedactor $redactor = new LogRedactor(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'Path to a trusted Monolog log file');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum issue groups shown', '20');
        $this->addOption('max-bytes', null, InputOption::VALUE_REQUIRED, 'Maximum bytes read from the file tail', '2097152');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = $input->getArgument('file');
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        $maxBytes = filter_var($input->getOption('max-bytes'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 16_777_216]]);

        if (!is_string($path) || !is_file($path) || !is_readable($path) || $limit === false || $maxBytes === false) {
            $io->error('Provide a readable log file and valid limit/max-bytes options.');

            return Command::INVALID;
        }

        $issues = $this->analyzer->analyze($this->reader->read($path, $maxBytes));
        $rows = [];
        $total = array_sum(array_map(static fn ($issue): int => $issue->count, $issues));
        foreach (array_slice($issues, 0, $limit) as $issue) {
            $rows[] = [
                $issue->count,
                $total > 0 ? number_format(100 * $issue->count / $total, 1).'%' : '0%',
                $issue->level,
                $issue->channel,
                $this->redactor->redact($issue->example, 120),
                $issue->lastSeen->format('Y-m-d H:i:s'),
            ];
        }

        $io->title('Kaizen · recurring log issues');
        $io->table(['Count', 'Share', 'Level', 'Channel', 'Example', 'Last seen'], $rows);
        $io->note('This is frequency-based prioritization, not impact scoring. Only structured Monolog lines are counted.');

        return Command::SUCCESS;
    }
}
