<?php
declare(strict_types=1);

namespace Novora\KaizenBundle\Tests;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class TemplateSyntaxTest extends TestCase
{
    public function testAllViewsCompile(): void
    {
        $loader = new FilesystemLoader();
        $loader->addPath(dirname(__DIR__).'/templates', 'NovoraKaizen');
        $twig = new Environment($loader, ['cache' => false, 'strict_variables' => true]);
        $twig->addFunction(new TwigFunction('path', static fn (string $route): string => '/'.$route));
        foreach (['base.html.twig', 'dashboard.html.twig', 'investigations.html.twig', 'detail.html.twig', 'execution.html.twig'] as $name) {
            self::assertNotNull($twig->load('@NovoraKaizen/'.$name));
        }
    }
}
