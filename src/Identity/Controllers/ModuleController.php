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
        $allModules = $registry->getAllModulesWithManifest();

        $modules = [];
        foreach ($allModules as $mod) {
            if (!empty($mod['frontend'])) {
                $modules[] = [
                    'id' => $mod['id'],
                    'name' => $mod['name'],
                    'type' => $mod['type'],
                    'frontend' => $mod['frontend'],
                ];
            }
        }

        return response()->json([
            'value' => true,
            'result' => $modules,
        ]);
    }
}
