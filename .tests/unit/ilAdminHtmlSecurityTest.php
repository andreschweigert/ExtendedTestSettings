<?php

declare(strict_types=1);

require_once ETS_PLUGIN_ROOT . '/classes/class.ilExtendedTestSettingsPlugin.php';
require_once ETS_PLUGIN_ROOT . '/classes/class.ilExtendedTestSettingsConfigGUI.php';

/** Zeichnet die an ILIAS übergebenen Texte auf; Properties/Modals erwarten HTML. */
final class ilEtsHtmlNode
{
    public array $properties = [];

    public function __construct(public array $arguments)
    {
    }

    public function withProperties(array $properties): self
    {
        $this->properties = $properties;
        return $this;
    }

    public function __call(string $method, array $arguments): self
    {
        return $this;
    }

    public function getShowSignal(): string
    {
        return 'fixture-signal';
    }
}

final class ilEtsHtmlFactory
{
    public array $modals = [];

    public function interruptive(...$arguments): ilEtsHtmlNode
    {
        $node = new ilEtsHtmlNode($arguments);
        $this->modals[] = $node;
        return $node;
    }

    public function __call(string $method, array $arguments): object
    {
        return in_array($method, ['modal', 'button', 'dropdown', 'item'], true)
            ? $this : new ilEtsHtmlNode($arguments);
    }
}

final class ilAdminHtmlSecurityTest extends ilEtsTestCase
{
    private function gui(): ilExtendedTestSettingsConfigGUI
    {
        $ref = new ReflectionClass(ilExtendedTestSettingsConfigGUI::class);
        $gui = $ref->newInstanceWithoutConstructor();
        $gui->setPluginObject(new class extends ilExtendedTestSettingsPlugin {
            public function txt(string $key): string
            {
                return str_ends_with($key, '_confirm') ? $key . ' %s' : $key;
            }
        });
        $ref->getProperty('ctrl')->setValue($gui, new ilCtrl());
        return $gui;
    }

    public function testManipulierteMetadatenSindInPropertiesUndModalsNurText(): void
    {
        $payload = '<img src=x onerror="window.xss=1">';
        $factory = new ilEtsHtmlFactory();
        [$item] = (new ReflectionMethod(ilExtendedTestSettingsConfigGUI::class, 'buildModuleItem'))->invoke(
            $this->gui(), ['id' => 'testmodul', 'label' => $payload, 'version' => $payload,
                'ilias_min' => $payload, 'ilias_max' => $payload, 'maturity' => $payload], $factory
        );

        $this->assertStringNotContainsString('<img', implode('', $item->properties));
        $this->assertStringContainsString('&lt;img', implode('', $item->properties));
        $this->assertCount(2, $factory->modals);
        foreach ($factory->modals as $modal) {
            $this->assertStringNotContainsString('<img', $modal->arguments[1]);
            $this->assertStringContainsString('&lt;img', $modal->arguments[1]);
        }
    }

    public function testUpdateProtokollEscapedAlleEintraegeEinmal(): void
    {
        $payload = '</li><script>window.xss=1</script><li>';
        $html = (new ReflectionMethod(ilExtendedTestSettingsConfigGUI::class, 'renderUpdateLog'))->invoke(
            $this->gui(), ['Modul ' . $payload, 'Fehler: <img src=x onerror=alert(1)>', 'A & B']
        );
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('A &amp; B', $html);
        $this->assertStringNotContainsString('&amp;lt;', $html);
        $this->assertSame(3, substr_count($html, '<li>'));
    }
}
