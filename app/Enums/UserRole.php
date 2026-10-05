<?php

namespace App\Enums;

enum UserRole: string
{
    case Resident = 'resident';
    case Admin = 'admin';
}
