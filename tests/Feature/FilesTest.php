<?php

declare(strict_types=1);

namespace Atannex\Bridge\Tests\Feature;

use Atannex\Bridge\Files\Download;
use Atannex\Bridge\Files\SignedFiles;
use Atannex\Bridge\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

final class FilesTest extends TestCase
{
    /**
     * A multipart call the way a real client sends it: the `input` field plus
     * files[...] parts, with a multipart Content-Type.
     *
     * @param  array<string, mixed>  $parts
     */
    private function upload(array $parts): TestResponse
    {
        $files = $parts['files'] ?? [];
        unset($parts['files']);

        return $this->call('POST', '/bridge/v1/call/avatar.upload', $parts, [], $files === [] ? [] : ['files' => $files], [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'multipart/form-data; boundary=x',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_multipart_upload_and_a_signed_download_bound_to_the_uploader(): void
    {
        $alice = $this->user();
        Sanctum::actingAs($alice);

        $response = $this->upload([
            'input' => json_encode(['caption' => 'Me']),
            'files' => ['avatar' => UploadedFile::fake()->image('me.png', 40, 40)],
        ]);
        $response->assertOk();

        $response->assertJsonPath('data.mime', 'image/png')
            ->assertJsonPath('data.caption', 'Me')
            ->assertJsonPath('data.download.name', 'avatar.png');

        $url = $response->json('data.download.url');
        $this->assertStringNotContainsString('avatars', $url, 'The storage path must not appear in the link.');
        Storage::disk('local')->assertExists("avatars/{$alice->id}.png");

        $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertDownload('avatar.png');

        // Someone else holding the link gets nothing.
        Sanctum::actingAs($this->user());
        $this->getJson($url)->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_non_images_and_oversized_files_fail_validation(): void
    {
        Sanctum::actingAs($this->user());

        $this->upload([
            'input' => '{}',
            'files' => ['avatar' => UploadedFile::fake()->create('evil.png', 10, 'text/html')],
        ])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['avatar']]]);

        $this->upload([
            'input' => '{}',
            'files' => ['avatar' => UploadedFile::fake()->image('big.png')->size(500)],
        ])->assertStatus(422);
    }

    public function test_files_at_undeclared_paths_are_dropped_and_bad_paths_rejected(): void
    {
        Sanctum::actingAs($this->user());

        // Not declared: dropped, so the required avatar is missing.
        $this->upload([
            'input' => '{}',
            'files' => ['other' => UploadedFile::fake()->image('a.png')],
        ])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['avatar']]]);

        $this->upload([
            'input' => '{}',
            'files' => ['../../etc' => UploadedFile::fake()->image('a.png')],
        ])->assertStatus(400)->assertJsonPath('error.code', 'MALFORMED_REQUEST');

        $this->upload([
            'input' => '{}',
            'stray' => 'field',
        ])->assertStatus(400);
    }

    public function test_multipart_size_counts_what_arrived_even_without_content_length(): void
    {
        config(['bridge.limits.multipart_bytes' => 20 * 1024]);
        Sanctum::actingAs($this->user());

        // No CONTENT_LENGTH header, as with a chunked upload.
        $this->call('POST', '/bridge/v1/call/avatar.upload', ['input' => '{}'], [], ['files' => ['avatar' => UploadedFile::fake()->image('big.png')->size(60)]], [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'multipart/form-data; boundary=x',
        ])->assertStatus(413)->assertJsonPath('error.code', 'PAYLOAD_TOO_LARGE');
    }

    public function test_tampered_expired_and_unsigned_links_are_refused(): void
    {
        $user = $this->user();
        Storage::disk('local')->put('reports/a.pdf', 'PDF');
        $link = app(SignedFiles::class)->link(Download::fromDisk('local', 'reports/a.pdf'), $user, 'UTC');

        Sanctum::actingAs($user);
        $this->get($link['url'])->assertOk();

        $this->getJson($link['url'].'x')->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
        $this->getJson(strtok($link['url'], '?'))->assertForbidden();

        $this->travel(10)->minutes();
        $this->getJson($link['url'])->assertForbidden();
    }

    public function test_shareable_links_work_without_the_owner(): void
    {
        Storage::disk('local')->put('public/a.txt', 'hello');
        $link = app(SignedFiles::class)->link(Download::fromDisk('local', 'public/a.txt')->shareable(), $this->user(), 'UTC');

        $this->get($link['url'])->assertOk();
    }

    public function test_downloads_refuse_traversal_and_unlisted_disks(): void
    {
        foreach (['../.env', 'a/../../b', '/etc/passwd', "a\0b"] as $path) {
            try {
                Download::fromDisk('local', $path);
                $this->fail("Path [{$path}] should be refused.");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        app(SignedFiles::class)->link(Download::fromDisk('s3', 'x'), null, 'UTC');
    }
}
