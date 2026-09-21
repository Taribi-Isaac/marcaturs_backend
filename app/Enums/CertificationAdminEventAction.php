<?php

namespace App\Enums;

enum CertificationAdminEventAction: string
{
    case ProgrammeCreated = 'programme_created';
    case ProgrammeUpdated = 'programme_updated';
    case ProgrammeArchived = 'programme_archived';
    case ProgrammeUnarchived = 'programme_unarchived';
    case VersionCreated = 'version_created';
    case VersionUpdated = 'version_updated';
    case VersionPublished = 'version_published';
    case VersionUnpublished = 'version_unpublished';
    case ModuleCreated = 'module_created';
    case ModuleUpdated = 'module_updated';
    case ModuleDeleted = 'module_deleted';
    case ModulesReordered = 'modules_reordered';
    case LessonCreated = 'lesson_created';
    case LessonUpdated = 'lesson_updated';
    case LessonDeleted = 'lesson_deleted';
    case LessonsReordered = 'lessons_reordered';
    case ResourceCreated = 'resource_created';
    case ResourceUpdated = 'resource_updated';
    case ResourceDeleted = 'resource_deleted';
    case ResourcesReordered = 'resources_reordered';
    case PurchaseInitialized = 'purchase_initialized';
    case EnrollmentActivated = 'enrollment_activated';
    case LessonCompleted = 'lesson_completed';
    case AssessmentCreated = 'assessment_created';
    case AssessmentUpdated = 'assessment_updated';
    case QuestionCreated = 'question_created';
    case QuestionUpdated = 'question_updated';
    case QuestionDeleted = 'question_deleted';
    case QuestionsReordered = 'questions_reordered';
    case AssessmentAttemptStarted = 'assessment_attempt_started';
    case AssessmentAttemptSubmitted = 'assessment_attempt_submitted';
    case AssessmentAttemptPassed = 'assessment_attempt_passed';
    case AssessmentAttemptFailed = 'assessment_attempt_failed';
    case AwardCreated = 'award_created';
    case CertificateCreated = 'certificate_created';
    case CertificateArtifactGenerated = 'certificate_artifact_generated';
    case CertificateArtifactGenerationFailed = 'certificate_artifact_generation_failed';
    case CertificateArtifactDownloaded = 'certificate_artifact_downloaded';
}
