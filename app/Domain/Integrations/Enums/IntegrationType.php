<?php

namespace App\Domain\Integrations\Enums;

enum IntegrationType: string
{
    case Google = 'google';
    case Microsoft = 'microsoft';
}
