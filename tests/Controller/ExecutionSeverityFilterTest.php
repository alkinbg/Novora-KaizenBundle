<?php

declare(strict_types=1);

namespace Novora\KaizenBundle\Tests\Controller;

use Novora\KaizenBundle\Analysis\ErrorFamilyClassifier;
use Novora\KaizenBundle\Analysis\ExecutionAnalyzer;
use Novora\KaizenBundle\Analysis\LogRedactor;
use Novora\KaizenBundle\Analysis\ParetoAnalyzer;
use Novora\KaizenBundle\Analysis\ParetoChartBuilder;
use Novora\KaizenBundle\Analysis\TrendAnalyzer;
use Novora\KaizenBundle\Analysis\WasteDetector;
use Novora\KaizenBundle\Controller\KaizenController;
use Novora\KaizenBundle\Improvement\ChangeVerificationAnalyzer;
use Novora\KaizenBundle\Improvement\InvestigationStore;
use Novora\KaizenBundle\Source\MonologFileReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class ExecutionSeverityFilterTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'kaizen_levels_');
        self::assertNotFalse($file);
        $this->file = $file;

        $records = [];
        for ($i = 0; $i < 170; ++$i) {
            $records[] = $this->line('DEBUG', 'Routine debug event '.(string) $i);
        }
        $records[] = $this->line('INFO', 'Normal processing completed');
        $records[] = $this->line('ERROR', 'Important database failure');
        $records[] = $this->line('CRITICAL', 'Critical HTTP failure');

        file_put_contents($this->file, implode("\n", $records)."\n");
    }

    protected function tearDown(): void
    {
        if (isset($this->file) && is_file($this->file)) {
            unlink($this->file);
        }
    }

    public function testDefaultShowsProblemsBeforeDebugNoise(): void
    {
        $html = $this->controller()->execution(str_repeat('a', 32), Request::create('/_kaizen/executions/'.str_repeat('a', 32)))->getContent();

        self::assertIsString($html);
        self::assertStringContainsString('Important database failure', $html);
        self::assertStringContainsString('Critical HTTP failure', $html);
        self::assertStringNotContainsString('Routine debug event 0', $html);
        self::assertStringContainsString('Showing 2 of 2 matching entries', $html);
        self::assertStringContainsString('173 total', $html);
    }

    public function testSpecificLevelAndEmptyResults(): void
    {
        $id = str_repeat('a', 32);
        $controller = $this->controller();

        $debug = $controller->execution($id, Request::create('/_kaizen/executions/'.$id, 'GET', ['level' => 'debug']))->getContent();
        self::assertStringContainsString('Showing 150 of 170 matching entries', $debug);
        self::assertStringContainsString('Routine debug event 0', $debug);
        self::assertStringNotContainsString('Important database failure', $debug);

        $critical = $controller->execution($id, Request::create('/_kaizen/executions/'.$id, 'GET', ['level' => 'critical']))->getContent();
        self::assertStringContainsString('Critical HTTP failure', $critical);
        self::assertStringNotContainsString('Important database failure', $critical);

        $warning = $controller->execution($id, Request::create('/_kaizen/executions/'.$id, 'GET', ['level' => 'warning']))->getContent();
        self::assertStringContainsString('No matching entries in this execution', $warning);

        $invalid = $controller->execution($id, Request::create('/_kaizen/executions/'.$id, 'GET', ['level' => ['invalid']]))->getContent();
        self::assertStringContainsString('Showing 2 of 2 matching entries', $invalid);
    }

    public function testDiagnosticDetailIsAvailableBeyondSummaryWithoutRenderingRawSecrets(): void
    {
        $record = $this->line('ERROR', 'Exception '.str_repeat('trace ', 100)
            .'Final SQLSTATE failure {"password":"not-public"}');
        file_put_contents($this->file, $record."\n");

        $html = $this->controller()->execution(
            str_repeat('a', 32),
            Request::create('/_kaizen/executions/'.str_repeat('a', 32)),
        )->getContent();

        self::assertIsString($html);
        self::assertStringContainsString('View diagnostic details', $html);
        self::assertStringContainsString('Final SQLSTATE failure', $html);
        self::assertStringNotContainsString('not-public', $html);
        self::assertStringContainsString('[REDACTED]', $html);
    }

    public function testDashboardDistinguishesMissingLogSourceFromNoErrors(): void
    {
        $missing = sys_get_temp_dir().'/kaizen_missing_'.bin2hex(random_bytes(8)).'.log';
        $html = $this->controller($missing)->dashboard(Request::create('/_kaizen'))->getContent();

        self::assertIsString($html);
        self::assertStringContainsString('No log data available', $html);
        self::assertStringContainsString('zero counts below do not mean', $html);
        self::assertStringContainsString('kaizen_missing_', $html);

        $working = $this->controller()->dashboard(Request::create('/_kaizen'))->getContent();
        self::assertStringNotContainsString('No log data available', $working);
        self::assertStringNotContainsString('No readable Monolog events were found', $working);
    }

    public function testDashboardExplainsEmptyOrUnrecognizedLogRecords(): void
    {
        file_put_contents($this->file, "not a Monolog record\\n");
        $html = $this->controller()->dashboard(Request::create('/_kaizen'))->getContent();

        self::assertStringContainsString('No readable Monolog events were found', (string) $html);
        self::assertStringNotContainsString('No log data available', (string) $html);
    }

    private function line(string $level, string $message): string
    {
        return json_encode([
            'datetime' => '2026-10-09T10:00:00+03:00',
            'level_name' => $level,
            'channel' => 'request',
            'message' => $message,
            'extra' => [
                'kaizen_execution_id' => str_repeat('a', 32),
                'kaizen_origin' => 'http',
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function controller(?string $logPath = null): KaizenController
    {
        $loader = new FilesystemLoader();
        $loader->addPath(dirname(__DIR__, 2).'/templates', 'NovoraKaizen');
        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addFunction(new TwigFunction('path', static fn (string $name, array $params = []): string => '/'.$name.'?'.http_build_query($params)));
        $twig->addGlobal('app', (object) [
            'request' => Request::create('/_kaizen'),
        ]);

        return new KaizenController(
            $twig,
            $this->createStub(UrlGeneratorInterface::class),
            $this->createStub(CsrfTokenManagerInterface::class),
            new MonologFileReader(),
            new ParetoAnalyzer(),
            new ErrorFamilyClassifier(),
            new ExecutionAnalyzer(),
            new ParetoChartBuilder(),
            new TrendAnalyzer(),
            new WasteDetector(),
            new LogRedactor(),
            new InvestigationStore(sys_get_temp_dir().'/unused_kaizen_store'),
            new ChangeVerificationAnalyzer(),
            $logPath ?? $this->file,
            false,
            4_194_304,
        );
    }
}
