<?php

namespace App\Enums;

enum CameraViewStatus: string
{
    case Ok = 'ok';
    case Moved = 'moved';
    case Covered = 'covered';
}
