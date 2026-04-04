<?php

namespace NewSolari\Core\Identity\Controllers;

use NewSolari\Core\Http\BaseController;
use NewSolari\Core\Module\ModuleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ModuleController extends BaseController
{
    public function frontendManifest(Request $request): JsonResponse
    {
        // Validates authentication — user context not needed yet but may be used
        // for permission-based module filtering in the future
        $this->getAuthenticatedUser($request);

        $registry = app(ModuleRegistry::class);
        $modules = [];

        // In-process modules (loaded as Composer packages)
        foreach ($registry->getAllModules() as $module) {
            if ($registry->isEnabled($module->getId())) {
                $manifest = $module->getFrontendManifest();
                if ($manifest !== null) {
                    $modules[] = [
                        'id' => $module->getId(),
                        'name' => $module->getName(),
                        'type' => $module->getType(),
                        'frontend' => $manifest,
                    ];
                }
            }
        }

        // Remote/extracted services (not loaded as packages, but frontend needs them)
        $remoteServices = config('modules.remote_services', []);
        foreach ($remoteServices as $service) {
            if (!($service['enabled'] ?? true)) {
                continue;
            }
            // Skip if already registered as an in-process module
            $alreadyRegistered = collect($modules)->contains('id', $service['id']);
            if ($alreadyRegistered) {
                continue;
            }
            if (!empty($service['frontend'])) {
                $modules[] = [
                    'id' => $service['id'],
                    'name' => $service['name'],
                    'type' => $service['type'],
                    'frontend' => $service['frontend'],
                ];
            }
        }

        return response()->json([
            'value' => true,
            'result' => $modules,
        ]);
    }
}
