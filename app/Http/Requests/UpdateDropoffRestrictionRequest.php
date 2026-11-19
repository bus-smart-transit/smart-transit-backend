<?php

namespace App\Http\Requests;

/**
 * Phase 4 (2026-11-19): the input rules of `DropoffRulesController::updateRestriction`: the store rules with the route,
 * name and stops optional.
 */
class UpdateDropoffRestrictionRequest extends StoreDropoffRestrictionRequest
{
    protected bool $creating = false;
}
