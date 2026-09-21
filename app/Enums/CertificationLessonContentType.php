<?php

namespace App\Enums;

enum CertificationLessonContentType: string
{
    case Video = 'video';
    case Text = 'text';
    case Downloadable = 'downloadable';
    case ExternalReference = 'external_reference';
}
