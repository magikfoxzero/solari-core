<?php

namespace NewSolari\Core\Module\Contracts;

interface HasFrontendRoutes
{
    public function getFrontendRoutes(): array;
    public function getNavigationItems(): array;
}
