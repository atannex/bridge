<?php

declare(strict_types=1);

namespace Atannex\Bridge\Tests\Feature;

use Atannex\Bridge\Attributes\Access;
use Atannex\Bridge\Context;
use Atannex\Bridge\Contracts\Action;
use Atannex\Bridge\Contracts\HasInput;
use Atannex\Bridge\Contracts\HasOutput;
use Atannex\Bridge\Facades\Bridge;
use Atannex\Bridge\Generation\TypeScriptGenerator;
use Atannex\Bridge\Input;
use Atannex\Bridge\Schema\ObjectType;
use Atannex\Bridge\Schema\Schema;
use Atannex\Bridge\Schema\Type;
use Atannex\Bridge\Tests\TestCase;

final class GenerationTest extends TestCase
{
    public function test_the_contract_types_operations_inputs_outputs_and_events(): void
    {
        $contract = app(TypeScriptGenerator::class)->generate();

        $this->assertFalse($contract->failed(), implode("\n", $contract->errors));
        $ts = $contract->content;

        $this->assertStringContainsString("import type { BridgeFile, DownloadLink, OperationsManifest, Paginated } from '@atannex/bridge';", $ts);
        $this->assertStringContainsString("export type Status = 'draft' | 'published';", $ts);
        $this->assertStringContainsString("export interface PublicUser {\n  id: number;\n  name: string;\n  is_admin: boolean;\n  created_at: string | null;\n}", $ts);
        $this->assertStringContainsString("    'users.list': {\n      input: {\n        page?: number;\n        mode?: PageMode;\n        cursor?: string | null;\n      };\n      output: Paginated<PublicUser>;\n    };", $ts);
        $this->assertStringContainsString('        avatar: BridgeFile;', $ts);
        $this->assertStringContainsString('        download: DownloadLink;', $ts);
        $this->assertStringContainsString("    'test.void': {\n      input: Record<string, never>;\n      output: null;\n    };", $ts);
        $this->assertStringContainsString('     * @deprecated Use test.echo instead.', $ts);
        $this->assertStringContainsString("    'orders.updated': {\n      channel: 'private';\n      payload: {\n        id: number;\n        status: Status;\n      };\n    };", $ts);
        $this->assertStringContainsString("  'documents.create': { idempotency: 'idempotent', auth: 'user', deprecated: false },", $ts);
        $this->assertStringContainsString("  'test.old': { idempotency: 'unsafe', auth: 'guest', deprecated: 'Use test.echo instead.' },", $ts);
        $this->assertStringContainsString('} as const satisfies OperationsManifest;', $ts);
        $this->assertStringNotContainsString('any', $ts);
    }

    public function test_output_is_deterministic(): void
    {
        $generator = app(TypeScriptGenerator::class);

        $this->assertSame($generator->generate()->content, $generator->generate()->content);
    }

    public function test_ambiguous_schemas_are_diagnosed_not_typed_as_any(): void
    {
        Bridge::action('diag.mixed', DiagnosticAction::class, ['access' => Access::Guest]);
        DiagnosticAction::$output = Schema::mixed();

        $contract = app(TypeScriptGenerator::class)->generate();
        $this->assertFalse($contract->failed());
        $this->assertStringContainsString('[diag.mixed output] Schema::mixed() generates `unknown`', $contract->warnings[0]);

        DiagnosticAction::$output = Schema::object(['f' => Schema::file()]);
        $this->assertStringContainsString('[diag.mixed output] A file schema cannot be used in output', app(TypeScriptGenerator::class)->generate()->errors[0]);

        DiagnosticAction::$output = Schema::object(['other' => Schema::integer()], 'PublicUser');
        $this->assertStringContainsString('Two different schemas are both named [PublicUser]', app(TypeScriptGenerator::class)->generate()->errors[0]);
    }

    public function test_the_same_name_rendering_differently_as_input_gets_a_suffix(): void
    {
        Bridge::action('diag.mixed', DiagnosticAction::class, ['access' => Access::Guest]);
        DiagnosticAction::$output = Schema::object(['when' => Schema::dateTime()], 'Slot');
        DiagnosticAction::$input = Schema::object(['slot' => Schema::object(['when' => Schema::dateTime(), 'photo' => Schema::file()->optional()], 'Slot')]);

        $contract = app(TypeScriptGenerator::class)->generate();

        $this->assertFalse($contract->failed(), implode("\n", $contract->errors));
        $this->assertStringContainsString('export interface SlotInput {', $contract->content);
        DiagnosticAction::$input = null;
    }

    public function test_the_command_writes_and_checks_the_file(): void
    {
        $path = sys_get_temp_dir().'/bridge-gen-'.uniqid().'/bridge.ts';

        $this->artisan('bridge:generate', ['--check' => true, '--output' => $path])->assertFailed();
        $this->artisan('bridge:generate', ['--output' => $path])->assertSuccessful();
        $this->assertFileExists($path);
        $this->artisan('bridge:generate', ['--check' => true, '--output' => $path])->assertSuccessful();

        file_put_contents($path, '// edited');
        $this->artisan('bridge:generate', ['--check' => true, '--output' => $path])
            ->expectsOutputToContain('out of date')
            ->assertFailed();

        unlink($path);
        rmdir(dirname($path));
    }

    public function test_the_command_fails_on_errors(): void
    {
        Bridge::action('diag.mixed', DiagnosticAction::class, ['access' => Access::Guest]);
        DiagnosticAction::$output = Schema::object(['f' => Schema::file()]);

        $this->artisan('bridge:generate', ['--output' => sys_get_temp_dir().'/never-'.uniqid().'.ts'])->assertFailed();
    }
}

final class DiagnosticAction implements Action, HasInput, HasOutput
{
    public static Type $output;

    public static ?ObjectType $input = null;

    public function input(): ObjectType
    {
        return self::$input ?? Schema::object([]);
    }

    public function output(): Type
    {
        return self::$output;
    }

    public function handle(Input $input, Context $context): mixed
    {
        return null;
    }
}
