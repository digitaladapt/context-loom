<?php

declare(strict_types=1);

namespace App\Domain;

enum ChunkType: string
{
    case STDOUT = 'stdout';
    case STDERR = 'stderr';
    case PROGRESS = 'progress';
    case META = 'meta';
    case DONE = 'done';
}
