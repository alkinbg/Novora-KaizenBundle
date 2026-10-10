<?php
declare(strict_types=1);

namespace Novora\KaizenBundle\Controller;

use Novora\KaizenBundle\Analysis\LogRedactor;
use Novora\KaizenBundle\Analysis\ErrorFamilyClassifier;
use Novora\KaizenBundle\Analysis\ExecutionAnalyzer;
use Novora\KaizenBundle\Analysis\ParetoAnalyzer;
use Novora\KaizenBundle\Analysis\ParetoChartBuilder;
use Novora\KaizenBundle\Analysis\TrendAnalyzer;
use Novora\KaizenBundle\Analysis\WasteDetector;
use Novora\KaizenBundle\Model\Issue;
use Novora\KaizenBundle\Improvement\ChangeVerificationAnalyzer;
use Novora\KaizenBundle\Improvement\InvestigationStore;
use Novora\KaizenBundle\Source\MonologFileReader;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;

#[Route('/_kaizen', name: 'novora_kaizen_')]
#[IsGranted('ROLE_ADMIN')]
final readonly class KaizenController
{
    public function __construct(
        private Environment $twig,
        private UrlGeneratorInterface $urlGenerator,
        private CsrfTokenManagerInterface $csrf,
        private MonologFileReader $reader,
        private ParetoAnalyzer $pareto,
        private ErrorFamilyClassifier $families,
        private ExecutionAnalyzer $executions,
        private ParetoChartBuilder $paretoChart,
        private TrendAnalyzer $trends,
        private WasteDetector $waste,
        private LogRedactor $redactor,
        private InvestigationStore $store,
        private ChangeVerificationAnalyzer $verificationAnalyzer,
        private string $logPath,
        private bool $correlationEnabled,
        private int $maxBytes,
    ) {
    }

    #[Route('', name: 'dashboard', methods: ['GET'])]
    public function dashboard(Request $request): Response
    {
        $logSourceAvailable = is_file($this->logPath) && is_readable($this->logPath);
        $events = $logSourceAvailable ? $this->reader->read($this->logPath, $this->maxBytes) : [];
        $issues = $this->pareto->analyze($events);
        $correlation = $this->executions->summarize($events);
        $total = count($events);
        $problemCount = count(array_filter($events, static fn ($e): bool => in_array($e->level, ['error', 'critical', 'alert', 'emergency'], true)));
        $trend = $this->trends->compare($events, new \DateTimeImmutable(), 24);

        $scope = $request->query->get('scope', 'actionable');
        if (!is_string($scope) || !in_array($scope, ['actionable', 'errors', 'compatibility'], true)) {
            $scope = 'actionable';
        }

        $relevantIssues = array_values(array_filter(
            $issues,
            fn ($issue): bool => !$this->waste->isRoutineEvent($issue),
        ));
        $errorIssues = array_values(array_filter(
            $relevantIssues,
            static fn ($issue): bool => in_array($issue->level, ['error', 'critical', 'alert', 'emergency'], true),
        ));
        $familySummary = $this->families->summarize($errorIssues);

        $family = $request->query->get('family');
        if ($scope !== 'errors' || !is_string($family) || !array_key_exists($family, $this->families->labels())) {
            $family = null;
        }

        $familyOverview = $scope === 'errors' && $family === null;
        $chartIssues = match ($scope) {
            'errors' => $family === null
                ? $errorIssues
                : array_values(array_filter(
                    $errorIssues,
                    fn ($issue): bool => $this->families->classify($issue) === $family,
                )),
            'compatibility' => array_values(array_filter(
                $relevantIssues,
                fn ($issue): bool => $this->waste->isCompatibilityIssue($issue),
            )),
            default => $relevantIssues,
        };
        if ($familyOverview) {
            // Presentation-only aggregates: these are symptom families, NOT individual
            // error fingerprints. Never pass their synthetic keys to an investigation.
            $observedAt = new \DateTimeImmutable();
            $chartIssues = array_map(
                static fn (array $group): Issue => new Issue(
                    $group['key'],
                    'error',
                    'family',
                    $group['label'],
                    $group['count'],
                    $observedAt,
                    $observedAt,
                ),
                $familySummary,
            );
        }

        $chart = $this->paretoChart->build(
            $chartIssues,
            $familyOverview && $chartIssues !== [] ? count($chartIssues) : null,
        );
        $chart['buckets'] = array_map(
            fn (array $bucket): array => [
                ...$bucket,
                'example' => $this->redactor->redact($bucket['example']),
                'label' => $familyOverview ? $bucket['example'] : $bucket['label'],
            ],
            $chart['buckets'],
        );
        $hiddenRoutinePatterns = count($issues) - count($relevantIssues);

        $opportunities = array_map(fn (array $candidate): array => [
            ...$candidate, 'example' => $this->redactor->redact($candidate['example']),
        ], $this->waste->detect($issues));

        return $this->view('dashboard.html.twig', [
            'chart' => $chart, 'scope' => $scope, 'family' => $family, 'families' => $familySummary, 'familyOverview' => $familyOverview, 'selectedFamily' => $family === null ? null : $this->families->labels()[$family], 'total' => $total, 'errors' => $problemCount,
            'groups' => count($issues), 'trend' => $trend, 'correlation' => $correlation, 'correlationEnabled' => $this->correlationEnabled,
            'hiddenRoutinePatterns' => $hiddenRoutinePatterns,
            'logPath' => basename($this->logPath), 'logSourceAvailable' => $logSourceAvailable, 'maxBytes' => $this->maxBytes,
            'investigations' => $this->store->all(), 'storageWritable' => $this->store->isStorageWritable(), 'createToken' => $this->token('create'), 'opportunities' => $opportunities,
        ]);
    }

    #[Route('/executions/{id}', name: 'execution', requirements: ['id' => '[a-f0-9]{32}'], methods: ['GET'])]
    public function execution(string $id, Request $request): Response
    {
        $events = array_values(array_filter(
            $this->reader->read($this->logPath, $this->maxBytes),
            static fn ($event): bool => $event->executionId === $id,
        ));

        if ($events === []) {
            throw new NotFoundHttpException('Execution not found in the configured log sample.');
        }

        usort($events, static fn ($a, $b): int => $a->occurredAt <=> $b->occurredAt);

        $filters = [
            'problems' => ['error', 'critical', 'alert', 'emergency'],
            'critical' => ['critical', 'alert', 'emergency'],
            'error' => ['error'],
            'warning' => ['warning'],
            'info' => ['info', 'notice'],
            'debug' => ['debug'],
            'all' => [],
        ];

        $level = $request->query->all()['level'] ?? 'problems';
        if (!is_string($level) || !array_key_exists($level, $filters)) {
            $level = 'problems';
        }

        $counts = array_fill_keys(array_keys($filters), 0);
        foreach ($events as $event) {
            ++$counts['all'];
            foreach ($filters as $filter => $levels) {
                if ($filter !== 'all' && in_array($event->level, $levels, true)) {
                    ++$counts[$filter];
                }
            }
        }

        $selected = array_values(array_filter(
            $events,
            static fn ($event): bool => $level === 'all' || in_array($event->level, $filters[$level], true),
        ));
        // Filter before limiting: errors must never be pushed out by DEBUG noise.
        $shown = array_slice($selected, 0, 150);
        $timeline = array_map(
            fn ($event): array => [
                'at' => $event->occurredAt->format('Y-m-d H:i:s.u'),
                'level' => $event->level,
                'channel' => $event->channel,
                'message' => $this->redactor->redact($event->message, 4096),
            ],
            $shown,
        );

        return $this->view('execution.html.twig', [
            'executionId' => $id,
            'origin' => $events[0]->origin,
            'eventCount' => count($events),
            'selectedLevel' => $level,
            'levelCounts' => $counts,
            'selectedCount' => count($selected),
            'displayedCount' => count($shown),
            'timeline' => $timeline,
        ]);
    }

    #[Route('/investigations', name: 'investigations', methods: ['GET'])]
    public function investigations(): Response
    {
        return $this->view('investigations.html.twig', [
            'investigations' => $this->store->all(), 'storageWritable' => $this->store->isStorageWritable(), 'createToken' => $this->token('create'),
        ]);
    }

    #[Route('/investigations', name: 'create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->verify($request, 'create');
        try {
            $case = $this->store->createOrReuse(
                $this->field($request, 'title'),
                $this->field($request, 'problem'),
                $this->field($request, 'fingerprint'),
            );
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage(), $e);
        }

        return $this->redirect('novora_kaizen_detail', ['id' => $case['id']]);
    }

    #[Route('/investigations/{id}', name: 'detail', requirements: ['id' => '[a-f0-9]{32}'], methods: ['GET'])]
    public function detail(string $id): Response
    {
        return $this->renderDetail($id);
    }

    /**
     * Reuse the normal investigation page for recoverable form validation
     * errors, instead of turning a user's incomplete input into a log error.
     *
     * @param array{index:int,assessment:string,evidence:string,message:string}|null $assessmentError
     */
    private function renderDetail(string $id, ?array $assessmentError = null): Response
    {
        $case = $this->store->find($id);
        if ($case === null) {
            throw new NotFoundHttpException('Investigation not found.');
        }

        $verification = null;
        $appliedAt = $case['correction_applied_at'] ?? null;
        if (($case['fingerprint'] ?? '') !== '' && is_string($appliedAt) && $appliedAt !== '') {
            $correctionTime = new \DateTimeImmutable($appliedAt);
            $now = new \DateTimeImmutable();

            if ($correctionTime->getTimestamp() < $now->getTimestamp()) {
                $verification = $this->verificationAnalyzer->analyze(
                    $this->reader->read($this->logPath, $this->maxBytes),
                    $case['fingerprint'],
                    $correctionTime,
                    $now,
                );
            }
        }

        return new Response($this->twig->render('@NovoraKaizen/detail.html.twig', [
            'case' => $case,
            'token' => $this->token($id),
            'verification' => $verification,
            'correctionTimezone' => date_default_timezone_get(),
            'assessmentError' => $assessmentError,
        ]), $assessmentError === null ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route('/investigations/{id}/{action}', name: 'update', requirements: ['id' => '[a-f0-9]{32}', 'action' => 'gemba|ishikawa|assessment|why|pdca|status|correction'], methods: ['POST'])]
    public function update(string $id, string $action, Request $request): Response
    {
        $this->verify($request, $id);
        try {
            switch ($action) {
                case 'gemba':
                    $this->store->addGemba($id, $this->field($request, 'observation'), $this->field($request, 'evidence'));
                    break;
                case 'ishikawa':
                    $this->store->addCause($id, $this->field($request, 'category'), $this->field($request, 'hypothesis'), $this->field($request, 'evidence'));
                    break;
                case 'assessment':
                    $index = $this->number($request, 'index');
                    $assessment = $this->field($request, 'assessment');
                    $evidence = $this->field($request, 'evidence');

                    if ($assessment === 'confirmed' && $evidence === '') {
                        // User input is incomplete, not an exceptional application
                        // failure. Keep the store's own invariant as well.
                        return $this->renderDetail($id, [
                            'index' => $index,
                            'assessment' => $assessment,
                            'evidence' => $evidence,
                            'message' => 'Add supporting evidence before confirming this cause.',
                        ]);
                    }

                    $this->store->assessCause($id, $index, $assessment, $evidence);
                    break;
                case 'why':
                    $this->store->setWhy($id, $this->number($request, 'step'), $this->field($request, 'answer'));
                    break;
                case 'pdca':
                    $this->store->setPdca($id, $this->field($request, 'phase'), $this->field($request, 'description'));
                    break;
                case 'status':
                    $this->store->setStatus($id, $this->field($request, 'status'));
                    break;
                case 'correction':
                    $submitted = $this->field($request, 'applied_at');
                    $timezone = new \DateTimeZone(date_default_timezone_get());
                    if ($submitted === '') {
                        $appliedAt = new \DateTimeImmutable('now', $timezone);
                    } else {
                        $appliedAt = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $submitted, $timezone);
                        if ($appliedAt === false || $appliedAt->format('Y-m-d\TH:i') !== $submitted) {
                            throw new \InvalidArgumentException('Invalid correction time. Use YYYY-MM-DDTHH:MM.');
                        }
                    }

                    $this->store->recordCorrection($id, $appliedAt);
                    break;
            }
        } catch (\InvalidArgumentException|\OutOfBoundsException|\DomainException $e) {
            throw new BadRequestHttpException($e->getMessage(), $e);
        }

        return $this->redirect('novora_kaizen_detail', ['id' => $id]);
    }

    private function view(string $template, array $context): Response
    {
        return new Response($this->twig->render('@NovoraKaizen/'.$template, $context));
    }

    private function redirect(string $route, array $params): RedirectResponse
    {
        return new RedirectResponse($this->urlGenerator->generate($route, $params), Response::HTTP_SEE_OTHER);
    }

    private function token(string $id): string
    {
        return $this->csrf->getToken('kaizen_'.$id)->getValue();
    }

    private function verify(Request $request, string $id): void
    {
        $value = $this->field($request, '_token');
        if (!$this->csrf->isTokenValid(new CsrfToken('kaizen_'.$id, $value))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }

    private function field(Request $request, string $name): string
    {
        $value = $request->request->all()[$name] ?? '';
        if (!is_string($value) || strlen($value) > 4000) {
            throw new BadRequestHttpException('Invalid form field: '.$name);
        }

        return trim($value);
    }

    private function number(Request $request, string $name): int
    {
        $raw = $this->field($request, $name);
        if (!ctype_digit($raw) || strlen($raw) > 3) {
            throw new BadRequestHttpException('Invalid numeric field: '.$name);
        }

        return (int) $raw;
    }
}
