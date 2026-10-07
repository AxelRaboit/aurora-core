<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Configuration\Setting\Command;

use Aurora\Module\Configuration\Setting\Command\DumpJsTranslationsCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * What a YAML sentence becomes once vue-i18n has to compile it.
 *
 * The catalogue is written for Symfony and read by vue-i18n, and the two do
 * not agree on what a brace or an `@` means. This command is where they are
 * reconciled, so this is where the reconciliation is checked - a message that
 * vue-i18n cannot compile does not fail loudly, it makes the component
 * rendering it render nothing.
 */
final class DumpJsTranslationsCommandTest extends TestCase
{
    private string $auroraDirectory;

    protected function setUp(): void
    {
        $this->auroraDirectory = sys_get_temp_dir().'/aurora-dump-js-'.bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($this->auroraDirectory.'/src/Core/translations');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->auroraDirectory);
    }

    public function testBracesThatNameNothingAreEscaped(): void
    {
        $messages = $this->dump([
            'snippet' => 'function bonjour() { … }',
            'json' => "{\n  \"--th-accent\": \"#6366f1\"\n}",
        ]);

        self::assertSame("function bonjour() {'{'} … {'}'}", $messages['snippet']);
        self::assertSame("{'{'}\n  \"--th-accent\": \"#6366f1\"\n{'}'}", $messages['json']);
    }

    public function testNamedPlaceholdersSurvive(): void
    {
        $messages = $this->dump([
            'vue' => 'Bonjour {name}, il reste {count} jours',
            'symfony' => 'Bonjour %name%, il reste %count% jours',
        ]);

        self::assertSame('Bonjour {name}, il reste {count} jours', $messages['vue']);
        self::assertSame('Bonjour {name}, il reste {count} jours', $messages['symfony']);
    }

    public function testAtSignIsStillEscaped(): void
    {
        $messages = $this->dump(['mail' => 'vous@example.com']);

        self::assertSame("vous{'@'}example.com", $messages['mail']);
    }

    /**
     * A message carrying both: the `@` escape writes braces of its own, and
     * escaping them again would put `{'{'}'@'{'}'}` on the screen.
     */
    public function testAnAtSignInsideBracesIsEscapedOnce(): void
    {
        $messages = $this->dump(['both' => 'écrivez { vous@example.com }']);

        self::assertSame("écrivez {'{'} vous{'@'}example.com {'}'}", $messages['both']);
    }

    /**
     * A literal is vue-i18n's own quoting, and the only way to show a `|`
     * that is not a plural separator: quoting it again put the quotes on
     * screen. A plural message, written with the bare bar, is left as it is.
     */
    public function testALiteralIsKeptAndABarePipeStaysAPluralSeparator(): void
    {
        $messages = $this->dump([
            'format' => "Londres {'|'} 51.5 {'|'} -0.12",
            'plural' => 'Aucune note | 1 note | {count} notes',
        ]);

        self::assertSame("Londres {'|'} 51.5 {'|'} -0.12", $messages['format']);
        self::assertSame('Aucune note | 1 note | {count} notes', $messages['plural']);
    }

    /**
     * @param array<string, string> $messages
     *
     * @return array<string, string>
     */
    private function dump(array $messages): array
    {
        file_put_contents(
            $this->auroraDirectory.'/src/Core/translations/messages.fr.yaml',
            json_encode($messages, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        $tester = new CommandTester(new DumpJsTranslationsCommand($this->auroraDirectory));
        self::assertSame(0, $tester->execute([]));

        $decoded = json_decode(
            (string) file_get_contents($this->auroraDirectory.'/src/Core/assets/locales/generated/fr.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($decoded);

        /* @var array<string, string> $decoded */
        return $decoded;
    }
}
