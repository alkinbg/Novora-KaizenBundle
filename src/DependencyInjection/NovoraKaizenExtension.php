<?php
declare(strict_types=1);

namespace Novora\KaizenBundle\DependencyInjection;

use Novora\KaizenBundle\Analysis\Fingerprinter;
use Novora\KaizenBundle\Analysis\LogRedactor;
use Novora\KaizenBundle\Analysis\ErrorFamilyClassifier;
use Novora\KaizenBundle\Analysis\ExecutionAnalyzer;
use Novora\KaizenBundle\Analysis\ParetoAnalyzer;
use Novora\KaizenBundle\Analysis\ParetoChartBuilder;
use Novora\KaizenBundle\Analysis\TrendAnalyzer;
use Novora\KaizenBundle\Analysis\WasteDetector;
use Novora\KaizenBundle\Command\AnalyzeLogsCommand;
use Novora\KaizenBundle\Controller\KaizenController;
use Novora\KaizenBundle\Correlation\ExecutionContext;
use Novora\KaizenBundle\Correlation\ExecutionIdProcessor;
use Novora\KaizenBundle\Correlation\MessengerExecutionSubscriber;
use Novora\KaizenBundle\Improvement\ChangeVerificationAnalyzer;
use Novora\KaizenBundle\Improvement\InvestigationStore;
use Novora\KaizenBundle\Source\MonologFileReader;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

final class NovoraKaizenExtension extends Extension implements PrependExtensionInterface
{
    public function prepend(ContainerBuilder $container): void
    {
        $container->prependExtensionConfig('twig', [
            'paths' => [dirname(__DIR__, 2).'/templates' => 'NovoraKaizen'],
        ]);
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), $configs);
        $container->register(Fingerprinter::class, Fingerprinter::class);
        $container->register(ParetoAnalyzer::class, ParetoAnalyzer::class)->setAutowired(true);
        $container->register(ExecutionAnalyzer::class, ExecutionAnalyzer::class);
        $container->register(ErrorFamilyClassifier::class, ErrorFamilyClassifier::class);
        $container->register(ParetoChartBuilder::class, ParetoChartBuilder::class);
        $container->register(TrendAnalyzer::class, TrendAnalyzer::class)->setAutowired(true);
        $container->register(WasteDetector::class, WasteDetector::class);
        $container->register(LogRedactor::class, LogRedactor::class);
        $container->register(MonologFileReader::class, MonologFileReader::class);
        if ($config['correlation_enabled']) {
            if (!class_exists(\Monolog\LogRecord::class)) {
                throw new \LogicException('Enable correlation only when Monolog 3 is installed.');
            }

            $container->register(ExecutionContext::class, ExecutionContext::class);
            $container->register(ExecutionIdProcessor::class, ExecutionIdProcessor::class)
                ->setAutowired(true)
                ->addTag('monolog.processor');

            if (class_exists(WorkerMessageReceivedEvent::class)) {
                $container->register(MessengerExecutionSubscriber::class, MessengerExecutionSubscriber::class)
                    ->setAutowired(true)
                    ->addTag('kernel.event_subscriber');
            }
        }

        $container->register(ChangeVerificationAnalyzer::class, ChangeVerificationAnalyzer::class)->setAutowired(true);
        $container->register(InvestigationStore::class, InvestigationStore::class)
            ->setArgument('$directory', $config['storage_dir']);
        $container->register(AnalyzeLogsCommand::class, AnalyzeLogsCommand::class)
            ->setAutowired(true)
            ->addTag('console.command');
        $container->register(KaizenController::class, KaizenController::class)
            ->setAutowired(true)
            ->setArgument('$logPath', $config['log_path'])
            ->setArgument('$correlationEnabled', $config['correlation_enabled'])
            ->setArgument('$maxBytes', $config['max_bytes'])
            ->addTag('controller.service_arguments');
    }
}
