<?php

declare(strict_types=1);

/** Kontrollierte HTTP-Antworten ohne Netzwerkzugriff. */
final class ilEtsDownloadChecker extends ilModuleUpdateChecker
{
    public array $requests = [];
    public array $responses = [];

    protected function downloadResponse(string $url, $fh): array
    {
        $this->requests[] = $url;
        $response = array_shift($this->responses);
        if ($response === null) {
            throw new RuntimeException('Unerwarteter Netzwerkaufruf im Test.');
        }
        fwrite($fh, $response['body'] ?? '');
        return $response + ['ok' => true, 'error_no' => 0, 'error' => '', 'location' => null];
    }
}

final class ilModuleDownloadSecurityTest extends ilEtsTestCase
{
    private const REPO = 'https://github.com/user/Repo';
    private const SOURCE = self::REPO . '/raw/main/x.zip';
    private const RAW = 'https://raw.githubusercontent.com/user/Repo/main/x.zip';

    protected function setUp(): void
    {
        parent::setUp();
        if (!extension_loaded('curl')) {
            $this->markTestSkipped('cURL-Konstanten sind für die Download-Tests erforderlich.');
        }
    }

    public function testDownloaderLehntTraversalVorDemErstenAbrufAb(): void
    {
        $checker = new ilEtsDownloadChecker(self::REPO, 'main');
        try {
            $checker->downloadZip(self::REPO . '/../../evil/Repo/main/x.zip');
            $this->fail('Traversal wurde akzeptiert.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Download-URL', $e->getMessage());
            $this->assertSame([], $checker->requests);
        }
    }

    public function testGithubRedirectZumGleichenRepositoryFunktioniert(): void
    {
        $checker = new ilEtsDownloadChecker(self::REPO, 'main');
        $checker->responses = [
            ['http_code' => 302, 'location' => self::RAW, 'body' => 'Redirect-Seite'],
            ['http_code' => 200, 'body' => str_repeat('Z', 256)],
        ];
        $tmp = $checker->downloadZip(self::SOURCE);
        try {
            $this->assertSame([self::SOURCE, self::RAW], $checker->requests);
            $this->assertSame(str_repeat('Z', 256), file_get_contents($tmp));
        } finally {
            unlink($tmp);
        }
    }

    public function testUnsicheresRedirectZielWirdNichtAbgerufen(): void
    {
        foreach (['http://127.0.0.1/x.zip', 'https://evil.example/x.zip',
            'https://raw.githubusercontent.com/user/Other/main/x.zip',
            '/user/Repo/../../evil/Repo/main/x.zip', '//evil.example/x.zip'] as $target) {
            $checker = new ilEtsDownloadChecker(self::REPO, 'main');
            $checker->responses = [['http_code' => 302, 'location' => $target]];
            try {
                $checker->downloadZip(self::SOURCE);
                $this->fail('Redirect wurde akzeptiert: ' . $target);
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('Redirect', $e->getMessage());
                $this->assertSame([self::SOURCE], $checker->requests);
            }
        }
    }

    public function testRelativeRedirectsBleibenImRepository(): void
    {
        $this->assertSame(self::RAW, ilModuleDownloadUrlPolicy::resolveRedirect(
            self::RAW, '/user/Repo/main/x.zip', self::REPO
        ));
        $this->assertSame('https://raw.githubusercontent.com/user/Repo/main/y.zip',
            ilModuleDownloadUrlPolicy::resolveRedirect(self::RAW, 'y.zip', self::REPO));
        $this->assertNull(ilModuleDownloadUrlPolicy::resolveRedirect(self::RAW, '../x.zip', self::REPO));
    }

    public function testRedirectSchleifeIstBegrenzt(): void
    {
        $checker = new ilEtsDownloadChecker(self::REPO, 'main');
        $checker->responses = array_fill(0, 7, ['http_code' => 302, 'location' => self::SOURCE]);
        try {
            $checker->downloadZip(self::SOURCE);
            $this->fail('Redirect-Schleife wurde nicht beendet.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Redirect', $e->getMessage());
            $this->assertCount(6, $checker->requests);
        }
    }
}
