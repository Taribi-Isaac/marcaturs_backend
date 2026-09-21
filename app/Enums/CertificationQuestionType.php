<?php

namespace App\Enums;

enum CertificationQuestionType: string
{
    /**
     * FR-028 technical/product implementation decision for MVP:
     * one reliable closed-ended type with server-owned correctness.
     * Additional types remain a Product decision and are not exposed yet.
     */
    case SingleChoice = 'single_choice';
}
