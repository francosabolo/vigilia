<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Operator = 'operator';
    case Guard = 'guard';
    case SiteAdmin = 'site_admin';
    case Resident = 'resident';
}
