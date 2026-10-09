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
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class CauseAssessmentValidationTest extends TestCase
{
    private string $directory;
    private InvestigationStore $store;
    private string $id;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/kaizen_assessment_'.bin2hex(random_bytes(6));
        $this->store = new InvestigationStore($this->directory);
        $case = $this->store->create('Session permissions', 'Sessions could not be cleaned');
        $this->id = $case['id'];
        $this->store->addCause($this->id, 'infrastructure', 'Permissions are incorrect', '');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testConfirmationWithoutEvidenceShowsInlineValidationWithoutModifyingCause(): void
    {
        $response = $this->controller()->update($this->id, 'assessment', $this->request('confirmed', ''));

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $body = (string) $response->getContent();
        self::assertStringContainsString('Add supporting evidence before confirming this cause.', $body);
        self::assertStringContainsString('aria-invalid="true"', $body);
        self::assertStringContainsString('role="alert"', $body);
        self::assertMatchesRegularExpression('/<option value="confirmed" selected>Confirmed<\/option>/', $body);
        self::assertStringContainsString('Permissions are incorrect', $body);

        $stored = $this->store->find($this->id);
        self::assertSame('unverified', $stored['causes'][0]['assessment']);
        self::assertSame('', $stored['causes'][0]['evidence']);
    }

    public function testConfirmationWithEvidencePersistsAsBefore(): void
    {
        $result = $this->controller()->update(
            $this->id,
            'assessment',
            $this->request('confirmed', 'Observed owner mismatch and failing access check'),
        );

        self::assertSame(Response::HTTP_SEE_OTHER, $result->getStatusCode());
        $stored = $this->store->find($this->id);
        self::assertSame('confirmed', $stored['causes'][0]['assessment']);
        self::assertSame('Observed owner mismatch and failing access check', $stored['causes'][0]['evidence']);
    }

    public function testUnverifiedCauseStillPermitsEmptyEvidence(): void
    {
        $result = $this->controller()->update($this->id, 'assessment', $this->request('unverified', ''));
        self::assertSame(Response::HTTP_SEE_OTHER, $result->getStatusCode());
    }

    private function request(string $assessment, string $evidence): Request
    {
        return Request::create('/_kaizen/investigations/'.$this->id.'/assessment', 'POST', [
            '_token' => 'accepted-in-test',
            'index' => '0',
            'assessment' => $assessment,
            'evidence' => $evidence,
        ]);
    }

    private function controller(): KaizenController
    {
        $loader = new FilesystemLoader();
        $loader->addPath(dirname(__DIR__, 2).'/templates', 'NovoraKaizen');
        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addFunction(new TwigFunction('path', static fn (string $name, array $params = []): string => '/'.$name.'?'.http_build_query($params)));
        $twig->addGlobal('app', (object) ['request' => Request::create('/_kaizen')]);

        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(true);
        $csrf->method('getToken')->willReturn(new \Symfony\Component\Security\Csrf\CsrfToken('test', 'accepted-in-test'));

        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/_kaizen/investigations/'.$this->id);

        return new KaizenController(
            $twig,
            $urls,
            $csrf,
            new MonologFileReader(),
            new ParetoAnalyzer(),
            new ErrorFamilyClassifier(),
            new ExecutionAnalyzer(),
            new ParetoChartBuilder(),
            new TrendAnalyzer(),
            new WasteDetector(),
            new LogRedactor(),
            $this->store,
            new ChangeVerificationAnalyzer(),
            '/nonexistent/kaizen.log',
            false,
            4_194_304,
        );
    }
}
