<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Operator = 'operator';
    case Guard = 'guard';
    case SiteAdmin = 'site_admin';
    /** The client's administration (consorcio or country): sees every site of its client. */
    case ClientAdmin = 'client_admin';
    case Resident = 'resident';
}
