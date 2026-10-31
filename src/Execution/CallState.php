<?php

declare(strict_types=1);

namespace Atannex\Bridge\Execution;

use Atannex\Bridge\Registry\OperationDefinition;
use Atannex\Bridge\Tenancy\Tenant;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * What one call has learned so far, so the error path and the final event
 * can report it. Lives for exactly one call.
 *
 * @internal
 */
final class CallState
{
    public ?OperationDefinition $definition = null;

    public ?Authenticatable $user = null;

    public ?Tenant $tenant = null;

    public ?Claim $claim = null;

    public bool $replayed = false;

    private ?string $previousLocale = null;

    public function setLocale(?string $locale): void
    {
        if ($locale === null || $locale === app()->getLocale()) {
            return;
        }

        $this->previousLocale = app()->getLocale();
        app()->setLocale($locale);
    }

    /** Put the app locale back so it cannot bleed into the next request on a long-lived worker. */
    public function restoreLocale(): void
    {
        if ($this->previousLocale !== null) {
            app()->setLocale($this->previousLocale);
            $this->previousLocale = null;
        }
    }
}
