<?php

declare(strict_types=1);

namespace Wps\Services;

use Wps\Actions\ActionResult;
use Wps\Context\ExecutionContext;
use Wps\Exceptions\NonRecoverableException;

/**
 * Sends a variable to the CRM: PUT of the changed fields when the object has an id,
 * POST of the whole object otherwise (the returned id is written back into the variable).
 * Shared by the save_object action and modify with save_to_crm = true.
 */
final class CrmObjectSaver
{
    public function __construct(private readonly ExternalApiAdapterService $api)
    {
    }

    public function save(ExecutionContext $context, string $var, ?string $module = null, bool $full = false): ActionResult
    {
        if ($context->isList($var)) {
            throw new NonRecoverableException("Variable \"{$var}\" is a record list; use foreach to change its records");
        }
        if (($context->meta()[$var]['source'] ?? null) === ExecutionContext::SOURCE_LOOP_INFO) {
            throw new NonRecoverableException("Variable \"{$var}\" is read-only");
        }
        if ($context->isLocal($var)) {
            throw new NonRecoverableException("Variable \"{$var}\" is local (set_variable) and cannot be saved to the CRM");
        }
        if ($var === ExecutionContext::OBJECT && $context->eventInfo->eventType === 'delete') {
            return ActionResult::skipped('Record was deleted in the CRM — nothing to save', ['target' => $var]);
        }
        $object = $context->getVariable($var);
        if ($object === null) {
            return ActionResult::skipped("Variable \"{$var}\" is empty (object was not loaded) — nothing to save", ['target' => $var]);
        }
        $module = $module ?: $context->getModule($var);
        if ($module === null || $module === '') {
            throw new NonRecoverableException("Cannot save \"{$var}\": CRM module is unknown (set \"module\" in the save action)");
        }
        $context->setModule($var, $module);

        $id = isset($object['id']) ? (string)$object['id'] : '';
        if ($id !== '') {
            $fields = $full ? array_keys($object) : $context->dirtyFields($var);
            $attributes = array_intersect_key($object, array_flip(array_diff($fields, ['id'])));
            $request = ['target' => $var, 'method' => 'PUT', 'module' => $module, 'id' => $id, 'attributes' => (object)$attributes];
            if ($attributes === []) {
                return ActionResult::skipped('No changed fields to save', $request);
            }
            $response = $this->api->update($module, $id, $attributes);
            $context->clearDirty($var);
            return ActionResult::success($request, ['saved' => true, 'response' => $response]);
        }

        $attributes = $object;
        unset($attributes['id']);
        $request = ['target' => $var, 'method' => 'POST', 'module' => $module, 'attributes' => (object)$attributes];
        $response = $this->api->create($module, $attributes);
        if (isset($response['id']) && $response['id'] !== '') {
            $context->setClean($var, 'id', (string)$response['id']);
        }
        $context->clearDirty($var);
        return ActionResult::success($request, ['saved' => true, 'created_id' => $response['id'] ?? null, 'response' => $response]);
    }
}
